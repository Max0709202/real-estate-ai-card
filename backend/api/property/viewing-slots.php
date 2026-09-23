<?php
/**
 * 内見日程調整: 買主が希望日時を送信する（§4／§7 日時変更）。
 * POST(JSON) { property_id, session_id, visitor_id, slots: ["2026-10-05 10:00", ...], reschedule? }
 *
 * ・1時間の候補を3枠以上選んだときだけ送信できる。
 * ・送信でエージェントへ M01（初回）／M07（日時変更）を送る。
 * ・日時変更のときは、この送信の時点で旧予約を解除し、旧日時のリマインドを取り消す。
 *   入力画面を開いただけでは解除しない。
 * ・選択から送信までに担当者の予定が変わった場合は、送信時に再確認して選び直しを案内する。
 */
require_once __DIR__ . '/viewing-common.php';
require_once __DIR__ . '/../../includes/viewing-reminder-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = viewingApiInput();
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$rawSlots = is_array($input['slots'] ?? null) ? $input['slots'] : [];
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    $auth = viewingApiAuthorize($db, $propertyId, $input);
    if ($auth['role'] !== 'buyer') sendErrorResponse('この操作にはご本人確認が必要です', 403);
    $property = $auth['property'];
    $sessionId = (string)$property['session_id'];

    $norm = viewingNormalizeSlots($rawSlots);
    $slots = $norm['slots'];
    if (count($slots) < VIEWING_MIN_SLOTS) {
        sendErrorResponse('ご希望の日時を' . VIEWING_MIN_SLOTS . 'つ以上お選びください。内見時間は1枠1時間です。', 400);
    }

    // 選択から送信までに担当者の予定が変わっていないか、送信時にもう一度確認する。
    $cardId = (int)$property['business_card_id'];
    if (viewingCalendarIsConnected($db, $cardId)) {
        $from = viewingNow()->setTime(0, 0);
        $blocked = viewingCalendarBlockedFor($db, $cardId, $from, $from->modify('+' . VIEWING_DAYS_AHEAD . ' days'));
        $conflict = array_values(array_filter($slots, fn($s) => in_array($s['start'], $blocked, true)));
        if ($conflict) {
            $texts = array_map(fn($s) => viewingFormatRange($s['start'], $s['end']), $conflict);
            sendErrorResponse('ご選択後に担当者の予定が変わりました。恐れ入りますが、' . implode('／', $texts) . ' 以外の日時をお選び直しください。', 409, ['blocked' => $blocked]);
        }
    }

    $case = viewingEnsureCase($db, $propertyId, $sessionId, $cardId);
    if (!viewingIsOpen($case)) {
        // キャンセル済み・内見不可の案件へは追加できない。現在の状態をそのまま返す。
        sendErrorResponse('この内見はすでに' . (viewingStatusDefs()[$case['status']]['label'] ?? '終了') . 'です。', 409);
    }

    // 日時変更か（確定済みの案件に対する再送信）。
    $isReschedule = ($case['confirmed_start_at'] !== null && $case['confirmed_start_at'] !== '');
    $round = (int)$case['round'];
    $prevStart = $case['confirmed_start_at'];
    $prevEnd = $case['confirmed_end_at'];

    $db->beginTransaction();
    try {
        if ($isReschedule) {
            // 旧確定日時と新しい候補は履歴として分けて保存する（round を進める）。
            $round++;
            $stmt = $db->prepare("UPDATE property_viewings SET
                    round = ?, status = 'agent_review', is_rescheduling = 1,
                    prev_start_at = confirmed_start_at, prev_end_at = confirmed_end_at,
                    confirmed_slot_id = NULL, confirmed_start_at = NULL, confirmed_end_at = NULL, confirmed_at = NULL,
                    key_method = NULL, key_json = NULL, buyer_notified_at = NULL, unavailable_reason = NULL
                  WHERE id = ?");
            $stmt->execute([$round, (int)$case['id']]);
        } else {
            // 「候補日時では内見不可」の回答後に選び直された場合、その表示を解除する。
            $db->prepare("UPDATE property_viewings SET status = 'agent_review', unavailable_reason = NULL WHERE id = ?")->execute([(int)$case['id']]);
        }
        viewingReplaceSlots($db, (int)$case['id'], $round, $slots);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // 旧予約の解除にあわせて、旧日時のリマインドを即時取消する（調整中は送信しない）。
    if ($isReschedule) viewingReminderCancelAll($db, (int)$case['id'], '日時変更の候補送信により取消');

    $db->prepare("UPDATE properties SET status = 'viewing_request' WHERE id = ?")->execute([$propertyId]);
    viewingLogEvent($db, (int)$case['id'], $isReschedule ? 'buyer_reschedule' : 'buyer_request', [
        'actor' => 'buyer', 'detail' => count($slots) . '枠を送信',
    ]);

    $fresh = viewingLoad($db, (int)$case['id']);
    sendSuccessResponse(
        viewingApiCasePayload($db, $fresh, $property, 'buyer'),
        '内見依頼を送信しました。担当者からの連絡をお待ちください。'
    );

    viewingApiAfterResponse(function () use ($db, $fresh, $isReschedule, $prevStart, $prevEnd, $sessionId) {
        viewingMailSend($db, $fresh, $isReschedule ? 'M07' : 'M01', [
            'prev' => viewingFormatRange($prevStart, $prevEnd),
        ]);
        // 既存の担当連絡通知（顧客操作のお知らせ）にも積む。
        if (function_exists('notifyEnqueue')) notifyEnqueue($db, $sessionId, 'property');
    });
} catch (Exception $e) {
    error_log('viewing-slots error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

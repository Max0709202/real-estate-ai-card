<?php
/**
 * 内見日程調整: キャンセル（§8）。
 * POST(JSON)
 *   買主:   { property_id, session_id, visitor_id, reason, reason_text? }
 *           理由は必須。「その他」は自由記入も必須。保存後 M09（買主）・M10（エージェント）を送り、
 *           当該案件の未送信リマインドをすべて取り消す。
 *   担当:   { property_id, action:'notify_seller' }
 *           売主（仲介）会社へ M11 を送る。買主のキャンセルだけでは自動送信しない。
 *
 * キャンセル理由は売主仲介会社のメールへ自動転記しない。
 * 売主側への通知前であってもリマインドは送信しない。
 */
require_once __DIR__ . '/viewing-common.php';
require_once __DIR__ . '/../../includes/viewing-reminder-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = viewingApiInput();
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$action = trim((string)($input['action'] ?? 'cancel'));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    $auth = viewingApiAuthorize($db, $propertyId, $input);
    $property = $auth['property'];
    $case = viewingFindByPropertySession($db, $propertyId, (string)$property['session_id']);
    if (!$case) sendErrorResponse('内見依頼がありません', 404);

    /* ---- 担当者が売主（仲介）会社へキャンセルを通知する ---- */
    if ($action === 'notify_seller') {
        if ($auth['role'] !== 'agent') sendErrorResponse('担当者としてログインしてください', 403);
        if ((string)$case['status'] !== 'cancelled') sendErrorResponse('キャンセルされた内見ではありません', 409);
        if (!empty($case['seller_cancel_notified_at'])) sendErrorResponse('すでに売主（仲介）会社へ通知済みです', 409);

        $target = viewingFormatRange($case['prev_start_at'] ?: $case['confirmed_start_at'], $case['prev_end_at'] ?: $case['confirmed_end_at']);
        // キャンセル理由は売主仲介会社のメールへ自動転記しない。
        $res = viewingMailSend($db, $case, 'M11', ['target' => $target]);
        if ($res['sent'] === 0) {
            sendErrorResponse('売主（仲介）会社へのメールを送信できませんでした。宛先をご確認のうえ、再送してください。', 502);
        }
        $db->prepare("UPDATE property_viewings SET seller_cancel_notified_at = ? WHERE id = ?")
           ->execute([viewingNow()->format('Y-m-d H:i:s'), (int)$case['id']]);
        $fresh = viewingLoad($db, (int)$case['id']);
        viewingLogEvent($db, (int)$case['id'], 'seller_cancel_notified', ['actor' => 'agent']);
        sendSuccessResponse(viewingApiCasePayload($db, $fresh, $property, 'agent'), '売主（仲介）会社へキャンセルを通知しました。');
        exit;
    }

    /* ---- 買主のキャンセル ---- */
    if ($auth['role'] !== 'buyer') sendErrorResponse('この操作にはご本人確認が必要です', 403);
    if (!viewingIsOpen($case)) sendErrorResponse('この内見はすでに' . (viewingStatusDefs()[$case['status']]['label'] ?? '終了') . 'です。', 409);

    $reasons = viewingCancelReasonDefs();
    $reason = trim((string)($input['reason'] ?? ''));
    $reasonText = mb_substr(trim((string)($input['reason_text'] ?? '')), 0, 500);
    if (!isset($reasons[$reason])) sendErrorResponse('キャンセル理由をお選びください。', 400);
    if ($reason === 'other' && $reasonText === '') sendErrorResponse('「その他」を選ばれた場合は、理由をご記入ください。', 400);

    $target = viewingFormatRange($case['confirmed_start_at'], $case['confirmed_end_at']);
    $db->prepare("UPDATE property_viewings SET
                    status = 'cancelled', cancel_reason = ?, cancel_reason_text = ?, cancelled_at = ?,
                    prev_start_at = COALESCE(confirmed_start_at, prev_start_at),
                    prev_end_at = COALESCE(confirmed_end_at, prev_end_at),
                    is_rescheduling = 0
                  WHERE id = ?")
       ->execute([$reason, $reasonText ?: null, viewingNow()->format('Y-m-d H:i:s'), (int)$case['id']]);

    // 当該案件の未送信リマインドをすべて取り消す（売主側への通知前でも送信しない）。
    viewingReminderCancelAll($db, (int)$case['id'], '買主のキャンセルにより取消');

    $fresh = viewingLoad($db, (int)$case['id']);
    viewingLogEvent($db, (int)$case['id'], 'buyer_cancel', [
        'actor' => 'buyer', 'detail' => $reasons[$reason] . ($reasonText !== '' ? '：' . $reasonText : ''),
    ]);

    sendSuccessResponse(
        viewingApiCasePayload($db, $fresh, $property, 'buyer'),
        '内見のキャンセルを受け付けました。売主側への連絡は担当者が行います。'
    );

    $sessionId = (string)$property['session_id'];
    viewingApiAfterResponse(function () use ($db, $fresh, $target, $sessionId) {
        viewingMailSend($db, $fresh, 'M09', ['target' => $target]);
        viewingMailSend($db, $fresh, 'M10', ['target' => $target]);
        if (function_exists('notifyEnqueue')) notifyEnqueue($db, $sessionId, 'property');
    });
} catch (Exception $e) {
    error_log('viewing-cancel error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

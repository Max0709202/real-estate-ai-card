<?php
/**
 * 内見日程調整: 買主への確定連絡（§7）。担当ログイン必須。
 * POST(JSON) { property_id, meeting_note }
 *
 * ・待ち合わせ案内は必須。空欄では送信できない。
 * ・鍵情報を買主向け案内へ自動転記しない（買主向けは自由入力のみを使う）。
 * ・M06 の送信成功後にのみ、前日18時・当日8時のリマインドを予約する。
 */
require_once __DIR__ . '/viewing-common.php';
require_once __DIR__ . '/../../includes/viewing-reminder-helper.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = viewingApiInput();
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$meeting = trim((string)($input['meeting_note'] ?? ''));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);
if ($meeting === '') sendErrorResponse('当日の待ち合わせ場所と時間をご入力ください。', 400);

try {
    $db = (new Database())->getConnection();
    $auth = viewingApiAuthorize($db, $propertyId, $input);
    if ($auth['role'] !== 'agent') sendErrorResponse('担当者としてログインしてください', 403);
    $property = $auth['property'];

    $case = viewingFindByPropertySession($db, $propertyId, (string)$property['session_id']);
    if (!$case) sendErrorResponse('内見依頼がありません', 404);
    if (!viewingIsOpen($case)) sendErrorResponse('この内見はすでに' . (viewingStatusDefs()[$case['status']]['label'] ?? '終了') . 'です。', 409);
    if (empty($case['confirmed_start_at'])) sendErrorResponse('売主（仲介）会社の承諾がまだのため、確定案内は送信できません。', 409);

    $db->prepare("UPDATE property_viewings SET meeting_note = ? WHERE id = ?")
       ->execute([mb_substr($meeting, 0, 2000), (int)$case['id']]);
    $case = viewingLoad($db, (int)$case['id']);

    // 変更確定かどうかで件名・本文を切り替える（M06「内見日時変更確定」）。
    $changed = (int)$case['is_rescheduling'] === 1;
    $res = viewingMailSend($db, $case, 'M06', ['changed' => $changed]);

    if ($res['sent'] === 0) {
        // 送信できなかった場合は「買主へ確定連絡済み」にせず、リマインドも予約しない。
        sendErrorResponse('買主へのメールを送信できませんでした。宛先をご確認のうえ、再送してください。', 502,
            viewingApiCasePayload($db, $case, $property, 'agent'));
    }

    $db->prepare("UPDATE property_viewings SET status = 'buyer_notified', is_rescheduling = 0, buyer_notified_at = ? WHERE id = ?")
       ->execute([viewingNow()->format('Y-m-d H:i:s'), (int)$case['id']]);
    $case = viewingLoad($db, (int)$case['id']);

    // M06 が届いた宛先にだけリマインドを予約する。担当エージェントにも同じ回を送る。
    $recipients = array_map(fn($e) => ['email' => $e, 'role' => 'buyer'], $res['recipients']);
    $agentEmail = viewingAgentContact($db, (int)$case['business_card_id'])['email'];
    if ($agentEmail !== '' && filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
        $recipients[] = ['email' => $agentEmail, 'role' => 'agent'];
    }
    viewingReminderSchedule($db, $case, $recipients);

    viewingLogEvent($db, (int)$case['id'], 'buyer_notified', ['actor' => 'agent']);

    $payload = viewingApiCasePayload($db, $case, $property, 'agent');
    $payload['reminders'] = viewingReminderList($db, (int)$case['id']);
    sendSuccessResponse($payload, '買主へ内見確定の案内を送信しました。');
} catch (Exception $e) {
    error_log('viewing-confirm error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

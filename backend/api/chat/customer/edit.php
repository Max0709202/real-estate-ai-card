<?php
/**
 * 顧客が自分の担当連絡メッセージ（role='user', channel='contact'）を編集する。
 * POST { session_id, visitor_id, message_id, message }
 *  -> { message_id, message, edited: 1 }
 * 取り消し済み（deleted_at IS NOT NULL）のメッセージは編集不可。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
$message = trim($input['message'] ?? '');

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}
if ($messageId <= 0) {
    sendErrorResponse('message_id is required', 400);
}
if ($message === '') {
    sendErrorResponse('メッセージを入力してください', 400);
}
if (mb_strlen($message) > 2000) {
    $message = mb_substr($message, 0, 2000);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    agentMsgVerifyVisitorSession($db, $sessionId, $visitorId);

    // 自分（顧客）の担当連絡メッセージで、まだ取り消されていないものだけ編集できる。
    $stmt = $db->prepare("
        UPDATE chat_messages
        SET message = ?, edited_at = CURRENT_TIMESTAMP
        WHERE id = ? AND session_id = ? AND role = 'user' AND channel = 'contact' AND deleted_at IS NULL
    ");
    $stmt->execute([$message, $messageId, $sessionId]);

    if ($stmt->rowCount() === 0) {
        // 既に取り消し済み・他人の発言・存在しない等。安全側でエラーを返す。
        sendErrorResponse('このメッセージは編集できません', 409);
    }

    sendSuccessResponse([
        'message_id' => $messageId,
        'message' => $message,
        'edited' => 1,
    ], 'メッセージを編集しました');
} catch (Exception $e) {
    error_log('contact edit error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

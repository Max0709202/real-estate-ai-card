<?php
/** 担当者が自分の担当連絡メッセージを取り消す（ソフト削除）。 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

startSessionIfNotStarted();
$userId = requireAuth();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$messageId = (int)($input['message_id'] ?? 0);

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) sendErrorResponse('session_id is required', 400);
if ($messageId <= 0) sendErrorResponse('message_id is required', 400);

try {
    $db = (new Database())->getConnection();
    agentMsgVerifyOwnedSession($db, $sessionId, (int)$userId);
    $stmt = $db->prepare("UPDATE chat_messages SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND session_id = ? AND role = 'agent' AND channel = 'contact' AND sender_user_id = ? AND deleted_at IS NULL");
    $stmt->execute([$messageId, $sessionId, (int)$userId]);
    if ($stmt->rowCount() === 0) sendErrorResponse('このメッセージは取り消せません', 409);
    sendSuccessResponse(['message_id' => $messageId, 'deleted' => 1], 'メッセージを取り消しました');
} catch (Exception $e) {
    error_log('agent message delete error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

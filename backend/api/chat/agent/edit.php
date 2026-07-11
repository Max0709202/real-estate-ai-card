<?php
/** 担当者が自分の担当連絡メッセージを編集する。 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

startSessionIfNotStarted();
$userId = requireAuth();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$messageId = (int)($input['message_id'] ?? 0);
$message = trim($input['message'] ?? '');

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) sendErrorResponse('session_id is required', 400);
if ($messageId <= 0) sendErrorResponse('message_id is required', 400);
if ($message === '') sendErrorResponse('メッセージを入力してください', 400);
if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);

try {
    $db = (new Database())->getConnection();
    agentMsgVerifyOwnedSession($db, $sessionId, (int)$userId);
    $stmt = $db->prepare("UPDATE chat_messages SET message = ?, edited_at = CURRENT_TIMESTAMP WHERE id = ? AND session_id = ? AND role = 'agent' AND channel = 'contact' AND sender_user_id = ? AND deleted_at IS NULL");
    $stmt->execute([$message, $messageId, $sessionId, (int)$userId]);
    if ($stmt->rowCount() === 0) sendErrorResponse('このメッセージは編集できません', 409);
    sendSuccessResponse(['message_id' => $messageId, 'message' => $message, 'edited' => 1], 'メッセージを編集しました');
} catch (Exception $e) {
    error_log('agent message edit error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

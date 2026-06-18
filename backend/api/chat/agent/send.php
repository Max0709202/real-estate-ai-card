<?php
/**
 * 担当 → 顧客 へメッセージを送信する。
 * POST { session_id, message?, attachment_ids?[] } -> { message_id, created_at }
 * 認証必須。当該セッションが担当の名刺に属することを検証する。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$message = trim($input['message'] ?? '');
$attachmentIds = isset($input['attachment_ids']) && is_array($input['attachment_ids']) ? $input['attachment_ids'] : [];

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($message === '' && empty($attachmentIds)) {
    sendErrorResponse('メッセージまたは添付ファイルが必要です', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $session = agentMsgVerifyOwnedSession($db, $sessionId, (int)$userId);

    // 担当発言を保存（本文が空でも添付のみで送れるよう、空なら添付の説明を入れる）
    $storedMessage = $message !== '' ? $message : '[ファイルを送信しました]';
    $messageId = agentMsgInsertMessage($db, $sessionId, 'agent', $storedMessage, (int)$userId);

    // 仮アップロード済みの添付を確定メッセージに紐付け
    if (!empty($attachmentIds)) {
        agentMsgAttachMessageId($db, $attachmentIds, $messageId, $sessionId, 'agent');
    }

    // 顧客発言を既読化（担当が読んだ）
    agentMsgMarkRead($db, $sessionId, 'user');

    // 担当が会話に参加 → 以後はAIの自動応答を止める
    agentMsgSetHandoff($db, $sessionId, 'agent');

    // セッションの最終更新
    $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);

    // AI記憶（CRM要約）へ反映
    if (function_exists('chatCrmSyncFromChatSession')) {
        try { chatCrmSyncFromChatSession($db, $sessionId, (int)$session['business_card_id']); } catch (Throwable $e) { /* 記憶同期失敗は致命ではない */ }
    }

    $stmt = $db->prepare("SELECT id, role, message, created_at FROM chat_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $attach = agentMsgLoadAttachments($db, [$messageId]);

    sendSuccessResponse([
        'message_id' => $messageId,
        'created_at' => $row['created_at'] ?? null,
        'attachments' => $attach[$messageId] ?? [],
    ], 'OK');
} catch (Exception $e) {
    error_log('agent send error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

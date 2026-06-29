<?php
/**
 * 担当が顧客発言を既読化する。
 * POST { session_id, mode? } mode='bot' で AI応答へ戻す（ハンドオフ解除）。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../../includes/notification-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$handoff = trim($input['mode'] ?? '');

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    agentMsgVerifyOwnedSession($db, $sessionId, (int)$userId);

    $marked = agentMsgMarkRead($db, $sessionId, 'user');
    // 担当連絡画面を開いた → メール通知の未読解除（以降の新規操作で再通知）。
    notifyMarkRead($db, $sessionId, 'contact');
    if ($handoff === 'bot' || $handoff === 'agent') {
        agentMsgSetHandoff($db, $sessionId, $handoff);
    }

    sendSuccessResponse(['marked_read' => $marked], 'OK');
} catch (Exception $e) {
    error_log('agent read error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

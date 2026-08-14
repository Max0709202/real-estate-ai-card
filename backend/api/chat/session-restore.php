<?php
/**
 * Restore one chat session from the trash. My Page use.
 * POST { "id": "session_id" }
 *
 * session-delete.php でゴミ箱に入れた履歴（chat_sessions.deleted_at）を元に戻す。
 * 実体は削除していないため、メッセージ・ヒアリング内容・添付もそのまま復元される。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-session-trash-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$sessionId = trim($input['id'] ?? $input['session_id'] ?? '');

if ($sessionId === '') {
    sendErrorResponse('id (session_id) is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    chatSessionTrashEnsureColumns($db);

    $stmt = $db->prepare("SELECT cs.id, cs.business_card_id, cs.deleted_at
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ? AND bc.user_id = ?");
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    if (empty($session['deleted_at'])) {
        sendErrorResponse('この履歴は削除されていません', 409);
    }

    $stmt = $db->prepare('UPDATE chat_sessions SET deleted_at = NULL, deleted_by_user_id = NULL WHERE id = ? AND business_card_id = ? AND deleted_at IS NOT NULL');
    $stmt->execute([$sessionId, (int)$session['business_card_id']]);

    if ($stmt->rowCount() === 0) {
        sendErrorResponse('この履歴は復元できません', 409);
    }

    sendSuccessResponse(['session_id' => $sessionId], 'チャット履歴を復元しました');
} catch (Exception $e) {
    error_log('Chat session restore error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

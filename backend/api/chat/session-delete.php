<?php
/**
 * Move one chat session to the trash (soft delete) for the current user's business card. My Page use.
 * POST { "id": "session_id" }
 *
 * 実体は消さず chat_sessions.deleted_at を立てて担当側の一覧から隠すだけにする。
 * 誤って削除しても保持期間内（既定30日）は session-restore.php で復元できる。
 * お客様側の画面・過去のやり取りには影響しない。
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

    $businessCardId = (int)$session['business_card_id'];

    // すでにゴミ箱にある場合は削除日時を上書きしない（復元期限を延ばさないため）。
    if (empty($session['deleted_at'])) {
        $stmt = $db->prepare('UPDATE chat_sessions SET deleted_at = CURRENT_TIMESTAMP, deleted_by_user_id = ? WHERE id = ? AND business_card_id = ? AND deleted_at IS NULL');
        $stmt->execute([$userId, $sessionId, $businessCardId]);

        $stmt = $db->prepare('SELECT deleted_at FROM chat_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $deletedAt = $stmt->fetchColumn() ?: null;
    } else {
        $deletedAt = $session['deleted_at'];
    }

    $retentionDays = chatSessionTrashRetentionDays();

    sendSuccessResponse([
        'session_id' => $sessionId,
        'deleted_at' => $deletedAt,
        'purge_at' => chatSessionTrashPurgeAt($deletedAt),
        'days_left' => chatSessionTrashDaysLeft($deletedAt),
        'retention_days' => $retentionDays,
    ], 'チャット履歴をゴミ箱に移動しました（' . $retentionDays . '日以内なら復元できます）');
} catch (Exception $e) {
    error_log('Chat session delete error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

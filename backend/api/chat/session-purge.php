<?php
/**
 * Permanently delete one chat session that is already in the trash. My Page use.
 * POST { "id": "session_id" }
 *
 * ゴミ箱に入っている履歴だけを実体ごと削除する（復元不可）。
 * ゴミ箱に入っていないセッションは削除できない（誤操作で即消えないようにするため）。
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
        sendErrorResponse('完全に削除する前に、いったんゴミ箱へ移動してください', 409);
    }

    // 関連テーブルの用意はトランザクションの外で（DDLは暗黙コミットになるため）。
    chatSessionTrashEnsureRelatedTables($db);

    $db->beginTransaction();
    chatSessionTrashHardDelete($db, $sessionId, (int)$session['business_card_id']);
    $db->commit();

    sendSuccessResponse(['session_id' => $sessionId], 'チャット履歴を完全に削除しました');
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Chat session purge error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

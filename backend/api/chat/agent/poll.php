<?php
/**
 * 担当側ポーリング。
 * GET ?since_id=&session_id=
 * - session_id 指定時: そのセッションの since_id より新しい全メッセージ（添付込み）を返す。
 * - 常に: 当該担当の全セッション横断の未読サマリ（要返信一覧）と合計未読数を返す。
 * 重い処理（OpenAI等）は呼ばない。SELECTのみ。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
if ($sessionId !== '' && !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    $sessionId = '';
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // 全セッション横断の未読サマリ（顧客発言の未読件数 > 0 のセッション）
    $stmt = $db->prepare("
        SELECT cs.id AS session_id, cs.business_card_id,
               COUNT(cm.id) AS unread_count,
               MAX(cm.created_at) AS last_unread_at
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        JOIN chat_messages cm ON cm.session_id = cs.id AND cm.role = 'user' AND cm.read_at IS NULL
        WHERE bc.user_id = ?
        GROUP BY cs.id, cs.business_card_id
        ORDER BY last_unread_at DESC
    ");
    $stmt->execute([$userId]);
    $unreadSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalUnread = 0;
    foreach ($unreadSessions as $s) { $totalUnread += (int)$s['unread_count']; }

    $messages = [];
    if ($sessionId !== '') {
        // 所有検証
        agentMsgVerifyOwnedSession($db, $sessionId, (int)$userId);
        $stmt = $db->prepare("
            SELECT id, role, sender_user_id, message, read_at, created_at
            FROM chat_messages
            WHERE session_id = ? AND id > ?
            ORDER BY id ASC LIMIT 200
        ");
        $stmt->execute([$sessionId, $sinceId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($messages) {
            $attach = agentMsgLoadAttachments($db, array_column($messages, 'id'));
            foreach ($messages as &$m) { $m['attachments'] = $attach[(int)$m['id']] ?? []; }
            unset($m);
        }
    }

    sendSuccessResponse([
        'total_unread' => $totalUnread,
        'unread_sessions' => $unreadSessions,
        'messages' => $messages,
    ], 'OK');
} catch (Exception $e) {
    error_log('agent poll error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

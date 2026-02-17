<?php
/**
 * List chat sessions for the current user's business card(s). My Page use.
 * GET ?business_card_id= optional filter
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

try {
    $database = new Database();
    $db = $database->getConnection();

    $cardId = isset($_GET['business_card_id']) ? (int) $_GET['business_card_id'] : null;

    $sql = "
        SELECT cs.id, cs.business_card_id, cs.last_seen_at, cs.created_at,
               bc.name as card_holder_name,
               (SELECT COUNT(*) FROM chat_messages cm WHERE cm.session_id = cs.id) as message_count,
               (SELECT cl.id FROM chat_leads cl WHERE cl.session_id = cs.id LIMIT 1) as has_lead
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE bc.user_id = ?
    ";
    $params = [$userId];
    if ($cardId > 0) {
        $sql .= " AND cs.business_card_id = ?";
        $params[] = $cardId;
    }
    $sql .= " ORDER BY cs.last_seen_at DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendSuccessResponse(['sessions' => $sessions], 'OK');
} catch (Exception $e) {
    error_log('Chat sessions list error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

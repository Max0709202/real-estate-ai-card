<?php
/**
 * Fetch paginated history for paid user bulk notifications.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireFullAdminAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $database = new Database();
    $db = $database->getConnection();

    $campaignExpr = "
        COALESCE(
            CAST(related_id AS CHAR),
            CONCAT('legacy:', subject, ':', DATE_FORMAT(COALESCE(sent_at, completed_at, created_at), '%Y-%m-%d %H:%i'))
        )
    ";

    $countSql = "
        SELECT COUNT(*) FROM (
            SELECT {$campaignExpr} AS campaign_key
            FROM email_logs
            WHERE email_type = 'paid_user_notification'
            GROUP BY campaign_key
        ) grouped_notifications
    ";
    $total = (int)$db->query($countSql)->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $sql = "
        SELECT
            {$campaignExpr} AS campaign_key,
            subject,
            MIN(COALESCE(sent_at, completed_at, created_at)) AS sent_at,
            COUNT(*) AS recipient_count,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
            SUM(CASE WHEN status <> 'sent' THEN 1 ELSE 0 END) AS failed_count
        FROM email_logs
        WHERE email_type = 'paid_user_notification'
        GROUP BY campaign_key, subject
        ORDER BY sent_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    sendSuccessResponse([
        'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ], '送信済み案内を取得しました');
} catch (Exception $e) {
    error_log('Paid user notification history error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

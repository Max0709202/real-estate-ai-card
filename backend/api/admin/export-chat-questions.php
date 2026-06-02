<?php
/**
 * Export customer chat questions/messages to CSV for RAG review.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

try {
    requireAdmin();

    $database = new Database();
    $db = $database->getConnection();

    $where = ["cm.role = 'user'"];
    $params = [];

    if (!empty($_GET['business_card_id'])) {
        $where[] = 'bc.id = ?';
        $params[] = (int)$_GET['business_card_id'];
    }
    if (!empty($_GET['date_from'])) {
        $where[] = 'cm.created_at >= ?';
        $params[] = $_GET['date_from'] . ' 00:00:00';
    }
    if (!empty($_GET['date_to'])) {
        $where[] = 'cm.created_at <= ?';
        $params[] = $_GET['date_to'] . ' 23:59:59';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="chat_questions_' . date('YmdHis') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        '日時',
        '会社名',
        '担当者名',
        '名刺URL',
        'セッションID',
        '顧客名',
        '相談種別',
        '顧客の発言',
        '直前のAI回答',
    ]);

    $sql = "
        SELECT
            cm.id,
            cm.session_id,
            cm.message,
            cm.created_at,
            bc.id AS business_card_id,
            bc.company_name,
            bc.name AS card_holder_name,
            bc.url_slug,
            cc.customer_name,
            cl.structured_data,
            (
                SELECT bm.message
                FROM chat_messages bm
                WHERE bm.session_id = cm.session_id
                  AND bm.role = 'bot'
                  AND bm.id < cm.id
                ORDER BY bm.id DESC
                LIMIT 1
            ) AS previous_bot_message
        FROM chat_messages cm
        JOIN chat_sessions cs ON cs.id = cm.session_id
        JOIN business_cards bc ON bc.id = cs.business_card_id
        LEFT JOIN chat_lead_contacts cc ON cc.session_id = cs.id
        LEFT JOIN chat_leads cl ON cl.session_id = cs.id
        $whereClause
        ORDER BY cm.created_at DESC, cm.id DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $leadData = [];
        if (!empty($row['structured_data'])) {
            $decoded = json_decode($row['structured_data'], true);
            if (is_array($decoded)) $leadData = $decoded;
        }
        $cardUrl = rtrim(QR_CODE_BASE_URL, '/') . '/card.php?slug=' . ($row['url_slug'] ?? '');

        fputcsv($output, [
            $row['created_at'],
            $row['company_name'],
            $row['card_holder_name'],
            $cardUrl,
            $row['session_id'],
            $row['customer_name'],
            $leadData['customer_type'] ?? '',
            $row['message'],
            $row['previous_bot_message'],
        ]);
    }

    fclose($output);
    exit();
} catch (Exception $e) {
    error_log('Chat Questions CSV Export Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'チャット質問CSV出力に失敗しました']);
    exit();
}

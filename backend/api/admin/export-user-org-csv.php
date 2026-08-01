<?php
/**
 * 組織階層（権限・上長）をCSVで出力する。
 * ここで出したCSVをそのまま編集して import-user-org-csv.php に取り込める形にしておく。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

try {
    requireAdmin();

    $database = new Database();
    $db = $database->getConnection();
    orgEnsureUserColumns($db);

    $sql = "
        SELECT u.id,
               u.email,
               u.org_role,
               parent.email AS parent_email,
               bc.name AS member_name,
               bc.company_name,
               bc.branch_department
        FROM users u
        LEFT JOIN users parent ON parent.id = u.parent_user_id
        LEFT JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) first_card ON first_card.user_id = u.id
        LEFT JOIN business_cards bc ON bc.id = first_card.id
        ORDER BY u.id ASC
    ";
    $stmt = $db->query($sql);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="user_org_' . date('YmdHis') . '.csv"');

    $output = fopen('php://output', 'w');
    // BOM付きUTF-8（Excel用）
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // 取り込み時はこの列順・列名をそのまま使う。
    fputcsv($output, ['メールアドレス', '氏名', '会社名', '部署', '権限', '上長メールアドレス']);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['email'],
            $row['member_name'] ?? '',
            $row['company_name'] ?? '',
            $row['branch_department'] ?? '',
            orgRoleLabel($row['org_role'] ?? 'staff'),
            $row['parent_email'] ?? '',
        ]);
    }

    fclose($output);
    exit();
} catch (Exception $e) {
    error_log('User Org CSV Export Error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'CSV出力に失敗しました']);
    exit();
}

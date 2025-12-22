<?php
/**
 * Export Users to CSV
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

try {
    requireAdmin();
    
    $database = new Database();
    $db = $database->getConnection();

    // 検索条件の構築（dashboard.phpと同じロジック）
    $where = [];
    $params = [];

    // 入金状況の検索条件
    if (!empty($_GET['payment_status']) && in_array($_GET['payment_status'], ['CR', 'BANK_PENDING', 'BANK_PAID', 'UNUSED'])) {
        $where[] = "bc.payment_status = ?";
        $params[] = $_GET['payment_status'];
    }

    // 公開状況の検索条件（空文字列の場合は条件を追加しない）
    if (isset($_GET['is_open']) && $_GET['is_open'] !== '') {
        $where[] = "bc.is_published = ?";
        $params[] = (int)$_GET['is_open'];
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // CSV出力
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users_' . date('YmdHis') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM付きUTF-8（Excel用）
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // ヘッダー
    fputcsv($output, [
        '分類', '入金状況', 'OPEN', '社名', '名前', '携帯電話番号', 'メールアドレス',
        '表示回数（過去1か月）', '表示回数（累積）', '名刺URL', '登録日', '最終ログイン日'
    ]);

    // データ取得
    $sql = "
        SELECT 
            CASE 
                WHEN u.user_type = 'new' THEN '新規'
                WHEN u.user_type = 'existing' THEN '既存'
                WHEN u.user_type = 'free' THEN '無料'
                ELSE '新規'
            END as user_type,
            CASE 
                WHEN bc.payment_status = 'CR' THEN 'CR'
                WHEN bc.payment_status = 'BANK_PENDING' THEN '振込予定'
                WHEN bc.payment_status = 'BANK_PAID' THEN '振込済'
                WHEN bc.payment_status = 'UNUSED' THEN '未利用'
                ELSE '未利用'
            END as payment_status_label,
            CASE WHEN bc.is_published = 1 THEN '✓' ELSE '' END as is_open,
            bc.company_name,
            bc.name,
            bc.mobile_phone,
            u.email,
            COALESCE((
                SELECT COUNT(*) 
                FROM access_logs al 
                WHERE al.business_card_id = bc.id 
                AND al.accessed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
            ), 0) as monthly_views,
            COALESCE((
                SELECT COUNT(*) 
                FROM access_logs al 
                WHERE al.business_card_id = bc.id
            ), 0) as total_views,
            CONCAT('" . QR_CODE_BASE_URL . "', bc.url_slug) as card_url,
            bc.created_at as registered_at,
            u.last_login_at
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        $whereClause
        GROUP BY bc.id, u.id, u.email, u.user_type, bc.company_name, bc.name, bc.mobile_phone, bc.url_slug, 
                 bc.is_published, bc.payment_status, bc.created_at, u.last_login_at
        ORDER BY bc.created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['user_type'],
            $row['payment_status_label'],
            $row['is_open'],
            $row['company_name'],
            $row['name'],
            $row['mobile_phone'],
            $row['email'],
            $row['monthly_views'],
            $row['total_views'],
            $row['card_url'],
            $row['registered_at'],
            $row['last_login_at']
        ]);
    }

    fclose($output);
    exit();

} catch (Exception $e) {
    error_log("CSV Export Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'CSV出力に失敗しました']);
    exit();
}


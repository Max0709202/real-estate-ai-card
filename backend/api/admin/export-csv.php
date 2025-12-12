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
    if (!empty($_GET['payment_status'])) {
        if ($_GET['payment_status'] === 'completed') {
            // 入金済み: completedステータスの支払いが存在する
            $where[] = "EXISTS (SELECT 1 FROM payments p2 WHERE p2.business_card_id = bc.id AND p2.payment_status = 'completed')";
        } elseif ($_GET['payment_status'] === 'pending') {
            // 未入金: completedステータスの支払いが存在しない
            $where[] = "NOT EXISTS (SELECT 1 FROM payments p2 WHERE p2.business_card_id = bc.id AND p2.payment_status = 'completed')";
        }
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
        'ユーザータイプ', '入金', 'OPEN', '社名', '名前', '携帯電話番号', 'メールアドレス',
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
            CASE WHEN EXISTS (SELECT 1 FROM payments p2 WHERE p2.business_card_id = bc.id AND p2.payment_status = 'completed') THEN '✓' ELSE '' END as payment_confirmed,
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
                 bc.is_published, bc.created_at, u.last_login_at
        ORDER BY bc.created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['user_type'],
            $row['payment_confirmed'],
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


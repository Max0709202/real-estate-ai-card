<?php
/**
 * Admin Users Management API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireAdmin(); // 管理者認証
    
    $method = $_SERVER['REQUEST_METHOD'];
    $database = new Database();
    $db = $database->getConnection();

    if ($method === 'GET') {
        // ユーザー一覧取得（検索・ソート・ページネーション対応）
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;

        // 検索条件
        $where = [];
        $params = [];

        if (!empty($_GET['payment_status'])) {
            $where[] = "p.payment_status = ?";
            $params[] = $_GET['payment_status'];
        }

        if (!empty($_GET['is_open'])) {
            $where[] = "bc.is_published = ?";
            $params[] = (int)$_GET['is_open'];
        }

        if (!empty($_GET['date_from'])) {
            $where[] = "bc.created_at >= ?";
            $params[] = $_GET['date_from'];
        }

        if (!empty($_GET['date_to'])) {
            $where[] = "bc.created_at <= ?";
            $params[] = $_GET['date_to'];
        }

        if (!empty($_GET['login_from'])) {
            $where[] = "u.last_login_at >= ?";
            $params[] = $_GET['login_from'];
        }

        if (!empty($_GET['login_to'])) {
            $where[] = "u.last_login_at <= ?";
            $params[] = $_GET['login_to'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // ソート
        $sortField = $_GET['sort'] ?? 'bc.created_at';
        $sortOrder = strtoupper($_GET['order'] ?? 'DESC');
        
        $allowedSortFields = [
            'bc.created_at', 'u.email', 'bc.company_name', 'bc.name',
            'p.payment_status', 'bc.is_published', 'u.last_login_at'
        ];
        
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'bc.created_at';
        }

        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }

        // アクセス回数取得（過去1か月）
        $sql = "
            SELECT 
                bc.id,
                bc.user_id,
                u.email,
                bc.company_name,
                bc.name,
                bc.mobile_phone,
                bc.url_slug,
                bc.is_published as is_open,
                p.payment_status,
                p.paid_at,
                bc.created_at as registered_at,
                u.last_login_at,
                COUNT(al.id) as total_views,
                SUM(CASE WHEN al.accessed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) THEN 1 ELSE 0 END) as monthly_views
            FROM business_cards bc
            JOIN users u ON bc.user_id = u.id
            LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
            LEFT JOIN access_logs al ON bc.id = al.business_card_id
            $whereClause
            GROUP BY bc.id
            ORDER BY $sortField $sortOrder
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        // 総件数取得
        $countSql = "
            SELECT COUNT(DISTINCT bc.id) as total
            FROM business_cards bc
            JOIN users u ON bc.user_id = u.id
            LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
            $whereClause
        ";

        $countParams = array_slice($params, 0, -2); // limit, offsetを除外
        $stmt = $db->prepare($countSql);
        $stmt->execute($countParams);
        $total = $stmt->fetch()['total'];

        sendSuccessResponse([
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);

    } elseif ($method === 'POST') {
        // 入金確認とQRコード発行
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['business_card_id'])) {
            sendErrorResponse('ビジネスカードIDが必要です', 400);
        }

        $bcId = (int)$input['business_card_id'];
        $action = $input['action'] ?? 'confirm_payment';

        if ($action === 'confirm_payment') {
            // 入金確認
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'completed', paid_at = NOW()
                WHERE business_card_id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$bcId]);

            // QRコード発行処理
            $stmt = $db->prepare("
                SELECT bc.id, bc.url_slug, bc.user_id
                FROM business_cards bc
                WHERE bc.id = ?
            ");
            $stmt->execute([$bcId]);
            $bc = $stmt->fetch();

            if ($bc) {
                // QRコード生成APIを呼び出すか、直接処理
                $qrUrl = QR_CODE_BASE_URL . $bc['url_slug'];
                
                $stmt = $db->prepare("
                    UPDATE business_cards 
                    SET qr_code_issued = 1, qr_code_issued_at = NOW(), is_published = 1
                    WHERE id = ?
                ");
                $stmt->execute([$bcId]);

                // 通知メール送信
                $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$bc['user_id']]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // sendEmail(...)
                }
            }

            sendSuccessResponse(['business_card_id' => $bcId], '入金を確認し、QRコードを発行しました');
        }

    } elseif ($method === 'DELETE') {
        // ユーザー削除（複数対応）
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['user_ids']) || !is_array($input['user_ids'])) {
            sendErrorResponse('ユーザーIDが必要です', 400);
        }

        $userIds = array_map('intval', $input['user_ids']);
        $userIds = array_filter($userIds, function($id) {
            return $id > 0;
        });

        if (empty($userIds)) {
            sendErrorResponse('有効なユーザーIDが必要です', 400);
        }

        // トランザクション開始
        $db->beginTransaction();

        try {
            $deletedCount = 0;
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));

            // 削除前にユーザー情報を取得（ログ用）
            $stmt = $db->prepare("
                SELECT u.id, u.email, bc.company_name, bc.name
                FROM users u
                LEFT JOIN business_cards bc ON u.id = bc.user_id
                WHERE u.id IN ($placeholders)
            ");
            $stmt->execute($userIds);
            $usersToDelete = $stmt->fetchAll();

            // ユーザーを削除（CASCADE DELETEにより関連データも自動削除）
            $stmt = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
            $stmt->execute($userIds);
            $deletedCount = $stmt->rowCount();

            $db->commit();

            // ログに記録
            error_log("Admin deleted users: " . json_encode($usersToDelete) . " by admin_id: " . $_SESSION['admin_id']);

            sendSuccessResponse([
                'deleted_count' => $deletedCount,
                'user_ids' => $userIds
            ], "{$deletedCount}件のユーザーを削除しました");

        } catch (Exception $e) {
            $db->rollBack();
            error_log("User deletion error: " . $e->getMessage());
            sendErrorResponse('ユーザー削除中にエラーが発生しました', 500);
        }

    }

} catch (Exception $e) {
    error_log("Admin Users API Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


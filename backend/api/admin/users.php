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
            // 入金確認（支払いレコードが存在する場合）
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'completed', paid_at = NOW()
                WHERE business_card_id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$bcId]);

            // 支払いレコードが存在しない場合は作成
            $stmt = $db->prepare("
                SELECT id FROM payments WHERE business_card_id = ?
            ");
            $stmt->execute([$bcId]);
            $existingPayment = $stmt->fetch();
            
            if (!$existingPayment) {
                // 支払いレコードを作成
                $stmt = $db->prepare("
                    SELECT user_id FROM business_cards WHERE id = ?
                ");
                $stmt->execute([$bcId]);
                $bcData = $stmt->fetch();
                
                if ($bcData) {
                    $stmt = $db->prepare("
                        INSERT INTO payments (user_id, business_card_id, payment_type, amount, tax_amount, total_amount, payment_method, payment_status, paid_at)
                        VALUES (?, ?, 'new_user', 0, 0, 0, 'bank_transfer', 'completed', NOW())
                    ");
                    $stmt->execute([$bcData['user_id'], $bcId]);
                }
            }

            // QRコード発行処理（qr-helper.phpの関数を使用）
            require_once __DIR__ . '/../../includes/qr-helper.php';
            
            $result = generateBusinessCardQRCode($bcId, $db);
            
            if (!$result['success']) {
                sendErrorResponse($result['message'] ?? 'QRコードの生成に失敗しました', 500);
            }

            // 変更履歴を記録
            $adminId = $_SESSION['admin_id'];
            $adminEmail = $_SESSION['admin_email'] ?? '';
            
            // ビジネスカード情報を取得
            $stmt = $db->prepare("SELECT user_id, url_slug FROM business_cards WHERE id = ?");
            $stmt->execute([$bcId]);
            $bcInfo = $stmt->fetch();
            
            if ($bcInfo) {
                $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$bcInfo['user_id']]);
                $userInfo = $stmt->fetch();
                $userEmail = $userInfo['email'] ?? 'Unknown';
                
                // 入金確認の記録
                logAdminChange($db, $adminId, $adminEmail, 'payment_confirmed', 'business_card', $bcId, 
                    "入金確認: ユーザー {$userEmail} (URL: {$bcInfo['url_slug']})");
                
                // QRコード発行の記録
                logAdminChange($db, $adminId, $adminEmail, 'qr_code_issued', 'business_card', $bcId, 
                    "QRコード発行: ユーザー {$userEmail} (URL: {$bcInfo['url_slug']})");
            }

            sendSuccessResponse([
                'business_card_id' => $bcId,
                'qr_code_url' => $result['qr_code_url'] ?? null
            ], '入金を確認し、QRコードを発行しました。ユーザーにメールを送信しました。');
        } elseif ($action === 'cancel_payment') {
            // 入金取消（名刺使用停止）
            $adminId = $_SESSION['admin_id'];
            $adminEmail = $_SESSION['admin_email'] ?? '';
            
            // ビジネスカード情報を取得
            $stmt = $db->prepare("SELECT user_id, url_slug FROM business_cards WHERE id = ?");
            $stmt->execute([$bcId]);
            $bcInfo = $stmt->fetch();
            
            if (!$bcInfo) {
                sendErrorResponse('ビジネスカードが見つかりません', 404);
            }
            
            // 支払いステータスをpendingに変更
            $stmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'pending', paid_at = NULL
                WHERE business_card_id = ? AND payment_status = 'completed'
            ");
            $stmt->execute([$bcId]);
            
            // 名刺を非公開にする
            $stmt = $db->prepare("UPDATE business_cards SET is_published = 0 WHERE id = ?");
            $stmt->execute([$bcId]);
            
            // 変更履歴を記録
            $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$bcInfo['user_id']]);
            $userInfo = $stmt->fetch();
            $userEmail = $userInfo['email'] ?? 'Unknown';
            
            logAdminChange($db, $adminId, $adminEmail, 'payment_cancelled', 'business_card', $bcId, 
                "入金取消（名刺使用停止）: ユーザー {$userEmail} (URL: {$bcInfo['url_slug']})");
            
            sendSuccessResponse([
                'business_card_id' => $bcId
            ], '名刺の使用を停止しました。入金ステータスが「未入金」に変更され、名刺が非公開になりました。');
        } elseif ($action === 'update_published') {
            // 公開状態の変更
            if (!isset($input['is_published'])) {
                sendErrorResponse('公開状態が必要です', 400);
            }
            
            $isPublished = (int)$input['is_published'];
            
            // ビジネスカード情報を取得
            $stmt = $db->prepare("SELECT user_id, url_slug, is_published FROM business_cards WHERE id = ?");
            $stmt->execute([$bcId]);
            $bcInfo = $stmt->fetch();
            
            if (!$bcInfo) {
                sendErrorResponse('ビジネスカードが見つかりません', 404);
            }
            
            // 公開状態を更新
            $stmt = $db->prepare("UPDATE business_cards SET is_published = ? WHERE id = ?");
            $stmt->execute([$isPublished, $bcId]);
            
            // 変更履歴を記録
            $adminId = $_SESSION['admin_id'];
            $adminEmail = $_SESSION['admin_email'] ?? '';
            
            $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$bcInfo['user_id']]);
            $userInfo = $stmt->fetch();
            $userEmail = $userInfo['email'] ?? 'Unknown';
            
            $statusText = $isPublished ? '公開' : '非公開';
            logAdminChange($db, $adminId, $adminEmail, 'published_changed', 'business_card', $bcId, 
                "公開状態変更: {$statusText} - ユーザー {$userEmail} (URL: {$bcInfo['url_slug']})");
            
            sendSuccessResponse([
                'business_card_id' => $bcId,
                'is_published' => $isPublished
            ], "公開状態を{$statusText}に変更しました");
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

            // 変更履歴を記録
            $adminId = $_SESSION['admin_id'];
            $adminEmail = $_SESSION['admin_email'] ?? '';
            
            foreach ($usersToDelete as $user) {
                $description = "ユーザー削除: {$user['email']}";
                if (!empty($user['company_name'])) {
                    $description .= " ({$user['company_name']})";
                }
                if (!empty($user['name'])) {
                    $description .= " - {$user['name']}";
                }
                logAdminChange($db, $adminId, $adminEmail, 'user_deleted', 'user', $user['id'], $description);
            }

            // ログに記録
            error_log("Admin deleted users: " . json_encode($usersToDelete) . " by admin_id: " . $adminId);

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


<?php
/**
 * Overdue Payments Management Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

// 管理者認証
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// 管理者ロール確認
$stmt = $db->prepare("SELECT role FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$currentAdminRole = $stmt->fetchColumn();
$isAdmin = ($currentAdminRole === 'admin' || $_SESSION['admin_id'] == 1);

// 未払いユーザーを取得
$stmt = $db->prepare("
    SELECT 
        bc.id as business_card_id,
        bc.user_id,
        u.email,
        u.user_type,
        bc.company_name,
        bc.name,
        bc.mobile_phone,
        bc.url_slug,
        bc.is_published,
        MAX(p.paid_at) as last_payment_date,
        s.next_billing_date,
        s.status as subscription_status,
        CASE 
            WHEN s.next_billing_date IS NOT NULL AND s.next_billing_date < CURDATE() THEN 'subscription_overdue'
            WHEN MAX(p.paid_at) IS NULL THEN 'no_payment'
            WHEN MAX(p.paid_at) < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'monthly_overdue'
            ELSE 'other'
        END as overdue_reason,
        DATEDIFF(NOW(), COALESCE(s.next_billing_date, MAX(p.paid_at), bc.created_at)) as days_overdue
    FROM business_cards bc
    INNER JOIN users u ON bc.user_id = u.id
    LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
    LEFT JOIN subscriptions s ON bc.id = s.business_card_id AND s.status = 'active'
    WHERE u.user_type IN ('new', 'existing')
    AND (
        (s.next_billing_date IS NOT NULL AND s.next_billing_date < CURDATE() 
         AND NOT EXISTS (
             SELECT 1 FROM payments p2 
             WHERE p2.business_card_id = bc.id 
             AND p2.payment_status = 'completed'
             AND p2.paid_at >= s.next_billing_date
         ))
        OR
        (s.status IS NULL OR s.status != 'active')
        AND EXISTS (
            SELECT 1 FROM payments p3 
            WHERE p3.business_card_id = bc.id 
            AND p3.payment_type IN ('new_user', 'existing_user')
        )
    )
    GROUP BY bc.id, bc.user_id, u.email, u.user_type, bc.company_name, bc.name, 
             bc.mobile_phone, bc.url_slug, bc.is_published, s.next_billing_date, s.status, bc.created_at
    HAVING (
        (last_payment_date IS NULL OR last_payment_date < DATE_SUB(NOW(), INTERVAL 30 DAY))
        OR (s.next_billing_date IS NOT NULL AND s.next_billing_date < CURDATE())
    )
    ORDER BY days_overdue DESC, bc.created_at DESC
");
$stmt->execute();
$overdueUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報
$totalOverdue = count($overdueUsers);
$subscriptionOverdue = count(array_filter($overdueUsers, fn($u) => $u['overdue_reason'] === 'subscription_overdue'));
$monthlyOverdue = count(array_filter($overdueUsers, fn($u) => $u['overdue_reason'] === 'monthly_overdue'));
$noPayment = count(array_filter($overdueUsers, fn($u) => $u['overdue_reason'] === 'no_payment'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>未払い管理 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
    <style>
        .overdue-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            padding: 1.5rem;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .stat-card.primary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #ff9800 0%, #ff5722 100%);
        }

        .stat-card.info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .stat-card.success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
        }

        .action-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .btn-primary-action {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
        }

        .btn-primary-action:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-primary-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .overdue-table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .overdue-table {
            width: 100%;
            border-collapse: collapse;
        }

        .overdue-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .overdue-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .overdue-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .overdue-table tbody tr {
            transition: background-color 0.2s;
        }

        .overdue-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .overdue-table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-danger {
            background: #fee;
            color: #c33;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .user-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .user-type-new {
            background: #e3f2fd;
            color: #1976d2;
        }

        .user-type-existing {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .days-overdue {
            font-weight: 700;
            color: #d32f2f;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .empty-state p {
            font-size: 1rem;
            color: #666;
        }

        @media (max-width: 768px) {
            .overdue-container {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .overdue-table-container {
                overflow-x: auto;
            }

            .overdue-table {
                min-width: 800px;
            }

            .overdue-table th,
            .overdue-table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.5rem;
            }

            .stat-value {
                font-size: 2rem;
            }

            .action-bar {
                flex-direction: column;
            }

            .btn-primary-action {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>不動産AI名刺 管理画面</h1>
            <div class="admin-info">
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボード</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
        </header>

        <div class="overdue-container">
            <div class="page-header">
                <h2 class="page-title">未払い管理</h2>
                <?php if ($isAdmin): ?>
                <button type="button" class="btn-primary-action" id="btn-check-overdue">
                    <span id="btn-text">未払いをチェック</span>
                </button>
                <?php endif; ?>
            </div>

            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-label">総未払い件数</div>
                    <div class="stat-value"><?php echo number_format($totalOverdue); ?></div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-label">サブスクリプション延滞</div>
                    <div class="stat-value"><?php echo number_format($subscriptionOverdue); ?></div>
                </div>
                <div class="stat-card info">
                    <div class="stat-label">月額未払い</div>
                    <div class="stat-value"><?php echo number_format($monthlyOverdue); ?></div>
                </div>
                <div class="stat-card success">
                    <div class="stat-label">未払いなし</div>
                    <div class="stat-value"><?php echo $totalOverdue === 0 ? '✓' : '0'; ?></div>
                </div>
            </div>

            <div class="action-bar">
                <a href="dashboard.php" class="btn-primary-action" style="background: #6c757d; text-decoration: none; display: inline-block; text-align: center;">
                    ダッシュボードに戻る
                </a>
            </div>

            <div class="overdue-table-container">
                <?php if (empty($overdueUsers)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">✓</div>
                        <h3>未払いユーザーはありません</h3>
                        <p>すべてのユーザーが支払いを完了しています。</p>
                    </div>
                <?php else: ?>
                    <table class="overdue-table">
                        <thead>
                            <tr>
                                <th>ユーザータイプ</th>
                                <th>会社名</th>
                                <th>名前</th>
                                <th>メール</th>
                                <th>延滞理由</th>
                                <th>延滞日数</th>
                                <th>最終支払い日</th>
                                <th>次回請求日</th>
                                <th>公開状態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdueUsers as $user): ?>
                                <tr>
                                    <td>
                                        <span class="user-type-badge user-type-<?php echo htmlspecialchars($user['user_type']); ?>">
                                            <?php 
                                            $userTypeLabels = ['new' => '新規', 'existing' => '既存'];
                                            echo htmlspecialchars($userTypeLabels[$user['user_type']] ?? $user['user_type']); 
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['company_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($user['name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php
                                        $reasonLabels = [
                                            'subscription_overdue' => 'サブスクリプション延滞',
                                            'monthly_overdue' => '月額未払い',
                                            'no_payment' => '未払い'
                                        ];
                                        $reason = $user['overdue_reason'];
                                        $badgeClass = $reason === 'subscription_overdue' ? 'badge-danger' : 
                                                     ($reason === 'monthly_overdue' ? 'badge-warning' : 'badge-info');
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo htmlspecialchars($reasonLabels[$reason] ?? $reason); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="days-overdue">
                                            <?php echo number_format($user['days_overdue'] ?? 0); ?>日
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        echo $user['last_payment_date'] 
                                            ? date('Y/m/d', strtotime($user['last_payment_date'])) 
                                            : '<span style="color: #999;">未払い</span>'; 
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo $user['next_billing_date'] 
                                            ? date('Y/m/d', strtotime($user['next_billing_date'])) 
                                            : '<span style="color: #999;">-</span>'; 
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($user['is_published']): ?>
                                            <span style="color: #4caf50;">公開</span>
                                        <?php else: ?>
                                            <span style="color: #999;">非公開</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../assets/js/admin.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const checkOverdueBtn = document.getElementById('btn-check-overdue');
            if (checkOverdueBtn && window.isAdmin !== false) {
                checkOverdueBtn.addEventListener('click', async function() {
                    const message = '未払い月額料金をチェックして、該当するユーザーの支払いステータスを「未入金」に更新しますか？';
                    if (typeof showConfirm === 'function') {
                        showConfirm(message, async () => {
                            checkOverdueBtn.disabled = true;
                            const btnText = document.getElementById('btn-text');
                            if (btnText) btnText.textContent = 'チェック中...';
                            
                            try {
                                const response = await fetch('../../backend/api/admin/check-overdue-payments.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                    },
                                    credentials: 'include'
                                });
                                
                                const result = await response.json();
                                
                                if (result.success) {
                                    if (typeof showSuccess === 'function') {
                                        showSuccess(result.message || 'チェックが完了しました', {
                                            autoClose: 3000,
                                            onClose: () => location.reload()
                                        });
                                    } else {
                                        alert(result.message || 'チェックが完了しました');
                                        location.reload();
                                    }
                                } else {
                                    if (typeof showError === 'function') {
                                        showError(result.message || 'チェックに失敗しました');
                                    } else {
                                        alert(result.message || 'チェックに失敗しました');
                                    }
                                }
                            } catch (error) {
                                console.error('Error:', error);
                                if (typeof showError === 'function') {
                                    showError('エラーが発生しました');
                                } else {
                                    alert('エラーが発生しました');
                                }
                            } finally {
                                checkOverdueBtn.disabled = false;
                                if (btnText) btnText.textContent = '未払いをチェック';
                            }
                        });
                    } else {
                        if (confirm(message)) {
                            checkOverdueBtn.disabled = true;
                            const btnText = document.getElementById('btn-text');
                            if (btnText) btnText.textContent = 'チェック中...';
                            
                            try {
                                const response = await fetch('../../backend/api/admin/check-overdue-payments.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                    },
                                    credentials: 'include'
                                });
                                
                                const result = await response.json();
                                
                                if (result.success) {
                                    alert(result.message || 'チェックが完了しました');
                                    location.reload();
                                } else {
                                    alert(result.message || 'チェックに失敗しました');
                                }
                            } catch (error) {
                                console.error('Error:', error);
                                alert('エラーが発生しました');
                            } finally {
                                checkOverdueBtn.disabled = false;
                                if (btnText) btnText.textContent = '未払いをチェック';
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>


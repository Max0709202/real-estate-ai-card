<?php
/**
 * Overdue Payments Management Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

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
// business_cards.payment_status を現在の入金状態の正とし、月額対象（新規・非ERA）のみ next_billing_date を延滞判定に使う。
$stmt = $db->prepare(<<<SQL
    SELECT
        bc.id as business_card_id,
        bc.user_id,
        u.email,
        u.user_type,
        COALESCE(u.is_era_member, 0) as is_era_member,
        bc.company_name,
        bc.name,
        bc.mobile_phone,
        bc.url_slug,
        bc.is_published,
        COALESCE(bc.payment_status, 'UNUSED') as payment_status,
        ps.last_payment_date,
        ps.pending_payment_created_at,
        s.next_billing_date,
        s.status as subscription_status,
        s.billing_cycle,
        CASE
            WHEN COALESCE(bc.payment_status, 'UNUSED') = 'BANK_PENDING' THEN 'bank_pending'
            WHEN COALESCE(bc.payment_status, 'UNUSED') = 'UNUSED' THEN 'no_payment'
            WHEN u.user_type = 'new'
                 AND COALESCE(u.is_era_member, 0) = 0
                 AND s.status IN ('past_due', 'unpaid', 'incomplete', 'incomplete_expired', 'expired') THEN 'subscription_overdue'
            WHEN u.user_type = 'new'
                 AND COALESCE(u.is_era_member, 0) = 0
                 AND s.status IN ('active', 'trialing')
                 AND s.next_billing_date IS NOT NULL
                 AND s.next_billing_date <= CURDATE()
                 AND COALESCE(bc.payment_status, 'UNUSED') IN ('CR', 'BANK_PAID', 'ST')
                 AND NOT EXISTS (
                     SELECT 1
                     FROM payments p2
                     WHERE p2.business_card_id = bc.id
                       AND p2.payment_status = 'completed'
                       AND p2.paid_at >= s.next_billing_date
                       AND p2.payment_type IN ('new_user', 'renewal')
                 ) THEN 'subscription_overdue'
            ELSE 'other'
        END as overdue_reason,
        CASE
            WHEN COALESCE(bc.payment_status, 'UNUSED') = 'BANK_PENDING' THEN DATE(COALESCE(ps.pending_payment_created_at, bc.updated_at, bc.created_at))
            WHEN COALESCE(bc.payment_status, 'UNUSED') = 'UNUSED' THEN DATE(bc.created_at)
            WHEN s.next_billing_date IS NOT NULL THEN DATE(s.next_billing_date)
            ELSE DATE(COALESCE(s.updated_at, bc.updated_at, bc.created_at))
        END as due_reference_date,
        COALESCE(GREATEST(0, DATEDIFF(CURDATE(), CASE
            WHEN COALESCE(bc.payment_status, 'UNUSED') = 'BANK_PENDING' THEN DATE(COALESCE(ps.pending_payment_created_at, bc.updated_at, bc.created_at))
            WHEN COALESCE(bc.payment_status, 'UNUSED') = 'UNUSED' THEN DATE(bc.created_at)
            WHEN s.next_billing_date IS NOT NULL THEN DATE(s.next_billing_date)
            ELSE DATE(COALESCE(s.updated_at, bc.updated_at, bc.created_at))
        END)), 0) as days_overdue
    FROM business_cards bc
    INNER JOIN users u ON bc.user_id = u.id
    LEFT JOIN (
        SELECT
            business_card_id,
            MAX(CASE WHEN payment_status = 'completed' THEN paid_at END) as last_payment_date,
            MAX(CASE WHEN payment_status = 'pending' THEN created_at END) as pending_payment_created_at
        FROM payments
        GROUP BY business_card_id
    ) ps ON ps.business_card_id = bc.id
    LEFT JOIN subscriptions s ON s.id = (
        SELECT s2.id
        FROM subscriptions s2
        WHERE s2.business_card_id = bc.id
        ORDER BY s2.created_at DESC, s2.id DESC
        LIMIT 1
    )
    WHERE COALESCE(bc.card_status, 'active') <> 'canceled'
      AND (
        COALESCE(bc.payment_status, 'UNUSED') IN ('UNUSED', 'BANK_PENDING')
        OR (
            u.user_type = 'new'
            AND COALESCE(u.is_era_member, 0) = 0
            AND (
                s.status IN ('past_due', 'unpaid', 'incomplete', 'incomplete_expired', 'expired')
                OR (
                    s.status IN ('active', 'trialing')
                    AND s.next_billing_date IS NOT NULL
                    AND s.next_billing_date <= CURDATE()
                    AND COALESCE(bc.payment_status, 'UNUSED') IN ('CR', 'BANK_PAID', 'ST')
                    AND NOT EXISTS (
                        SELECT 1
                        FROM payments p3
                        WHERE p3.business_card_id = bc.id
                          AND p3.payment_status = 'completed'
                          AND p3.paid_at >= s.next_billing_date
                          AND p3.payment_type IN ('new_user', 'renewal')
                    )
                )
            )
        )
      )
    ORDER BY days_overdue DESC, bc.created_at DESC
SQL);

$stmt->execute();
$overdueUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 統計情報
$totalOverdue = count($overdueUsers);
$subscriptionOverdue = count(array_filter($overdueUsers, fn($u) => $u['overdue_reason'] === 'subscription_overdue'));
$bankPending = count(array_filter($overdueUsers, fn($u) => $u['overdue_reason'] === 'bank_pending'));
$noPayment = count(array_filter($overdueUsers, fn($u) => $u['overdue_reason'] === 'no_payment'));
$classificationLabels = ['new' => '新規', 'existing' => '既存', 'era' => 'ＥＲＡ'];
$paymentStatusLabels = [
    'CR' => 'CR',
    'BANK_PENDING' => '振込予定',
    'BANK_PAID' => '振込済',
    'ST' => 'ST送金',
    'UNUSED' => '未利用'
];
$reasonLabels = [
    'subscription_overdue' => '月額期限切れ',
    'bank_pending' => '振込予定',
    'no_payment' => '未利用/未入金'
];
$reasonBadgeClasses = [
    'subscription_overdue' => 'badge-danger',
    'bank_pending' => 'badge-warning',
    'no_payment' => 'badge-info'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
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
            background: linear-gradient(135deg, #494949 0%, #38f9d7 100%);
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

        .user-type-era {
            background: #fff3cd;
            color: #dc3545;
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
                min-width: 900px;
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
                    <div class="stat-label">月額期限切れ</div>
                    <div class="stat-value"><?php echo number_format($subscriptionOverdue); ?></div>
                </div>
                <div class="stat-card info">
                    <div class="stat-label">振込予定</div>
                    <div class="stat-value"><?php echo number_format($bankPending); ?></div>
                </div>
                <div class="stat-card success">
                    <div class="stat-label">未利用/未入金</div>
                    <div class="stat-value"><?php echo number_format($noPayment); ?></div>
                </div>
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
                                <th>入金状況</th>
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
                                        <?php
                                        $classification = !empty($user['is_era_member']) ? 'era' : ($user['user_type'] ?? 'new');
                                        ?>
                                        <span class="user-type-badge user-type-<?php echo htmlspecialchars($classification); ?>">
                                            <?php echo htmlspecialchars($classificationLabels[$classification] ?? $classification); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['company_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($user['name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php $paymentStatus = $user['payment_status'] ?? 'UNUSED'; ?>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($paymentStatusLabels[$paymentStatus] ?? $paymentStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $reason = $user['overdue_reason'];
                                        $badgeClass = $reasonBadgeClasses[$reason] ?? 'badge-info';
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
                    const message = '月額期限切れをチェックして、該当するカードの入金状況を「未利用」に更新しますか？';
                    if (typeof showConfirm === 'function') {
                        showConfirm(message, async () => {
                            checkOverdueBtn.disabled = true;
                            const btnText = document.getElementById('btn-text');
                            if (btnText) btnText.textContent = 'チェック中...';
                            
                            try {
                                const response = await fetch('../backend/api/admin/check-overdue-payments.php', {
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
                                const response = await fetch('../backend/api/admin/check-overdue-payments.php', {
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


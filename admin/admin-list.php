<?php
/**
 * Admin List Page - Display all administrators and manage roles
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

//管理者認証
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// 現在の管理者情報取得
$stmt = $db->prepare("SELECT id, email, role FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

// すべての管理者一覧取得
$stmt = $db->prepare("
    SELECT 
        a.id,
        a.email,
        a.role,
        a.email_verified,
        a.created_at,
        a.last_login_at,
        a.last_password_change,
        changed_by.email as changed_by_email
    FROM admins a
    LEFT JOIN admins changed_by ON a.last_password_changed_by = changed_by.id
    ORDER BY a.id ASC
");
$stmt->execute();
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 初期管理者（ID=1）かどうか、またはadminロールを持っているかチェック
$canManageRoles = ($currentAdmin['id'] == 1 || $currentAdmin['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>管理者一覧 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
    <style>
        .admin-list-container {
            padding: 20px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .admin-table thead {
            background: #0066cc;
            color: #fff;
        }
        .admin-table th {
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }
        .admin-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        .admin-table tbody tr:hover {
            background: #f5f5f5;
        }
        .admin-table tbody tr:last-child td {
            border-bottom: none;
        }
        .role-select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .role-select:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .role-admin {
            background: #dc3545;
            color: #fff;
        }
        .role-client {
            background: #28a745;
            color: #fff;
        }
        .verified-badge {
            color: #28a745;
            font-weight: bold;
        }
        .unverified-badge {
            color: #dc3545;
            font-weight: bold;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 20px;
            background: #6c757d;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .btn-back:hover {
            background: #5a6268;
        }
        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .current-user {
            background: #e7f3ff !important;
        }
        .initial-admin {
            background: #fff3cd !important;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>管理者一覧</h1>
            <div class="admin-info">
                <p>現在のロール: <strong><?php echo $currentAdmin['role'] === 'admin' ? '管理者' : 'クライアント'; ?></strong></p>
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボードへ戻る</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
        </header>

        <div class="admin-content">
            <div class="admin-list-container">                
                <div id="message-container"></div>
                <div class="page-header">
                    <h2>登録されている管理者一覧</h2>
                    <p>総数: <?php echo count($admins); ?>名</p>
                </div>

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>メールアドレス</th>
                            <th>ロール</th>
                            <th>メール認証</th>
                            <th>登録日</th>
                            <th>最終ログイン</th>
                            <th>最終パスワード変更</th>
                            <th>変更者</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                        <tr class="<?php 
                            echo ($admin['id'] == $_SESSION['admin_id']) ? 'current-user' : ''; 
                            echo ($admin['id'] == 1) ? ' initial-admin' : '';
                        ?>">
                            <td>
                                <?php echo htmlspecialchars($admin['id']); ?>
                                <?php if ($admin['id'] == 1): ?>
                                    <span style="color: #ffc107; font-weight: bold;">（初期管理者）</span>
                                <?php endif; ?>
                                <?php if ($admin['id'] == $_SESSION['admin_id']): ?>
                                    <span style="color: #0066cc; font-weight: bold;">（現在のユーザー）</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                            <td>
                                <?php if ($canManageRoles && ($admin['id'] == 1 || $currentAdmin['role'] === 'admin')): ?>
                                    <?php if ($admin['id'] == 1 && $currentAdmin['id'] != 1): ?>
                                        <!-- Initial admin's role cannot be changed by others -->
                                        <span class="role-badge role-<?php echo $admin['role']; ?>">
                                            <?php echo $admin['role'] === 'admin' ? '管理者' : 'クライアント'; ?>
                                        </span>
                                    <?php else: ?>
                                        <select class="role-select" 
                                                data-admin-id="<?php echo $admin['id']; ?>"
                                                data-current-role="<?php echo $admin['role']; ?>">
                                            <option value="admin" <?php echo $admin['role'] === 'admin' ? 'selected' : ''; ?>>管理者</option>
                                            <option value="client" <?php echo $admin['role'] === 'client' ? 'selected' : ''; ?>>クライアント</option>
                                        </select>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="role-badge role-<?php echo $admin['role']; ?>">
                                        <?php echo $admin['role'] === 'admin' ? '管理者' : 'クライアント'; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($admin['email_verified']): ?>
                                    <span class="verified-badge">✓ 認証済み</span>
                                <?php else: ?>
                                    <span class="unverified-badge">✗ 未認証</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($admin['created_at'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($admin['last_login_at'] ?? '未ログイン'); ?></td>
                            <td><?php echo htmlspecialchars($admin['last_password_change'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($admin['changed_by_email'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <h3>権限について</h3>
                    <ul>
                        <!-- <li><strong>初期管理者（ID=1）</strong>: すべての管理者のロールを変更できます。</li> -->
                        <li><strong>管理者ロール</strong>: 他の管理者のロールを変更できます。</li>
                        <li><strong>クライアントロール</strong>: ユーザー情報の閲覧のみ可能です。データベース変更はできません。</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Role change handler
        document.querySelectorAll('.role-select').forEach(function(select) {
            select.addEventListener('change', function() {
                const adminId = this.getAttribute('data-admin-id');
                const newRole = this.value;
                const currentRole = this.getAttribute('data-current-role');
                
                // Confirm role change
                if (confirm('ロールを「' + (newRole === 'admin' ? '管理者' : 'クライアント') + '」に変更しますか？')) {
                    updateAdminRole(adminId, newRole, this);
                } else {
                    // Revert selection
                    this.value = currentRole;
                }
            });
        });

        function updateAdminRole(adminId, newRole, selectElement) {
            const messageContainer = document.getElementById('message-container');
            messageContainer.innerHTML = '';
            
            fetch('../backend/api/admin/update-role.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    admin_id: adminId,
                    role: newRole
                })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Update current role attribute
                    selectElement.setAttribute('data-current-role', newRole);
                    
                    // Show success message
                    messageContainer.innerHTML = '<div class="message message-success">ロールを正常に更新しました。</div>';
                    
                    // Reload page after 2 seconds to reflect changes
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    // Revert selection on error
                    selectElement.value = selectElement.getAttribute('data-current-role');
                    
                    // Show error message
                    messageContainer.innerHTML = '<div class="message message-error">' + (result.message || 'ロールの更新に失敗しました') + '</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                selectElement.value = selectElement.getAttribute('data-current-role');
                messageContainer.innerHTML = '<div class="message message-error">エラーが発生しました</div>';
            });
        }
    </script>
</body>
</html>


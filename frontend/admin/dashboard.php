<?php
/**
 * Admin Dashboard
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

//管理者認証
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// 最終パスワード変更情報取得
$stmt = $db->prepare("
    SELECT a.last_password_change, a.last_password_changed_by,
           changed_by.email as changed_by_email
    FROM admins a
    LEFT JOIN admins changed_by ON a.last_password_changed_by = changed_by.id
    WHERE a.id = ?
");
$stmt->execute([$_SESSION['admin_id']]);
$adminInfo = $stmt->fetch();

// 最新の変更履歴を取得（この管理者が行った変更操作）
$stmt = $db->prepare("
    SELECT change_type, target_type, description, changed_at, admin_email
    FROM admin_change_logs
    WHERE admin_id = ?
    ORDER BY changed_at DESC
    LIMIT 1
");
$stmt->execute([$_SESSION['admin_id']]);
$latestChange = $stmt->fetch();

// ユーザー一覧取得（検索・ソート対応）
$page = (int)($_GET['page'] ?? 1);
$limit = 50;
$offset = ($page - 1) * $limit;

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

// Map sort fields from request to database fields
$sortFieldMap = [
    'payment_confirmed' => 'payment_confirmed',
    'is_open' => 'bc.is_published',
    'user_type' => 'u.user_type',
    'company_name' => 'bc.company_name',
    'name' => 'bc.name',
    'mobile_phone' => 'bc.mobile_phone',
    'email' => 'u.email',
    'monthly_views' => 'monthly_views',
    'total_views' => 'total_views',
    'url_slug' => 'bc.url_slug',
    'registered_at' => 'bc.created_at',
    'last_login_at' => 'u.last_login_at'
];

$requestedSort = $_GET['sort'] ?? 'registered_at';
$sortField = $sortFieldMap[$requestedSort] ?? 'bc.created_at';
$sortOrder = strtoupper($_GET['order'] ?? 'DESC');

$sql = "
    SELECT
        bc.id,
        u.id as user_id,
        u.email,
        u.user_type,
        bc.company_name,
        bc.name,
        bc.mobile_phone,
        bc.url_slug,
        bc.is_published as is_open,
        bc.admin_notes,
        CASE WHEN EXISTS (SELECT 1 FROM payments p2 WHERE p2.business_card_id = bc.id AND p2.payment_status = 'completed') THEN 1 ELSE 0 END as payment_confirmed,
        COALESCE((
            SELECT COUNT(*)
            FROM access_logs al
            WHERE al.business_card_id = bc.id
        ), 0) as total_views,
        COALESCE((
            SELECT COUNT(*)
            FROM access_logs al
            WHERE al.business_card_id = bc.id
            AND al.accessed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
        ), 0) as monthly_views,
        bc.created_at as registered_at,
        u.last_login_at
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    $whereClause
    GROUP BY bc.id, u.id, u.email, u.user_type, bc.company_name, bc.name, bc.mobile_phone, bc.url_slug,
             bc.is_published, bc.admin_notes, bc.created_at, u.last_login_at
    ORDER BY $sortField $sortOrder
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>管理画面 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>不動産AI名刺 管理画面</h1>
            <div class="admin-header-actions">
                <div class="admin-info">
                    <?php if ($latestChange): ?>
                        <p>最終変更操作: <?php
                            $changeTypeText = [
                                'payment_confirmed' => '入金確認',
                                'qr_code_issued' => 'QRコード発行',
                                'published_changed' => '公開状態変更',
                                'user_deleted' => 'ユーザー削除',
                                'other' => 'その他'
                            ];
                            echo htmlspecialchars($changeTypeText[$latestChange['change_type']] ?? $latestChange['change_type']);
                        ?></p>
                        <p>変更日時: <?php echo htmlspecialchars($latestChange['changed_at']); ?></p>
                        <p>変更者: <?php echo htmlspecialchars($latestChange['admin_email']); ?></p>
                        <?php if (!empty($latestChange['description'])): ?>
                            <p style="font-size: 12px; color: #666;"><?php echo htmlspecialchars($latestChange['description']); ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>変更履歴: なし</p>
                    <?php endif; ?>
                    <?php
                    // Get current admin role
                    $stmt = $db->prepare("SELECT role FROM admins WHERE id = ?");
                    $stmt->execute([$_SESSION['admin_id']]);
                    $currentAdminRole = $stmt->fetchColumn();
                    $isAdmin = ($currentAdminRole === 'admin' || $_SESSION['admin_id'] == 1);
                    ?>
                </div>
                <div class="admin-theme-toggle" id="theme-toggle" title="テーマを切り替え">
                    <svg class="theme-icon sun-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="5"></circle>
                        <line x1="12" y1="1" x2="12" y2="3"></line>
                        <line x1="12" y1="21" x2="12" y2="23"></line>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                        <line x1="1" y1="12" x2="3" y2="12"></line>
                        <line x1="21" y1="12" x2="23" y2="12"></line>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                    </svg>
                    <svg class="theme-icon moon-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                    </svg>
                </div>
                <div class="admin-user-menu">
                    <div class="admin-user-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                <div class="admin-user-dropdown">
                    <a href="admin-list.php" class="admin-dropdown-item">
                        <span>管理者一覧</span>
                    </a>
                    <a href="email-logs.php" class="admin-dropdown-item">
                        <span>メール送信ログ</span>
                    </a>
                    <a href="send-email.php" class="admin-dropdown-item">
                        <span>メール招待</span>
                    </a>
                    <a href="overdue-payments.php" class="admin-dropdown-item">
                        <span>未払い管理</span>
                    </a>
                    <a href="logout.php" class="admin-dropdown-item admin-dropdown-logout">
                        <span>ログアウト</span>
                    </a>
                </div>
            </div>
        </header>

        <div class="admin-content">
            <div class="filters">
                <form method="GET" class="filter-form">
                    <select name="payment_status">
                        <option value="">入金状況</option>
                        <option value="completed" <?php echo ($_GET['payment_status'] ?? '') === 'completed' ? 'selected' : ''; ?>>入金済み</option>
                        <option value="pending" <?php echo ($_GET['payment_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>未入金</option>
                    </select>
                    <select name="is_open">
                        <option value="">公開状況</option>
                        <option value="1" <?php echo ($_GET['is_open'] ?? '') === '1' ? 'selected' : ''; ?>>公開中</option>
                        <option value="0" <?php echo ($_GET['is_open'] ?? '') === '0' ? 'selected' : ''; ?>>非公開</option>
                    </select>
                    <button type="submit" class="btn-filter">検索</button>
                    <?php
                    // CSV出力リンクに検索条件を追�
                    $csvParams = [];
                    if (!empty($_GET['payment_status'])) {
                        $csvParams['payment_status'] = $_GET['payment_status'];
                    }
                    if (isset($_GET['is_open']) && $_GET['is_open'] !== '') {
                        $csvParams['is_open'] = $_GET['is_open'];
                    }
                    $csvUrl = '../../backend/api/admin/export-csv.php';
                    if (!empty($csvParams)) {
                        $csvUrl .= '?' . http_build_query($csvParams);
                    }
                    ?>
                    <a href="<?php echo htmlspecialchars($csvUrl); ?>" class="btn-export">CSV出力</a>
                    <?php if ($isAdmin): ?>
                    <button type="button" class="btn-check-overdue" id="btn-check-overdue" title="未払い月額料金をチェックして自動更新">
                        未払いチェック
                    </button>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <button type="button" class="btn-delete-bulk" id="btn-delete-selected" disabled>
                    選択したユーザーを削除
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn-delete-bulk" disabled style="opacity: 0.5; cursor: not-allowed;" title="クライアントロールは削除操作ができません">
                    選択したユーザーを削除
                    </button>
                    <?php endif; ?>
                </form>
            </div>

            <table class="users-table">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="user_type">ユーザータイプ</th>
                        <th class="sortable" data-sort="payment_confirmed">入金</th>
                        <th class="sortable" data-sort="is_open">OPEN</th>
                        <th class="sortable" data-sort="company_name">社名</th>
                        <th class="sortable" data-sort="name">名前</th>
                        <th class="sortable" data-sort="mobile_phone">携帯</th>
                        <th class="sortable" data-sort="email">メール</th>
                        <th class="sortable" data-sort="monthly_views">表示回数<br>（1か月）</th>
                        <th class="sortable" data-sort="total_views">表示回数<br>（累積）</th>
                        <th class="sortable" data-sort="registered_at">登録日</th>
                        <th class="sortable" data-sort="last_login_at">最終ログイン</th>
                        <th>備考</th>
                        <th>削除
                            <input type="checkbox" id="select-all" title="すべて選択">
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $index => $user): ?>
                    <tr class="<?php echo ($index % 2 === 0) ? 'even-row' : 'odd-row'; ?>">
                        <td data-label="ユーザータイプ">
                            <?php
                            $userTypeText = [
                                'new' => '新規',
                                'existing' => '既存',
                                'free' => '無料'
                            ];
                            $userTypeClass = [
                                'new' => 'user-type-new',
                                'existing' => 'user-type-existing',
                                'free' => 'user-type-free'
                            ];
                            $type = $user['user_type'] ?? 'new';
                            ?>
                            <span class="user-type-badge <?php echo $userTypeClass[$type] ?? 'user-type-new'; ?>">
                                <?php echo htmlspecialchars($userTypeText[$type] ?? '新規'); ?>
                            </span>
                        </td>
                        <td data-label="入金">
                            <?php
                            $isFreeUser = ($user['user_type'] ?? 'new') === 'free';
                            $paymentDisabled = !$isAdmin || $isFreeUser;
                            ?>
                            <input type="checkbox" class="payment-checkbox"
                                   data-bc-id="<?php echo $user['id']; ?>"
                                   <?php echo $user['payment_confirmed'] ? 'checked' : ''; ?>
                                   <?php echo $paymentDisabled ? 'disabled' : ''; ?>
                                   <?php if ($isFreeUser): ?>title="無料ユーザーは入金確認できません"<?php endif; ?>>
                        </td>
                        <td data-label="OPEN">
                            <input type="checkbox" class="open-checkbox"
                                   data-bc-id="<?php echo $user['id']; ?>"
                                   <?php echo $user['is_open'] ? 'checked' : ''; ?>
                                   <?php echo !$isAdmin ? 'disabled' : ''; ?>>
                        </td>
                        <td data-label="社名"><?php echo htmlspecialchars($user['company_name'] ?? ''); ?></td>
                        <td data-label="名前">
                            <?php if (!empty($user['url_slug'])): ?>
                                <a href="<?php echo BASE_URL; ?>/frontend/card.php?slug=<?php echo htmlspecialchars($user['url_slug']); ?>" target="_blank" style="color: #0066cc; text-decoration: underline; cursor: pointer;">
                                    <?php echo htmlspecialchars($user['name'] ?? ''); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($user['name'] ?? ''); ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="携帯"><?php echo htmlspecialchars($user['mobile_phone'] ?? ''); ?></td>
                        <td data-label="メール"><?php echo htmlspecialchars($user['email']); ?></td>
                        <td data-label="表示回数（1か月）"><?php echo $user['monthly_views']; ?></td>
                        <td data-label="表示回数（累積）"><?php echo $user['total_views']; ?></td>
                        <td data-label="登録日"><?php echo htmlspecialchars($user['registered_at']); ?></td>
                        <td data-label="最終ログイン"><?php echo htmlspecialchars($user['last_login_at'] ?? ''); ?></td>
                        <td data-label="備考">
                            <textarea class="admin-notes-input"
                                      data-bc-id="<?php echo $user['id']; ?>"
                                      rows="2"
                                      placeholder="備考を入力..."
                                      style="width: 100%; min-width: 200px; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.85rem; font-family: inherit; resize: vertical;"><?php echo htmlspecialchars($user['admin_notes'] ?? ''); ?></textarea>
                            <button type="button" class="btn-save-notes"
                                    data-bc-id="<?php echo $user['id']; ?>"
                                    style="margin-top: 0.25rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; background: #28a745; color: #fff; border: none; border-radius: 3px; cursor: pointer; display: none;">
                                保存
                            </button>
                            <span class="notes-save-status" data-bc-id="<?php echo $user['id']; ?>" style="font-size: 0.75rem; color: #28a745; margin-left: 0.5rem; display: none;"></span>
                        </td>
                        <!-- <td data-label="操作">
                            <?php if ($isAdmin): ?>
                            <button class="btn-action" onclick="confirmPayment(<?php echo $user['id']; ?>)">入金確認</button>
                            <?php else: ?>
                            <button class="btn-action" disabled style="opacity: 0.5; cursor: not-allowed;" title="クライアントロールは操作ができません">入金確認</button>
                            <?php endif; ?>
                        </td> -->
                        <td data-label="選択">
                            <input type="checkbox" class="user-select-checkbox"
                                   data-user-id="<?php echo $user['user_id']; ?>"
                                   data-bc-id="<?php echo $user['id']; ?>"
                                   <?php echo !$isAdmin ? 'disabled' : ''; ?>>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Pass admin role to JavaScript
        window.isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        window.currentAdminId = <?php echo $_SESSION['admin_id']; ?>;

        // Theme Toggle Functionality
        (function() {
            const themeToggle = document.getElementById('theme-toggle');
            const body = document.body;
            const html = document.documentElement;

            // Get saved theme or default to light
            const savedTheme = localStorage.getItem('admin-theme') || 'light';

            // Apply saved theme on page load
            if (savedTheme === 'dark') {
                body.classList.add('dark-theme');
                html.classList.add('dark-theme');
            }

            // Update icon visibility based on current theme
            function updateIcons() {
                const isDark = body.classList.contains('dark-theme');
                const sunIcon = themeToggle.querySelector('.sun-icon');
                const moonIcon = themeToggle.querySelector('.moon-icon');

                if (isDark) {
                    sunIcon.style.display = 'none';
                    moonIcon.style.display = 'block';
                } else {
                    sunIcon.style.display = 'block';
                    moonIcon.style.display = 'none';
                }
            }

            // Initialize icon visibility
            updateIcons();

            // Toggle theme on click
            themeToggle.addEventListener('click', function() {
                body.classList.toggle('dark-theme');
                html.classList.toggle('dark-theme');

                const isDark = body.classList.contains('dark-theme');
                localStorage.setItem('admin-theme', isDark ? 'dark' : 'light');

                updateIcons();
            });
        })();

        // Admin Notes Save Functionality
        (function() {
            const notesInputs = document.querySelectorAll('.admin-notes-input');

            notesInputs.forEach(function(input) {
                const bcId = input.dataset.bcId;
                const saveBtn = document.querySelector(`.btn-save-notes[data-bc-id="${bcId}"]`);
                const statusIndicator = document.querySelector(`.notes-save-status[data-bc-id="${bcId}"]`);
                let saveTimeout;

                // Store initial value to track changes
                const initialValue = (input.value || '').trim();

                // Show save button when input changes
                input.addEventListener('input', function() {
                    const currentValue = (input.value || '').trim();
                    const hasChanged = currentValue !== initialValue;
                    if (hasChanged && saveBtn) {
                        saveBtn.style.display = 'inline-block';
                    } else if (!hasChanged && saveBtn) {
                        saveBtn.style.display = 'none';
                    }
                    if (statusIndicator) {
                        statusIndicator.style.display = 'none';
                    }
                });

                // Auto-save on blur (when user clicks away) - only if value changed
                input.addEventListener('blur', function() {
                    clearTimeout(saveTimeout);
                    const currentValue = (input.value || '').trim();
                    const hasChanged = currentValue !== initialValue;

                    if (hasChanged) {
                        saveTimeout = setTimeout(function() {
                            saveNotes(bcId, currentValue, saveBtn, statusIndicator);
                        }, 300);
                    }
                });

                // Manual save button click
                if (saveBtn) {
                    saveBtn.addEventListener('click', function() {
                        const currentValue = (input.value || '').trim();
                        const hasChanged = currentValue !== initialValue;
                        if (hasChanged) {
                            saveNotes(bcId, currentValue, saveBtn, statusIndicator);
                        }
                    });
                }
            });

            function saveNotes(bcId, notes, saveBtn, statusIndicator) {
                if (!bcId) return;

                fetch('../../backend/api/admin/update-notes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        business_card_id: parseInt(bcId),
                        admin_notes: notes
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        if (saveBtn) saveBtn.style.display = 'none';
                        if (statusIndicator) {
                            statusIndicator.textContent = '保存しました';
                            statusIndicator.style.display = 'inline';
                            statusIndicator.style.color = '#28a745';
                            setTimeout(function() {
                                statusIndicator.style.display = 'none';
                            }, 2000);
                        }
                    } else {
                        if (statusIndicator) {
                            statusIndicator.textContent = '保存失敗';
                            statusIndicator.style.display = 'inline';
                            statusIndicator.style.color = '#dc3545';
                            setTimeout(function() {
                                statusIndicator.style.display = 'none';
                            }, 2000);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error saving notes:', error);
                    if (statusIndicator) {
                        statusIndicator.textContent = 'エラー';
                        statusIndicator.style.display = 'inline';
                        statusIndicator.style.color = '#dc3545';
                        setTimeout(function() {
                            statusIndicator.style.display = 'none';
                        }, 2000);
                    }
                });
            }
        })();
    </script>
    <script src="../assets/js/modal.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>


<?php
/**
 * Admin Dashboard
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
if (!empty($_GET['payment_status']) && in_array($_GET['payment_status'], ['CR', 'BANK_PENDING', 'BANK_PAID', 'ST', 'UNUSED'])) {
    $where[] = "bc.payment_status = ?";
    $params[] = $_GET['payment_status'];
}

// 公開状況の検索条件（空文字列の場合は条件を追加しない）
if (isset($_GET['is_open']) && $_GET['is_open'] !== '') {
    $where[] = "bc.is_published = ?";
    $params[] = (int)$_GET['is_open'];
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Map sort fields from request to database fields
$sortFieldMap = [
    'payment_status' => 'bc.payment_status',
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
        u.is_era_member,
        bc.company_name,
        bc.name,
        bc.mobile_phone,
        bc.url_slug,
        bc.is_published as is_open,
        bc.admin_notes,
        bc.payment_status,
        s.next_billing_date,
        s.cancelled_at,
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
    LEFT JOIN (
        SELECT s1.business_card_id, s1.next_billing_date, s1.cancelled_at
        FROM subscriptions s1
        INNER JOIN (
            SELECT business_card_id, MAX(created_at) as max_created_at
            FROM subscriptions
            GROUP BY business_card_id
        ) s2 ON s1.business_card_id = s2.business_card_id AND s1.created_at = s2.max_created_at
    ) s ON s.business_card_id = bc.id
    $whereClause
    GROUP BY bc.id, u.id, u.email, u.user_type, u.is_era_member, bc.company_name, bc.name, bc.mobile_phone, bc.url_slug,
             bc.is_published, bc.admin_notes, bc.payment_status, bc.created_at, u.last_login_at,
             s.next_billing_date, s.cancelled_at
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
                        <option value="CR" <?php echo ($_GET['payment_status'] ?? '') === 'CR' ? 'selected' : ''; ?>>CR</option>
                        <option value="BANK_PENDING" <?php echo ($_GET['payment_status'] ?? '') === 'BANK_PENDING' ? 'selected' : ''; ?>>振込予定</option>
                        <option value="BANK_PAID" <?php echo ($_GET['payment_status'] ?? '') === 'BANK_PAID' ? 'selected' : ''; ?>>振込済</option>
                        <option value="ST" <?php echo ($_GET['payment_status'] ?? '') === 'ST' ? 'selected' : ''; ?>>ST送金</option>
                        <option value="UNUSED" <?php echo ($_GET['payment_status'] ?? '') === 'UNUSED' ? 'selected' : ''; ?>>未利用</option>
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
                    $csvUrl = '../backend/api/admin/export-csv.php';
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
                        <th class="sortable" data-sort="user_type">分類</th>
                        <th class="sortable" data-sort="payment_status">入金状況</th>
                        <th class="sortable" data-sort="is_open">OPEN</th>
                        <th class="sortable" data-sort="company_name">社名</th>
                        <th class="sortable" data-sort="url_slug">企業URL</th>
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
                    <?php
                    // Calculate usage period display for each user
                    $usagePeriodDisplay = null;
                    $paymentStatus = $user['payment_status'] ?? 'UNUSED';
                    
                    if (in_array($paymentStatus, ['CR', 'BANK_PAID', 'ST'])) {
                        // Calculate end date
                        $endDate = null;
                        if ($user['next_billing_date']) {
                            $nextBilling = new DateTime($user['next_billing_date']);
                            $nextBilling->modify('-1 day');
                            $endDate = $nextBilling->format('Y年n月j日');
                        } elseif ($user['cancelled_at']) {
                            $cancelled = new DateTime($user['cancelled_at']);
                            $endDate = $cancelled->format('Y年n月j日');
                        }
                        
                        // If no end date from subscription, try to get from payment
                        if (!$endDate) {
                            $stmt = $db->prepare("
                                SELECT paid_at
                                FROM payments
                                WHERE user_id = ? AND payment_status = 'completed'
                                ORDER BY paid_at DESC, created_at DESC
                                LIMIT 1
                            ");
                            $stmt->execute([$user['user_id']]);
                            $paymentMethodData = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($paymentMethodData && $paymentMethodData['paid_at']) {
                                $paidDate = new DateTime($paymentMethodData['paid_at']);
                                $paidDate->modify('+1 month');
                                $paidDate->modify('-1 day');
                                $endDate = $paidDate->format('Y年n月j日');
                            }
                        }
                        
                        // Show date for both bank transfer and credit card payments
                        $usagePeriodDisplay = $endDate ? $endDate . '迄' : '期限未設定';
                    }
                    ?>
                    <tr class="<?php echo ($index % 2 === 0) ? 'even-row' : 'odd-row'; ?>">
                        <td data-label="分類">
                            <?php
                            $type = $user['user_type'] ?? 'new';
                            $isEra = $user['is_era_member'] ?? 0;
                            // Determine effective classification
                            $classification = $isEra ? 'era' : $type;
                            ?>
                            <select class="user-classification-select"
                                    data-user-id="<?php echo $user['user_id']; ?>"
                                    data-bc-id="<?php echo $user['id']; ?>"
                                    style="padding: 4px 8px; border-radius: 4px; border: 1px solid #ddd; font-size: 0.85rem; min-width: 70px;
                                           <?php echo $classification === 'era' ? 'color: #dc3545; font-weight: bold;' : ''; ?>">
                                <option value="new" <?php echo ($classification === 'new') ? 'selected' : ''; ?>>新規</option>
                                <option value="existing" <?php echo ($classification === 'existing') ? 'selected' : ''; ?>>既存</option>
                                <option value="era" <?php echo ($classification === 'era') ? 'selected' : ''; ?> style="color: #dc3545; font-weight: bold;">ＥＲＡ</option>
                            </select>
                        </td>
                        <td data-label="入金状況">
                            <?php
                            $paymentStatus = $user['payment_status'] ?? 'UNUSED';
                            $paymentStatusLabels = [
                                'CR' => 'CR',
                                'BANK_PENDING' => '振込予定',
                                'BANK_PAID' => '振込済',
                                'ST' => 'ST送金',
                                'UNUSED' => '未利用'
                            ];
                            $paymentStatusClasses = [
                                'CR' => 'payment-badge-credit',
                                'BANK_PENDING' => 'payment-badge-transfer-pending',
                                'BANK_PAID' => 'payment-badge-transfer-completed',
                                'ST' => 'payment-badge-credit', // ST送金はクレジットと同じグリーン背景
                                'UNUSED' => 'payment-badge-unused'
                            ];
                            $label = $paymentStatusLabels[$paymentStatus] ?? '未利用';
                            $class = $paymentStatusClasses[$paymentStatus] ?? 'payment-badge-unused';
                            // Allow toggling between BANK_PENDING and BANK_PAID
                            $canToggle = $isAdmin && in_array($paymentStatus, ['BANK_PENDING', 'BANK_PAID']);
                            $toggleTitle = $paymentStatus === 'BANK_PENDING' 
                                ? 'クリックして「振込済」に変更' 
                                : 'クリックして「振込予定」に戻す';
                            ?>
                            <span class="payment-badge <?php echo $class; ?> <?php echo $canToggle ? 'payment-badge-clickable' : ''; ?>"
                                  data-bc-id="<?php echo $user['id']; ?>"
                                  data-current-status="<?php echo htmlspecialchars($paymentStatus); ?>"
                                  <?php if ($canToggle): ?>
                                  onclick="confirmBankTransferPaid(<?php echo $user['id']; ?>, this)"
                                  title="<?php echo htmlspecialchars($toggleTitle); ?>"
                                  style="cursor: pointer;"
                                  <?php endif; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </span>
                        </td>
                        <td data-label="OPEN">
                            <?php
                            $paymentStatus = $user['payment_status'] ?? 'UNUSED';
                            $canOpen = in_array($paymentStatus, ['CR', 'BANK_PAID', 'ST']);
                            $isActuallyOpen = $user['is_open'] && $canOpen; // Force closed if payment not allowed
                            $isDisabled = !$isAdmin || !$canOpen;
                            $tooltip = !$canOpen ? '入金完了（CR / 振込済）後にOPEN可能' : '';
                            ?>
                            <input type="checkbox" class="open-checkbox"
                                   data-bc-id="<?php echo $user['id']; ?>"
                                   data-payment-status="<?php echo htmlspecialchars($paymentStatus); ?>"
                                   <?php echo $isActuallyOpen ? 'checked' : ''; ?>
                                   <?php echo $isDisabled ? 'disabled' : ''; ?>
                                   <?php if ($tooltip): ?>title="<?php echo htmlspecialchars($tooltip); ?>"<?php endif; ?>>
                            <?php if ($tooltip && !$isActuallyOpen): ?>
                                <!-- <span style="font-size: 0.75rem; color: #999; margin-left: 0.5rem;" title="<?php echo htmlspecialchars($tooltip); ?>">※</span> -->
                            <?php endif; ?>
                        </td>
                        <td data-label="社名"><?php echo htmlspecialchars($user['company_name'] ?? ''); ?></td>
                        <td data-label="企業URL">
                            <?php
                            $isEraUser = $user['is_era_member'] ?? 0;
                            $userTypeForUrl = $user['user_type'] ?? 'new';
                            $canEditCorporateUrl = ($userTypeForUrl === 'existing' || $isEraUser);
                            $currentSlug = $user['url_slug'] ?? '';
                            $baseUrl = $isEraUser ? 'https://era.self-in.com/' : 'https://self-in.com/';
                            ?>
                            <div style="display: flex; align-items: center; gap: 2px; white-space: nowrap; font-size: 0.8rem;">
                                <?php if ($isEraUser): ?>
                                    <span>https://</span><span style="color: #dc3545; font-weight: bold;">era</span><span>.self-in.com/</span>
                                <?php else: ?>
                                    <span>https://self-in.com/</span>
                                <?php endif; ?>
                                <?php if ($canEditCorporateUrl): ?>
                                    <input type="text" class="url-slug-input"
                                           data-user-id="<?php echo $user['user_id']; ?>"
                                           data-bc-id="<?php echo $user['id']; ?>"
                                           value="<?php echo htmlspecialchars($currentSlug); ?>"
                                           placeholder="スラッグ"
                                           style="width: 80px; padding: 3px 6px; border: 1px solid #ddd; border-radius: 3px; font-size: 0.8rem;">
                                    <span>/</span>
                                    <button type="button" class="btn-save-slug"
                                            data-bc-id="<?php echo $user['id']; ?>"
                                            style="padding: 2px 6px; font-size: 0.7rem; background: #28a745; color: #fff; border: none; border-radius: 3px; cursor: pointer; display: none;">
                                        保存
                                    </button>
                                <?php else: ?>
                                    <span><?php echo htmlspecialchars($currentSlug); ?></span><?php if ($currentSlug !== ''): ?><span>/</span><?php endif; ?>
                                    <span style="color: #999; font-size: 0.7rem;">（新規は編集不可）</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="名前">
                            <?php if (!empty($user['url_slug'])): ?>
                                <a href="<?php echo BASE_URL; ?>/card.php?slug=<?php echo htmlspecialchars($user['url_slug']); ?>" target="_blank" style="color: #0066cc; text-decoration: underline; cursor: pointer;">
                                    <?php echo htmlspecialchars($user['name'] ?? ''); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($user['name'] ?? ''); ?>
                            <?php endif; ?>
                            <?php if ($usagePeriodDisplay): ?>
                                <div style="font-size: 0.875rem; color: #666; margin-top: 0.25rem;">
                                    <?php echo htmlspecialchars($usagePeriodDisplay); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="携帯"><?php echo htmlspecialchars($user['mobile_phone'] ?? ''); ?></td>
                        <td data-label="メール">
                            <a href="mailto:<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" style="color: #0066cc; text-decoration: none;">
                                <?php echo htmlspecialchars($user['email']); ?>
                            </a>
                        </td>
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

                fetch('../backend/api/admin/update-notes.php', {
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

        // User Classification Dropdown Handler
        (function() {
            const classificationSelects = document.querySelectorAll('.user-classification-select');
            
            classificationSelects.forEach(function(select) {
                const originalValue = select.value;
                
                select.addEventListener('change', function() {
                    const userId = this.dataset.userId;
                    const bcId = this.dataset.bcId;
                    const newValue = this.value;
                    
                    // Update select color based on selection
                    if (newValue === 'era') {
                        this.style.color = '#dc3545';
                        this.style.fontWeight = 'bold';
                    } else {
                        this.style.color = '';
                        this.style.fontWeight = '';
                    }
                    
                    // Confirm change
                    const classificationText = {
                        'new': '新規',
                        'existing': '既存',
                        'era': 'ＥＲＡ'
                    };
                    
                    if (!confirm('分類を「' + classificationText[newValue] + '」に変更しますか？')) {
                        this.value = originalValue;
                        if (originalValue === 'era') {
                            this.style.color = '#dc3545';
                            this.style.fontWeight = 'bold';
                        } else {
                            this.style.color = '';
                            this.style.fontWeight = '';
                        }
                        return;
                    }
                    
                    // Send update to server
                    fetch('../backend/api/admin/update-user-classification.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            user_id: parseInt(userId),
                            business_card_id: parseInt(bcId),
                            classification: newValue
                        })
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            // Reload page to update URL display
                            window.location.reload();
                        } else {
                            alert('エラー: ' + (result.message || '更新に失敗しました'));
                            this.value = originalValue;
                            if (originalValue === 'era') {
                                this.style.color = '#dc3545';
                                this.style.fontWeight = 'bold';
                            } else {
                                this.style.color = '';
                                this.style.fontWeight = '';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('エラーが発生しました');
                        this.value = originalValue;
                    });
                });
            });
        })();

        // URL Slug Input Handler
        (function() {
            const slugInputs = document.querySelectorAll('.url-slug-input');
            
            slugInputs.forEach(function(input) {
                const bcId = input.dataset.bcId;
                const saveBtn = document.querySelector('.btn-save-slug[data-bc-id="' + bcId + '"]');
                const initialValue = input.value;
                
                // Show save button when value changes
                input.addEventListener('input', function() {
                    if (this.value !== initialValue && saveBtn) {
                        saveBtn.style.display = 'inline-block';
                    } else if (saveBtn) {
                        saveBtn.style.display = 'none';
                    }
                });
                
                // Save on button click
                if (saveBtn) {
                    saveBtn.addEventListener('click', function() {
                        const newSlug = input.value.trim();
                        const userId = input.dataset.userId;
                        
                        if (!newSlug) {
                            alert('スラッグを入力してください');
                            return;
                        }
                        
                        // Validate slug format (alphanumeric and hyphens only)
                        if (!/^[a-zA-Z0-9\-]+$/.test(newSlug)) {
                            alert('スラッグは英数字とハイフンのみ使用できます');
                            return;
                        }
                        
                        fetch('../backend/api/admin/update-url-slug.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            credentials: 'include',
                            body: JSON.stringify({
                                user_id: parseInt(userId),
                                business_card_id: parseInt(bcId),
                                url_slug: newSlug
                            })
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                saveBtn.style.display = 'none';
                                input.style.borderColor = '#28a745';
                                setTimeout(function() {
                                    input.style.borderColor = '#ddd';
                                }, 2000);
                            } else {
                                alert('エラー: ' + (result.message || '更新に失敗しました'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('エラーが発生しました');
                        });
                    });
                }
                
                // Save on Enter key
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && saveBtn) {
                        e.preventDefault();
                        saveBtn.click();
                    }
                });
            });
        })();
    </script>
    <script src="../assets/js/modal.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>


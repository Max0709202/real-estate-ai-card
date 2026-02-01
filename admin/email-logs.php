<?php
/**
 * Admin Email Logs Viewer
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

// フィルターとページネーション
$page = (int)($_GET['page'] ?? 1);
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if (!empty($_GET['recipient_email'])) {
    $where[] = "recipient_email LIKE ?";
    $params[] = '%' . $_GET['recipient_email'] . '%';
}

if (!empty($_GET['recipient_type'])) {
    $where[] = "recipient_type = ?";
    $params[] = $_GET['recipient_type'];
}

if (!empty($_GET['status'])) {
    $where[] = "status = ?";
    $params[] = $_GET['status'];
}

if (!empty($_GET['email_type'])) {
    $where[] = "email_type = ?";
    $params[] = $_GET['email_type'];
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ソート
$sortField = $_GET['sort'] ?? 'created_at';
$sortOrder = strtoupper($_GET['order'] ?? 'DESC');

$allowedSortFields = ['created_at', 'sent_at', 'recipient_email', 'status', 'email_type', 'delivery_time_ms', 'recipient_type'];
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'created_at';
}

if (!in_array($sortOrder, ['ASC', 'DESC'])) {
    $sortOrder = 'DESC';
}

$sql = "
    SELECT 
        id, recipient_email, recipient_type, subject, email_type, status,
        sent_at, started_at, completed_at, delivery_time_ms, 
        smtp_response, error_message, user_id, created_at
    FROM email_logs
    $whereClause
    ORDER BY $sortField $sortOrder
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$emailLogs = $stmt->fetchAll();

// 統計情報取得
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
        AVG(CASE WHEN status = 'sent' THEN delivery_time_ms ELSE NULL END) as avg_delivery_time,
        AVG(CASE WHEN recipient_type = 'business' AND status = 'sent' THEN delivery_time_ms ELSE NULL END) as avg_business_time,
        AVG(CASE WHEN recipient_type = 'personal' AND status = 'sent' THEN delivery_time_ms ELSE NULL END) as avg_personal_time
    FROM email_logs
    $whereClause
";
$statsParams = array_slice($params, 0, -2); // Remove limit and offset
$stmt = $db->prepare($statsSql);
$stmt->execute($statsParams);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メール送信ログ - 管理画面</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>メール送信ログ</h1>
            <div class="admin-info">
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボードに戻る</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
        </header>

        <div class="admin-content">
            <!-- 統計情報 -->
            <div class="filters" style="margin-bottom: 1rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div style="background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #666;">総送信数</h3>
                        <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #333;"><?php echo $stats['total']; ?></p>
                    </div>
                    <div style="background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #666;">成功</h3>
                        <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #28a745;"><?php echo $stats['sent_count']; ?></p>
                    </div>
                    <div style="background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #666;">失敗</h3>
                        <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #dc3545;"><?php echo $stats['failed_count']; ?></p>
                    </div>
                    <div style="background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #666;">平均送信時間</h3>
                        <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #333;">
                            <?php echo $stats['avg_delivery_time'] ? round($stats['avg_delivery_time'], 0) . 'ms' : 'N/A'; ?>
                        </p>
                    </div>
                    <div style="background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #666;">ビジネス平均</h3>
                        <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #0066cc;">
                            <?php echo $stats['avg_business_time'] ? round($stats['avg_business_time'], 0) . 'ms' : 'N/A'; ?>
                        </p>
                    </div>
                    <div style="background: #fff; padding: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin: 0 0 0.5rem 0; font-size: 0.9rem; color: #666;">個人平均</h3>
                        <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #0066cc;">
                            <?php echo $stats['avg_personal_time'] ? round($stats['avg_personal_time'], 0) . 'ms' : 'N/A'; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- フィルター -->
            <div class="filters">
                <form method="GET" class="filter-form">
                    <input type="text" name="recipient_email" placeholder="メールアドレス" class="recipient-email-input"
                           value="<?php echo htmlspecialchars($_GET['recipient_email'] ?? ''); ?>">
                    <select name="recipient_type">
                        <option value="">宛先タイプ</option>
                        <option value="business" <?php echo ($_GET['recipient_type'] ?? '') === 'business' ? 'selected' : ''; ?>>ビジネス</option>
                        <option value="personal" <?php echo ($_GET['recipient_type'] ?? '') === 'personal' ? 'selected' : ''; ?>>個人</option>
                    </select>
                    <select name="status">
                        <option value="">ステータス</option>
                        <option value="sent" <?php echo ($_GET['status'] ?? '') === 'sent' ? 'selected' : ''; ?>>送信成功</option>
                        <option value="failed" <?php echo ($_GET['status'] ?? '') === 'failed' ? 'selected' : ''; ?>>送信失敗</option>
                        <option value="pending" <?php echo ($_GET['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>送信待ち</option>
                    </select>
                    <select name="email_type">
                        <option value="">メールタイプ</option>
                        <option value="verification" <?php echo ($_GET['email_type'] ?? '') === 'verification' ? 'selected' : ''; ?>>認証</option>
                        <option value="verification_resend" <?php echo ($_GET['email_type'] ?? '') === 'verification_resend' ? 'selected' : ''; ?>>認証再送</option>
                        <option value="password_reset" <?php echo ($_GET['email_type'] ?? '') === 'password_reset' ? 'selected' : ''; ?>>パスワードリセット</option>
                        <option value="email_reset" <?php echo ($_GET['email_type'] ?? '') === 'email_reset' ? 'selected' : ''; ?>>メールアドレス変更</option>
                        <option value="admin_notification" <?php echo ($_GET['email_type'] ?? '') === 'admin_notification' ? 'selected' : ''; ?>>管理者通知</option>
                    </select>
                    <button type="submit" class="btn-filter">検索</button>
                    <a href="email-logs.php" class="btn-export">リセット</a>
                </form>
            </div>

            <!-- ログテーブル -->
            <table class="users-table">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="created_at">送信日時</th>
                        <th class="sortable" data-sort="recipient_email">宛先</th>
                        <th class="sortable" data-sort="recipient_type">タイプ</th>
                        <th>件名</th>
                        <th class="sortable" data-sort="email_type">メールタイプ</th>
                        <th class="sortable" data-sort="status">ステータス</th>
                        <th class="sortable" data-sort="delivery_time_ms">送信時間</th>
                        <th>エラー</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emailLogs as $index => $log): ?>
                    <tr class="<?php echo ($index % 2 === 0) ? 'even-row' : 'odd-row'; ?>">
                        <td data-label="送信日時">
                            <?php echo htmlspecialchars($log['sent_at'] ?? $log['created_at']); ?>
                        </td>
                        <td data-label="宛先"><?php echo htmlspecialchars($log['recipient_email']); ?></td>
                        <td data-label="タイプ">
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; 
                                background: <?php echo $log['recipient_type'] === 'business' ? '#0066cc' : '#28a745'; ?>; 
                                color: #fff;">
                                <?php echo $log['recipient_type'] === 'business' ? 'ビジネス' : ($log['recipient_type'] === 'personal' ? '個人' : '不明'); ?>
                            </span>
                        </td>
                        <td data-label="件名" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php echo htmlspecialchars($log['subject']); ?>
                        </td>
                        <td data-label="メールタイプ"><?php echo htmlspecialchars($log['email_type']); ?></td>
                        <td data-label="ステータス">
                            <?php if ($log['status'] === 'sent'): ?>
                                <span style="color: #28a745; font-weight: bold;">✓ 送信成功</span>
                            <?php elseif ($log['status'] === 'failed'): ?>
                                <span style="color: #dc3545; font-weight: bold;">✗ 送信失敗</span>
                            <?php else: ?>
                                <span style="color: #ffc107;">待機中</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="送信時間">
                            <?php if ($log['delivery_time_ms']): ?>
                                <span style="font-weight: bold; color: <?php echo $log['delivery_time_ms'] > 3000 ? '#dc3545' : ($log['delivery_time_ms'] > 1000 ? '#ffc107' : '#28a745'); ?>;">
                                    <?php echo number_format($log['delivery_time_ms'], 0); ?>ms
                                </span>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </td>
                        <td data-label="エラー" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;">
                            <?php if ($log['error_message']): ?>
                                <span style="color: #dc3545;" title="<?php echo htmlspecialchars($log['error_message']); ?>">
                                    <?php echo htmlspecialchars(substr($log['error_message'], 0, 50)); ?>...
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="../assets/js/admin.js"></script>
</body>
</html>


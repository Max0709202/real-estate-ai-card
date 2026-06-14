<?php
/**
 * Admin viewer for chatbot public-data API access logs.
 * Shows, per user question, which external/internal data providers were
 * actually invoked (不動産情報ライブラリ / 国交データプラットフォーム / e-Stat /
 * 全国マンションDB) and how many records were returned. This answers:
 *   ① 公的データAPIの呼び出しログを確認できるか
 *   ② どの質問でAPIを利用したかを確認できるか
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';
require_once __DIR__ . '/../backend/includes/chat-public-data-helper.php';

startSessionIfNotStarted();

// 管理者認証
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// ログテーブルが未作成でも落ちないように保証
ensureChatPublicDataAccessLogTable($db);

$providerLabels = [
    'reinfolib' => '国土交通省 不動産情報ライブラリ',
    'mlit_dpf'  => '国土交通データプラットフォーム',
    'estat'     => '政府統計の総合窓口 e-Stat',
    'mansion_db'=> '当社 全国マンションデータベース',
];

// フィルター
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;

$where = [];
$params = [];

if (!empty($_GET['provider']) && isset($providerLabels[$_GET['provider']])) {
    $where[] = "l.provider = ?";
    $params[] = $_GET['provider'];
}
if (!empty($_GET['keyword'])) {
    $where[] = "l.user_message LIKE ?";
    $params[] = '%' . $_GET['keyword'] . '%';
}
if (!empty($_GET['session_id'])) {
    $where[] = "l.session_id = ?";
    $params[] = $_GET['session_id'];
}
if (isset($_GET['cached']) && $_GET['cached'] !== '') {
    $where[] = "l.cached = ?";
    $params[] = (int)$_GET['cached'];
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// 統計情報
$statsSql = "
    SELECT
        COUNT(*) AS total,
        COUNT(DISTINCT l.session_id) AS sessions,
        SUM(CASE WHEN l.cached = 1 THEN 1 ELSE 0 END) AS cached_count,
        SUM(CASE WHEN l.cached = 0 THEN 1 ELSE 0 END) AS live_count
    FROM chat_public_data_access_log l
    $whereClause
";
$stmt = $db->prepare($statsSql);
Database::bindValues($stmt, $params);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'sessions' => 0, 'cached_count' => 0, 'live_count' => 0];

// プロバイダ別件数（フィルター適用）
$providerSql = "
    SELECT l.provider, COUNT(*) AS cnt
    FROM chat_public_data_access_log l
    $whereClause
    GROUP BY l.provider
    ORDER BY cnt DESC
";
$stmt = $db->prepare($providerSql);
Database::bindValues($stmt, $params);
$stmt->execute();
$providerCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$totalLogs = (int)($stats['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalLogs / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

// 明細取得（名刺名を結合）
$sql = "
    SELECT l.id, l.created_at, l.session_id, l.business_card_id, l.user_message,
           l.provider, l.record_count, l.total_count, l.cached, l.fetched_at,
           bc.name AS agent_name
    FROM chat_public_data_access_log l
    LEFT JOIN business_cards bc ON bc.id = l.business_card_id
    $whereClause
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT ? OFFSET ?
";
$queryParams = $params;
$queryParams[] = $limit;
$queryParams[] = $offset;
$stmt = $db->prepare($sql);
Database::bindValues($stmt, $queryParams);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$buildPaginationUrl = function ($pageNumber) {
    $query = $_GET;
    $query['page'] = max(1, (int)$pageNumber);
    return '?' . http_build_query($query);
};
$paginationStart = max(1, $page - 2);
$paginationEnd = min($totalPages, $page + 2);
if ($paginationEnd - $paginationStart < 4) {
    $paginationStart = max(1, min($paginationStart, $totalPages - 4));
    $paginationEnd = min($totalPages, max($paginationEnd, 5));
}

$providerColors = [
    'reinfolib'  => '#0066cc',
    'mlit_dpf'   => '#6f42c1',
    'estat'      => '#28a745',
    'mansion_db' => '#d97706',
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>チャットAPI利用ログ - 管理画面</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>チャットAPI利用ログ</h1>
            <div class="admin-info">
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボードに戻る</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
        </header>

        <div class="admin-content">
            <p style="color:#667085; font-size:0.9rem; margin:0 0 1rem;">
                チャットボットが各質問に回答する際、実際に呼び出した公的データAPI・社内DBと取得件数を記録しています。
                「どの質問でAPIを使ったか」「何件取得したか」をここで確認できます。
            </p>

            <!-- 統計情報 -->
            <div class="filters" style="margin-bottom: 1rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                    <div style="background:#fff; padding:1rem; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin:0 0 0.5rem; font-size:0.9rem; color:#666;">総API利用回数</h3>
                        <p style="margin:0; font-size:1.5rem; font-weight:bold; color:#333;"><?php echo number_format($totalLogs); ?></p>
                    </div>
                    <div style="background:#fff; padding:1rem; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin:0 0 0.5rem; font-size:0.9rem; color:#666;">対象セッション数</h3>
                        <p style="margin:0; font-size:1.5rem; font-weight:bold; color:#333;"><?php echo number_format((int)$stats['sessions']); ?></p>
                    </div>
                    <div style="background:#fff; padding:1rem; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin:0 0 0.5rem; font-size:0.9rem; color:#666;">最新取得</h3>
                        <p style="margin:0; font-size:1.5rem; font-weight:bold; color:#28a745;"><?php echo number_format((int)$stats['live_count']); ?></p>
                    </div>
                    <div style="background:#fff; padding:1rem; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin:0 0 0.5rem; font-size:0.9rem; color:#666;">キャッシュ利用</h3>
                        <p style="margin:0; font-size:1.5rem; font-weight:bold; color:#0066cc;"><?php echo number_format((int)$stats['cached_count']); ?></p>
                    </div>
                </div>
                <?php if (!empty($providerCounts)): ?>
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:1rem;">
                    <?php foreach ($providerCounts as $prov => $cnt): ?>
                        <span style="padding:0.35rem 0.7rem; border-radius:999px; font-size:0.8rem; color:#fff; background:<?php echo $providerColors[$prov] ?? '#667085'; ?>;">
                            <?php echo htmlspecialchars($providerLabels[$prov] ?? $prov); ?>：<?php echo number_format((int)$cnt); ?>件
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- フィルター -->
            <div class="filters">
                <form method="GET" class="filter-form">
                    <input type="text" name="keyword" placeholder="質問内容で検索"
                           value="<?php echo htmlspecialchars($_GET['keyword'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <select name="provider">
                        <option value="">データ取得元</option>
                        <?php foreach ($providerLabels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($_GET['provider'] ?? '') === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="cached">
                        <option value="">取得方法</option>
                        <option value="0" <?php echo (isset($_GET['cached']) && $_GET['cached'] === '0') ? 'selected' : ''; ?>>最新取得</option>
                        <option value="1" <?php echo (isset($_GET['cached']) && $_GET['cached'] === '1') ? 'selected' : ''; ?>>キャッシュ</option>
                    </select>
                    <input type="text" name="session_id" placeholder="セッションID"
                           value="<?php echo htmlspecialchars($_GET['session_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn-filter">検索</button>
                    <a href="chat-api-logs.php" class="btn-export">リセット</a>
                </form>
            </div>

            <!-- ログテーブル -->
            <table class="users-table">
                <thead>
                    <tr>
                        <th>取得日時</th>
                        <th>質問内容</th>
                        <th>データ取得元</th>
                        <th>取得件数</th>
                        <th>取得方法</th>
                        <th>担当名刺</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:2rem; color:#999;">該当するログはありません。</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $index => $log): ?>
                    <tr class="<?php echo ($index % 2 === 0) ? 'even-row' : 'odd-row'; ?>">
                        <td data-label="取得日時"><?php echo htmlspecialchars($log['created_at']); ?></td>
                        <td data-label="質問内容" style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                            title="<?php echo htmlspecialchars((string)$log['user_message'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(mb_strimwidth((string)$log['user_message'], 0, 90, '…', 'UTF-8')); ?>
                        </td>
                        <td data-label="データ取得元">
                            <span style="padding:0.25rem 0.5rem; border-radius:4px; font-size:0.82rem; color:#fff;
                                background:<?php echo $providerColors[$log['provider']] ?? '#667085'; ?>;">
                                <?php echo htmlspecialchars($providerLabels[$log['provider']] ?? $log['provider']); ?>
                            </span>
                        </td>
                        <td data-label="取得件数">
                            <?php
                                $rc = $log['record_count'] !== null ? (int)$log['record_count'] : null;
                                $tc = $log['total_count'] !== null ? (int)$log['total_count'] : null;
                                if ($tc !== null && $rc !== null && $tc > $rc) {
                                    echo '該当 ' . number_format($tc) . '件<br><span style="color:#888; font-size:0.8rem;">（参照 ' . number_format($rc) . '件）</span>';
                                } elseif ($tc !== null) {
                                    echo number_format($tc) . '件';
                                } elseif ($rc !== null) {
                                    echo number_format($rc) . '件';
                                } else {
                                    echo '-';
                                }
                            ?>
                        </td>
                        <td data-label="取得方法">
                            <?php if ((int)$log['cached'] === 1): ?>
                                <span style="color:#0066cc;">キャッシュ</span>
                            <?php else: ?>
                                <span style="color:#28a745; font-weight:bold;">最新取得</span>
                            <?php endif; ?>
                            <?php if (!empty($log['fetched_at'])): ?>
                                <br><span style="color:#888; font-size:0.8rem;"><?php echo htmlspecialchars(mb_substr((string)$log['fetched_at'], 0, 16)); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="担当名刺">
                            <?php
                                $agent = trim((string)($log['agent_name'] ?? ''));
                                echo $agent !== '' ? htmlspecialchars($agent) : '<span style="color:#999;">-</span>';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <?php
                    $firstItem = $totalLogs === 0 ? 0 : $offset + 1;
                    $lastItem = min($offset + $limit, $totalLogs);
                ?>
                <nav class="admin-pagination" aria-label="ページ送り">
                    <div class="admin-pagination-summary">
                        <?php echo number_format($totalLogs); ?>件中 <?php echo number_format($firstItem); ?>-<?php echo number_format($lastItem); ?>件を表示
                    </div>
                    <div class="admin-pagination-links">
                        <?php if ($page > 1): ?>
                            <a class="admin-pagination-link" href="<?php echo htmlspecialchars($buildPaginationUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>">前へ</a>
                        <?php else: ?>
                            <span class="admin-pagination-link is-disabled" aria-disabled="true">前へ</span>
                        <?php endif; ?>

                        <?php if ($paginationStart > 1): ?>
                            <a class="admin-pagination-link" href="<?php echo htmlspecialchars($buildPaginationUrl(1), ENT_QUOTES, 'UTF-8'); ?>">1</a>
                            <?php if ($paginationStart > 2): ?><span class="admin-pagination-ellipsis">...</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($pageNumber = $paginationStart; $pageNumber <= $paginationEnd; $pageNumber++): ?>
                            <?php if ($pageNumber === $page): ?>
                                <span class="admin-pagination-link is-current" aria-current="page"><?php echo $pageNumber; ?></span>
                            <?php else: ?>
                                <a class="admin-pagination-link" href="<?php echo htmlspecialchars($buildPaginationUrl($pageNumber), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $pageNumber; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($paginationEnd < $totalPages): ?>
                            <?php if ($paginationEnd < $totalPages - 1): ?><span class="admin-pagination-ellipsis">...</span><?php endif; ?>
                            <a class="admin-pagination-link" href="<?php echo htmlspecialchars($buildPaginationUrl($totalPages), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $totalPages; ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a class="admin-pagination-link" href="<?php echo htmlspecialchars($buildPaginationUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>">次へ</a>
                        <?php else: ?>
                            <span class="admin-pagination-link is-disabled" aria-disabled="true">次へ</span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <script src="../assets/js/admin.js"></script>
</body>
</html>

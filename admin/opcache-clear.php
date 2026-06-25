<?php
/**
 * 管理者専用：PHP OPcache をクリアするユーティリティ。
 *
 * このサーバーは .php を更新しても OPcache が古いコンパイル済みコードを
 * 配信し続けることがある（特に www 系 / FPM）。デプロイ後にここから
 * OPcache をクリアすると、編集したPHPが即時反映される。
 *
 * セキュリティ：管理者ログイン必須＋admin ロール限定。実行は CSRF トークン付き POST のみ。
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

// admin ロール限定（初期管理者 ID=1 も許可）
$stmt = $db->prepare('SELECT role FROM admins WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$role = (string) ($stmt->fetchColumn() ?: '');
$isAdmin = ($role === 'admin' || (int) $_SESSION['admin_id'] === 1);
if (!$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>権限がありません。</p>';
    exit();
}

// CSRF トークン
if (empty($_SESSION['opcache_clear_csrf'])) {
    $_SESSION['opcache_clear_csrf'] = generateToken(32);
}
$csrf = $_SESSION['opcache_clear_csrf'];

$result = null;
$resultOk = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals($csrf, $posted)) {
        $result = 'セッションが無効です。ページを再読み込みしてからもう一度お試しください。';
        $resultOk = false;
    } elseif (!function_exists('opcache_reset')) {
        $result = 'このサーバーでは OPcache が利用できません（opcache_reset 関数なし）。';
        $resultOk = false;
    } else {
        $ok = opcache_reset();
        $resultOk = ($ok === true);
        $result = $resultOk
            ? 'OPcache をクリアしました。更新した PHP が次のリクエストから反映されます。'
            : 'OPcache のクリアに失敗しました（この SAPI では無効化されている可能性があります）。';
    }
}

// 現在の OPcache 状態
$status = null;
$enabled = null;
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    $enabled = is_array($status) ? ($status['opcache_enabled'] ?? false) : false;
}
$validateTs = function_exists('ini_get') ? ini_get('opcache.validate_timestamps') : null;
$revalidate = function_exists('ini_get') ? ini_get('opcache.revalidate_freq') : null;

function ocFmtBool($v) { return $v ? 'はい' : 'いいえ'; }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>OPcache クリア（管理者）</title>
<style>
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; color: #1f2d3d; }
    h1 { font-size: 1.3rem; }
    .card { border: 1px solid #dfe8f4; border-radius: 10px; padding: 1rem 1.2rem; background: #f8fbff; margin: 1rem 0; }
    table { border-collapse: collapse; width: 100%; font-size: 0.92rem; }
    th, td { text-align: left; padding: 0.35rem 0.5rem; border-bottom: 1px solid #e7edf8; }
    th { width: 45%; color: #54627a; }
    button { background: #0757d7; color: #fff; border: 0; border-radius: 8px; padding: 0.7rem 1.2rem; font-size: 1rem; font-weight: 700; cursor: pointer; }
    button:hover { background: #0044bd; }
    .msg { padding: 0.7rem 1rem; border-radius: 8px; margin: 1rem 0; font-weight: 600; }
    .msg.ok { background: #e6f7ee; color: #06794a; border: 1px solid #b8e6cf; }
    .msg.ng { background: #fdecec; color: #c0392b; border: 1px solid #f3c2bd; }
    .note { color: #7a8699; font-size: 0.86rem; line-height: 1.6; }
    a { color: #0757d7; }
</style>
</head>
<body>
    <h1>PHP OPcache クリア</h1>

    <?php if ($result !== null): ?>
        <div class="msg <?php echo $resultOk ? 'ok' : 'ng'; ?>"><?php echo htmlspecialchars($result); ?></div>
    <?php endif; ?>

    <div class="card">
        <table>
            <tr><th>OPcache 有効</th><td><?php echo $status === null ? '不明（拡張なし）' : ocFmtBool($enabled); ?></td></tr>
            <tr><th>validate_timestamps</th><td><?php echo htmlspecialchars((string) $validateTs); ?>（0 の場合、ファイルを更新しても自動反映されません）</td></tr>
            <tr><th>revalidate_freq（秒）</th><td><?php echo htmlspecialchars((string) $revalidate); ?></td></tr>
            <?php if (is_array($status) && !empty($status['opcache_statistics'])): ?>
                <tr><th>キャッシュ済みスクリプト数</th><td><?php echo (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0); ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <button type="submit">OPcache をクリアする</button>
    </form>

    <p class="note">
        PHP ファイル（<code>.php</code>）を更新したあと、変更がサイトに反映されない場合にここからクリアしてください。<br>
        JS / CSS は <code>?v=ファイル更新時刻</code> で自動更新されるため、通常このクリアは不要です。
    </p>
    <p class="note"><a href="dashboard.php">← 管理ダッシュボードに戻る</a></p>
</body>
</html>

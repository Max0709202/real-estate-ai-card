<?php
/**
 * 情報BOX設定ページ（運営専用）
 * ----------------------------
 * 会社（宅建業免許番号）ごとに、情報BOX機能の ON / OFF を切り替える。
 * 階層分け機能（組織階層設定）と同じく会社単位で判定する。
 *
 * OFF の会社では、マイページに「情報BOX」が表示されず、APIも拒否する。
 * OFF にしても作成済みの BOX・書類・台帳は消さない（ON に戻せばそのまま使える）。
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';
require_once __DIR__ . '/../backend/includes/infobox-helper.php';

startSessionIfNotStarted();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT id, email, role FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
$canEdit = ($currentAdmin && ((int)$currentAdmin['id'] === 1 || $currentAdmin['role'] === 'admin'));

$keyword = trim((string)($_GET['keyword'] ?? ''));
$settings = iboxFetchLicenseSettings($db);

$companies = [];
try {
    $rows = $db->query("
        SELECT u.id AS user_id,
               bc.company_name,
               bc.real_estate_license_prefecture,
               bc.real_estate_license_renewal_number,
               bc.real_estate_license_registration_number,
               CASE WHEN EXISTS (
                   SELECT 1 FROM business_cards active_bc
                   WHERE active_bc.user_id = u.id
                     AND active_bc.payment_status IN ('CR', 'BANK_PAID', 'ST')
                     AND active_bc.is_published = 1
               ) THEN 1 ELSE 0 END AS is_active
        FROM users u
        JOIN (SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id) first_card ON first_card.user_id = u.id
        JOIN business_cards bc ON bc.id = first_card.id
        WHERE bc.real_estate_license_registration_number IS NOT NULL
          AND bc.real_estate_license_registration_number <> ''
        ORDER BY u.id ASC
        LIMIT 5000
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('infobox settings company list error: ' . $e->getMessage());
    $rows = [];
}

foreach ($rows as $row) {
    $parts = orgLicenseParts($row['real_estate_license_prefecture'] ?? '', $row['real_estate_license_registration_number'] ?? '');
    if ($parts['key'] === '') continue;
    $key = $parts['key'];
    if (!isset($companies[$key])) {
        $renewal = trim((string)($row['real_estate_license_renewal_number'] ?? ''));
        $companies[$key] = [
            'license_key' => $key,
            'license_text' => trim((string)$row['real_estate_license_prefecture']) . ($renewal !== '' ? '（' . $renewal . '）' : '') . '第' . trim((string)$row['real_estate_license_registration_number']) . '号',
            'company_name' => '',
            'member_count' => 0,
            'active_count' => 0,
            'enabled' => $settings[$key]['enabled'] ?? false,
        ];
    }
    $companies[$key]['member_count']++;
    if ((int)$row['is_active'] === 1) $companies[$key]['active_count']++;
    if ($companies[$key]['company_name'] === '') $companies[$key]['company_name'] = trim((string)($row['company_name'] ?? ''));
}

if ($keyword !== '') {
    $companies = array_filter($companies, function ($c) use ($keyword) {
        return mb_stripos($c['company_name'], $keyword) !== false || mb_stripos($c['license_text'], $keyword) !== false;
    });
}

uasort($companies, function ($a, $b) {
    if ($a['enabled'] !== $b['enabled']) return $a['enabled'] ? -1 : 1;
    if ($a['active_count'] !== $b['active_count']) return $b['active_count'] <=> $a['active_count'];
    return strcmp($a['license_text'], $b['license_text']);
});

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <title>情報BOX設定 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
    <style>
        .ibox-container { padding: 20px; }
        .ibox-note { color: #666; font-size: 13px; line-height: 1.7; }
        .ibox-panel { margin-bottom: 24px; padding: 16px; background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; }
        .ibox-panel h3 { margin-top: 0; }
        .ibox-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin: 12px 0; }
        .ibox-toolbar input[type="text"] { padding: 7px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .ibox-btn { padding: 8px 16px; border: none; border-radius: 4px; background: #0066cc; color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; }
        .ibox-btn-secondary { background: #6c757d; }
        .ibox-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .ibox-table thead { background: #0066cc; color: #fff; }
        .ibox-table th { padding: 12px; text-align: left; font-size: 13px; }
        .ibox-table td { padding: 12px; border-bottom: 1px solid #e0e0e0; font-size: 14px; }
        .ibox-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; color: #fff; }
        .ibox-on { background: #28a745; }
        .ibox-off { background: #adb5bd; }
        .message { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>情報BOX設定</h1>
            <div class="admin-info">
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボードへ戻る</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
        </header>
        <div class="admin-content">
            <div class="ibox-container">
                <div id="message-container"></div>
                <div class="ibox-panel">
                    <h3>情報BOX機能の ON / OFF（会社ごと）</h3>
                    <p class="ibox-note">
                        会社（宅建業免許番号）ごとに、情報BOX（取引ごとの書類共有・関係者連絡・原本管理・取引台帳）を使えるかどうかを切り替えます。<br>
                        <strong>OFF の会社では、マイページに「情報BOX」が表示されません</strong>（URLを直接開いてもご利用いただけません）。<br>
                        OFF にしても作成済みの情報BOX・書類・取引台帳は消えません。ON に戻せばそのままご利用いただけます。<br>
                        <strong>既定は OFF です。</strong>会社の判定は免許番号で行うため、会社名の表記ゆれの影響は受けません。
                    </p>
                    <form method="GET" class="ibox-toolbar">
                        <div><input type="text" name="keyword" value="<?php echo $h($keyword); ?>" placeholder="会社名・免許番号"></div>
                        <div>
                            <button type="submit" class="ibox-btn">検索</button>
                            <a href="infobox-settings.php" class="ibox-btn ibox-btn-secondary">クリア</a>
                        </div>
                    </form>
                    <table class="ibox-table">
                        <thead>
                            <tr>
                                <th>情報BOX</th>
                                <th>会社名</th>
                                <th>免許番号</th>
                                <th>登録人数</th>
                                <th>利用中<br>（入金済み・OPEN）</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($companies)): ?>
                            <tr><td colspan="5">該当する会社がありません。</td></tr>
                            <?php endif; ?>
                            <?php foreach ($companies as $company): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <?php if ($canEdit): ?>
                                    <input type="checkbox" class="ibox-toggle"
                                           data-license-key="<?php echo $h($company['license_key']); ?>"
                                           data-license-text="<?php echo $h($company['license_text']); ?>"
                                           data-company-name="<?php echo $h($company['company_name']); ?>"
                                           <?php echo $company['enabled'] ? 'checked' : ''; ?>
                                           title="情報BOX機能の ON / OFF">
                                    <?php endif; ?>
                                    <span class="ibox-badge <?php echo $company['enabled'] ? 'ibox-on' : 'ibox-off'; ?>"><?php echo $company['enabled'] ? 'ON' : 'OFF'; ?></span>
                                </td>
                                <td><?php echo $h($company['company_name'] !== '' ? $company['company_name'] : '（会社名未登録）'); ?></td>
                                <td style="white-space: nowrap; font-size: 13px;"><?php echo $h($company['license_text']); ?></td>
                                <td><?php echo (int)$company['member_count']; ?>名</td>
                                <td><?php echo (int)$company['active_count']; ?>名</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$canEdit): ?>
                    <p class="ibox-note">切り替えは管理者ロールのみ実行できます。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var messageContainer = document.getElementById('message-container');
            function showMessage(text, isError) {
                messageContainer.innerHTML = '';
                var div = document.createElement('div');
                div.className = 'message ' + (isError ? 'message-error' : 'message-success');
                div.textContent = text;
                messageContainer.appendChild(div);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            document.querySelectorAll('.ibox-toggle').forEach(function (toggle) {
                toggle.addEventListener('change', function () {
                    var self = this;
                    var enabled = self.checked;
                    var label = self.getAttribute('data-company-name') || self.getAttribute('data-license-text') || 'この会社';
                    var confirmText = enabled
                        ? label + ' の情報BOX機能をONにします。\nマイページに「情報BOX」が表示されるようになります。'
                        : label + ' の情報BOX機能をOFFにします。\nマイページから「情報BOX」が非表示になります。\n（作成済みのデータは消えません）';
                    if (!confirm(confirmText)) { self.checked = !enabled; return; }
                    self.disabled = true;
                    fetch('../backend/api/admin/update-infobox-plan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            license_key: self.getAttribute('data-license-key'),
                            license_text: self.getAttribute('data-license-text') || '',
                            company_name: self.getAttribute('data-company-name') || '',
                            enabled: enabled
                        })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (result) {
                            self.disabled = false;
                            if (!result.success) self.checked = !enabled;
                            var badge = self.parentNode.querySelector('.ibox-badge');
                            if (result.success && badge) {
                                badge.textContent = enabled ? 'ON' : 'OFF';
                                badge.className = 'ibox-badge ' + (enabled ? 'ibox-on' : 'ibox-off');
                            }
                            showMessage(result.message || (result.success ? '更新しました。' : '更新に失敗しました'), !result.success);
                        })
                        .catch(function () {
                            self.disabled = false;
                            self.checked = !enabled;
                            showMessage('エラーが発生しました', true);
                        });
                });
            });
        })();
    </script>
</body>
</html>

<?php
/**
 * 組織階層設定ページ
 * ------------------
 * 統括（全閲覧）→ マネージャー（店長）→ 担当者（営業）の3階層を、
 * ユーザーごとの「権限」と「上長」で設定する。
 *
 * 階層分けは法人プランの機能のため、会社（免許番号）ごとの ON / OFF もこの画面で行う。
 * OFF の会社では、マイページに「組織・配下顧客」が出ない（APIも拒否する）。
 *
 * 通常の運用では、統括（全閲覧）の指名は admin/dashboard.php の名前の前の☑で行う。
 * この画面は、まとめて確認・修正したい場合とCSVでの一括設定のために残している。
 *
 * 同一会社の判定は会社名ではなく宅建業免許番号（都道府県＋登録番号）で行うため、
 * 一覧にも免許番号を表示している。
 *
 * ここで設定した内容は、マイページの「組織・配下顧客」で
 * 上長が自社メンバーと顧客を “閲覧するため” にだけ使われる。
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';
require_once __DIR__ . '/../backend/includes/org-hierarchy-helper.php';

startSessionIfNotStarted();

// 管理者認証
if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
orgEnsureUserColumns($db);

$stmt = $db->prepare("SELECT id, email, role FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

// クライアントロールは閲覧のみ（既存APIと同じ考え方）。
$canEdit = ($currentAdmin && ((int)$currentAdmin['id'] === 1 || $currentAdmin['role'] === 'admin'));

// 検索条件
$keyword = trim((string)($_GET['keyword'] ?? ''));
$roleFilter = (string)($_GET['org_role'] ?? '');
if (!in_array($roleFilter, ORG_ROLES, true)) {
    $roleFilter = '';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(u.email LIKE ? OR bc.name LIKE ? OR bc.company_name LIKE ? OR bc.branch_department LIKE ? OR bc.real_estate_license_registration_number LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($roleFilter !== '') {
    $where[] = 'u.org_role = ?';
    $params[] = $roleFilter;
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT u.id,
           u.email,
           u.status,
           u.org_role,
           u.parent_user_id,
           bc.name AS member_name,
           bc.company_name,
           bc.branch_department,
           bc.position,
           bc.real_estate_license_prefecture,
           bc.real_estate_license_renewal_number,
           bc.real_estate_license_registration_number
    FROM users u
    LEFT JOIN (
        SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
    ) first_card ON first_card.user_id = u.id
    LEFT JOIN business_cards bc ON bc.id = first_card.id
    $whereClause
    ORDER BY u.id ASC
    LIMIT 500
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 上長として選べるのはマネージャー・管理者のみ。
// ただし、権限を担当者へ戻した後も上長として残っている人は候補に含める。
// 含めないと、その配下の行で現在の上長が選択肢に無く「（なし）」に見えてしまうため。
$supervisorStmt = $db->query("
    SELECT u.id, u.email, u.org_role, bc.name AS member_name, bc.company_name
    FROM users u
    LEFT JOIN (
        SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
    ) first_card ON first_card.user_id = u.id
    LEFT JOIN business_cards bc ON bc.id = first_card.id
    WHERE u.org_role IN ('manager', 'admin')
       OR u.id IN (SELECT parent.parent_user_id FROM users parent WHERE parent.parent_user_id IS NOT NULL)
    ORDER BY u.org_role DESC, u.id ASC
");
$supervisors = $supervisorStmt->fetchAll(PDO::FETCH_ASSOC);

// 会社（宅建業免許番号）ごとの階層機能 ON/OFF 一覧。
// 免許番号の正規化は orgLicenseParts() に任せ、マイページ側の判定とズレないようにする。
$licenseSettings = orgFetchLicenseSettings($db);
$licenseCompanies = [];
try {
    $companyRows = $db->query("
        SELECT u.id AS user_id,
               u.email,
               u.org_role,
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
        JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) first_card ON first_card.user_id = u.id
        JOIN business_cards bc ON bc.id = first_card.id
        WHERE bc.real_estate_license_registration_number IS NOT NULL
          AND bc.real_estate_license_registration_number <> ''
        ORDER BY u.id ASC
        LIMIT 5000
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('org license company list error: ' . $e->getMessage());
    $companyRows = [];
}

foreach ($companyRows as $companyRow) {
    $licenseParts = orgLicenseParts(
        $companyRow['real_estate_license_prefecture'] ?? '',
        $companyRow['real_estate_license_registration_number'] ?? ''
    );
    if ($licenseParts['key'] === '') continue;

    $licenseKey = $licenseParts['key'];
    if (!isset($licenseCompanies[$licenseKey])) {
        $licenseCompanies[$licenseKey] = [
            'license_key' => $licenseKey,
            'license_text' => orgAdminLicenseText($companyRow),
            'company_name' => '',
            'member_count' => 0,
            'active_count' => 0,
            'admin_count' => 0,
            // 登録済みの「統括のログインメール」。判定にも使う値。
            'admin_email' => $licenseSettings[$licenseKey]['admin_email'] ?? '',
            // 実際に統括に指名されている方のメール。入力の目安として出す。
            'detected_admin_email' => '',
            'enabled' => $licenseSettings[$licenseKey]['hierarchy_enabled'] ?? false,
        ];
    }

    $licenseCompanies[$licenseKey]['member_count']++;
    if ((int)$companyRow['is_active'] === 1) $licenseCompanies[$licenseKey]['active_count']++;
    if (orgNormalizeRole($companyRow['org_role'] ?? 'staff') === 'admin') {
        $licenseCompanies[$licenseKey]['admin_count']++;
        if ($licenseCompanies[$licenseKey]['detected_admin_email'] === '') {
            $licenseCompanies[$licenseKey]['detected_admin_email'] = trim((string)($companyRow['email'] ?? ''));
        }
    }
    // 会社名は確認用。最初に見つかった空でない値を採用する。
    if ($licenseCompanies[$licenseKey]['company_name'] === '') {
        $licenseCompanies[$licenseKey]['company_name'] = trim((string)($companyRow['company_name'] ?? ''));
    }
}

// ON の会社を上に、その次は利用中の人数が多い順に並べる。
uasort($licenseCompanies, function ($a, $b) {
    if ($a['enabled'] !== $b['enabled']) return $a['enabled'] ? -1 : 1;
    if ($a['active_count'] !== $b['active_count']) return $b['active_count'] <=> $a['active_count'];
    return strcmp($a['license_text'], $b['license_text']);
});

/** 一覧に出す免許番号（例：東京都知事（3）第12345号）。未登録なら空文字。 */
function orgAdminLicenseText(array $row): string
{
    $prefecture = trim((string)($row['real_estate_license_prefecture'] ?? ''));
    $number = trim((string)($row['real_estate_license_registration_number'] ?? ''));
    if ($prefecture === '' || $number === '') return '';

    $renewal = trim((string)($row['real_estate_license_renewal_number'] ?? ''));
    return $prefecture . ($renewal !== '' ? '（' . $renewal . '）' : '') . '第' . $number . '号';
}

/** 一覧・選択肢で使う表示名。名刺が未作成ならメールアドレスで代用する。 */
function orgAdminDisplayName(array $row): string
{
    $name = trim((string)($row['member_name'] ?? ''));
    return $name !== '' ? $name : (string)($row['email'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover, interactive-widget=resizes-content">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>組織階層設定 - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/admin-mobile.css">
    <style>
        .org-container { padding: 20px; }
        .org-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .org-toolbar label { display: block; font-size: 12px; color: #555; margin-bottom: 4px; }
        .org-toolbar input[type="text"],
        .org-toolbar select {
            padding: 7px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .org-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            background: #0066cc;
            color: #fff;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .org-btn-secondary { background: #6c757d; }
        .org-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .org-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .org-table thead { background: #0066cc; color: #fff; }
        .org-table th { padding: 12px; text-align: left; font-weight: bold; font-size: 13px; }
        .org-table td { padding: 12px; border-bottom: 1px solid #e0e0e0; font-size: 14px; }
        .org-table tbody tr:hover { background: #f5f5f5; }
        .org-select {
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            max-width: 260px;
        }
        .org-role-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            color: #fff;
        }
        .org-role-admin { background: #dc3545; }
        .org-role-manager { background: #0066cc; }
        .org-role-staff { background: #6c757d; }
        .org-plan-panel {
            margin-bottom: 24px;
            padding: 16px;
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 6px;
        }
        .org-plan-panel h3 { margin-top: 0; }
        .org-plan-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            color: #fff;
        }
        .org-plan-on { background: #28a745; }
        .org-plan-off { background: #adb5bd; }
        .org-csv-panel {
            margin-top: 24px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .org-csv-panel h3 { margin-top: 0; }
        .org-csv-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .org-note { color: #666; font-size: 13px; line-height: 1.7; }
        .message { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .org-import-log {
            margin-top: 10px;
            max-height: 200px;
            overflow-y: auto;
            font-size: 13px;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <header class="admin-header">
            <h1>組織階層設定</h1>
            <div class="admin-info">
                <p>現在のロール: <strong><?php echo ($currentAdmin['role'] ?? '') === 'admin' ? '管理者' : 'クライアント'; ?></strong></p>
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボードへ戻る</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
        </header>

        <div class="admin-content">
            <div class="org-container">
                <div id="message-container"></div>

                <p class="org-note">
                    <strong>この画面は運営専用です。</strong>全社のユーザーが表示されるため、各社の統括の方には公開しません
                    （管理画面は <code>admins</code> テーブルのログインが必要で、各社のユーザーアカウントでは入れません）。<br>
                    <strong>統括（全閲覧）の指名は、通常はダッシュボードの「名前」欄の☑で行います。</strong>この画面はまとめて確認・修正したいときにお使いください。<br>
                    自社かどうかの判定は<strong>宅建業免許番号（都道府県＋登録番号）</strong>で行います（会社名の表記ゆれの影響を受けないため）。
                    更新回数は免許更新のたびに変わるため、判定には使いません。<br>
                    その免許番号で最初に登録した方は、免許番号の初回入力時に自動で<strong>統括（全閲覧）</strong>になります。以降の方は<strong>担当者（営業）</strong>です。<br>
                    統括（全閲覧）は<strong>同じ免許番号のメンバー全員</strong>を、マネージャー（店長）は<strong>自分の配下だけ</strong>を閲覧できます。
                    いずれも<strong>閲覧のみ</strong>で、顧客情報の編集・削除はできません。<br>
                    マイページの一覧に出るのは<strong>入金済み（CR／振込済／ST送金）かつ OPEN</strong> の方のみです。<br>
                    権限を「担当者」へ戻しても配下の紐付けは自動では外れません。配下がいる方を担当者に戻す場合は、配下の「上長」も付け替えてください。
                </p>

                <div class="org-plan-panel">
                    <h3>法人プラン：階層分け機能の ON / OFF</h3>
                    <p class="org-note">
                        会社（宅建業免許番号）ごとに、階層分け機能を使えるかどうかを切り替えます。<br>
                        <strong>OFF の会社では、マイページに「組織・配下顧客」が表示されません</strong>（URLを直接開いてもエラーになります）。<br>
                        OFF にしても統括・店長・配下の設定は消えません。ON に戻せば、そのままの階層でご利用いただけます。<br>
                        <strong>既定は OFF です。</strong>法人プランをご契約いただいた会社だけを ON にしてください。<br>
                        会社の判定は免許番号で行うため、会社名の表記ゆれの影響は受けません。<br>
                        <strong>表示条件は「ON」と「ログインメールの登録」の両方（AND）です。</strong>
                        右の欄に登録したメールでログインした方だけに「組織・配下顧客」が表示されます。
                        統括の方だけでなく、<strong>店長の方のメールもここに追加してください</strong>（カンマまたは改行で複数登録できます）。<br>
                        <strong style="color:#c00;">ONにしてもメールが未登録の会社は、誰にも表示されません。</strong>
                    </p>
                    <table class="org-table">
                        <thead>
                            <tr>
                                <th>階層分け</th>
                                <th>会社名</th>
                                <th>免許番号</th>
                                <th>利用できるログインメール<br>（統括・店長。複数可）</th>
                                <th>登録人数</th>
                                <th>利用中<br>（入金済み・OPEN）</th>
                                <th>統括（全閲覧）</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($licenseCompanies)): ?>
                            <tr><td colspan="7">免許番号が登録されている会社がまだありません。</td></tr>
                            <?php endif; ?>
                            <?php foreach ($licenseCompanies as $company): ?>
                            <tr>
                                <td style="white-space: nowrap;">
                                    <?php if ($canEdit): ?>
                                    <input type="checkbox" class="org-plan-toggle"
                                           data-license-key="<?php echo htmlspecialchars($company['license_key'], ENT_QUOTES, 'UTF-8'); ?>"
                                           data-license-text="<?php echo htmlspecialchars($company['license_text'], ENT_QUOTES, 'UTF-8'); ?>"
                                           data-company-name="<?php echo htmlspecialchars($company['company_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                           <?php echo $company['enabled'] ? 'checked' : ''; ?>
                                           title="階層分け機能の ON / OFF">
                                    <?php endif; ?>
                                    <span class="org-plan-badge <?php echo $company['enabled'] ? 'org-plan-on' : 'org-plan-off'; ?>"><?php echo $company['enabled'] ? 'ON' : 'OFF'; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($company['company_name'] !== '' ? $company['company_name'] : '（会社名未登録）', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="white-space: nowrap; font-size: 13px;"><?php echo htmlspecialchars($company['license_text'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                    <textarea class="org-plan-email org-select" rows="2"
                                              data-license-key="<?php echo htmlspecialchars($company['license_key'], ENT_QUOTES, 'UTF-8'); ?>"
                                              placeholder="<?php echo htmlspecialchars($company['detected_admin_email'] !== '' ? $company['detected_admin_email'] : 'aaa@example.jp, bbb@example.jp', ENT_QUOTES, 'UTF-8'); ?>"
                                              style="width: 260px; max-width: 260px;"
                                              title="ここに登録したメールでログインした方だけに表示されます（カンマまたは改行で複数可）"><?php echo htmlspecialchars($company['admin_email'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <?php else: ?>
                                    <?php echo htmlspecialchars($company['admin_email'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php endif; ?>
                                    <?php if ($company['enabled'] && trim($company['admin_email']) === ''): ?>
                                        <div style="font-size: 11px; color: #c00; margin-top: 3px;">
                                            メール未登録のため、この会社では誰にも表示されません
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($company['detected_admin_email'] !== '' && strpos($company['admin_email'], $company['detected_admin_email']) === false): ?>
                                        <div style="font-size: 11px; color: #666; margin-top: 3px;">
                                            指名済みの統括：<?php echo htmlspecialchars($company['detected_admin_email'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$company['member_count']; ?>名</td>
                                <td><?php echo (int)$company['active_count']; ?>名</td>
                                <td>
                                    <?php if ((int)$company['admin_count'] > 0): ?>
                                        <?php echo (int)$company['admin_count']; ?>名
                                    <?php else: ?>
                                        <span style="color:#c00;">未指名</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="GET" class="org-toolbar">
                    <div>
                        <label for="keyword">氏名・メール・会社名・部署</label>
                        <input type="text" id="keyword" name="keyword" value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="キーワード">
                    </div>
                    <div>
                        <label for="org_role">権限</label>
                        <select id="org_role" name="org_role">
                            <option value="">すべて</option>
                            <?php foreach (ORG_ROLES as $role): ?>
                                <option value="<?php echo $role; ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(orgRoleLabel($role), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="org-btn">検索</button>
                        <a href="org-hierarchy.php" class="org-btn org-btn-secondary">クリア</a>
                    </div>
                </form>

                <table class="org-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>氏名</th>
                            <th>会社名 / 部署</th>
                            <th>免許番号</th>
                            <th>メールアドレス</th>
                            <th>権限</th>
                            <th>上長</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr><td colspan="7">該当するユーザーがいません。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($users as $user): ?>
                        <?php $userRole = orgNormalizeRole($user['org_role'] ?? 'staff'); ?>
                        <tr>
                            <td><?php echo (int)$user['id']; ?></td>
                            <td><?php echo htmlspecialchars(orgAdminDisplayName($user), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php echo htmlspecialchars((string)($user['company_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($user['branch_department'])): ?>
                                    <br><span style="color:#666; font-size:12px;"><?php echo htmlspecialchars((string)$user['branch_department'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </td>
                            <?php $licenseText = orgAdminLicenseText($user); ?>
                            <td style="white-space: nowrap; font-size: 13px;">
                                <?php echo $licenseText !== '' ? htmlspecialchars($licenseText, ENT_QUOTES, 'UTF-8') : '<span style="color:#c00;">未登録</span>'; ?>
                            </td>
                            <td><?php echo htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                <select class="org-select org-role-select" data-user-id="<?php echo (int)$user['id']; ?>" data-current="<?php echo $userRole; ?>">
                                    <?php foreach (ORG_ROLES as $role): ?>
                                    <option value="<?php echo $role; ?>" <?php echo $userRole === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(orgRoleLabel($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <span class="org-role-badge org-role-<?php echo $userRole; ?>"><?php echo htmlspecialchars(orgRoleLabel($userRole), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canEdit): ?>
                                <select class="org-select org-parent-select" data-user-id="<?php echo (int)$user['id']; ?>" data-current="<?php echo $user['parent_user_id'] !== null ? (int)$user['parent_user_id'] : ''; ?>">
                                    <option value="">（なし）</option>
                                    <?php foreach ($supervisors as $supervisor): ?>
                                        <?php // 自分自身は上長にできないので候補から外す ?>
                                        <?php if ((int)$supervisor['id'] !== (int)$user['id']): ?>
                                    <option value="<?php echo (int)$supervisor['id']; ?>" <?php echo (int)($user['parent_user_id'] ?? 0) === (int)$supervisor['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(orgAdminDisplayName($supervisor) . '（' . orgRoleLabel($supervisor['org_role'] ?? 'staff') . '）', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <?php
                                    $parentLabel = '（なし）';
                                    foreach ($supervisors as $supervisor) {
                                        if ((int)$supervisor['id'] === (int)($user['parent_user_id'] ?? 0)) {
                                            $parentLabel = orgAdminDisplayName($supervisor);
                                            break;
                                        }
                                    }
                                    echo htmlspecialchars($parentLabel, ENT_QUOTES, 'UTF-8');
                                ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="org-note">表示件数は最大500件です。多い場合はキーワードで絞り込んでください。</p>

                <div class="org-csv-panel">
                    <h3>CSV出力・取込み</h3>
                    <p class="org-note">
                        出力したCSVの「権限」「上長メールアドレス」列を編集して取り込むと、階層をまとめて設定できます。<br>
                        取込みで更新されるのは<strong>既存ユーザーの権限と上長のみ</strong>です（ユーザーの新規作成・削除は行いません）。<br>
                        権限は「担当者」「マネージャー」「管理者」（または staff / manager / admin）で指定します。上長を外す場合は「なし」と入力してください。
                    </p>
                    <div class="org-csv-row">
                        <a href="../backend/api/admin/export-user-org-csv.php" class="org-btn">CSV出力</a>
                        <?php if ($canEdit): ?>
                        <input type="file" id="org-csv-file" accept=".csv,text/csv">
                        <button type="button" class="org-btn" id="org-csv-import">CSV取込み</button>
                        <?php else: ?>
                        <span class="org-note">取込みは管理者ロールのみ実行できます。</span>
                        <?php endif; ?>
                    </div>
                    <div id="org-import-log" class="org-import-log"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var messageContainer = document.getElementById('message-container');

            function showMessage(text, isError) {
                messageContainer.innerHTML = '<div class="message ' + (isError ? 'message-error' : 'message-success') + '">' + text + '</div>';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function updateOrg(payload, selectEl, onSuccess) {
                selectEl.disabled = true;
                fetch('../backend/api/admin/update-user-org.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        selectEl.disabled = false;
                        if (result.success) {
                            showMessage(result.message || '更新しました。', false);
                            if (onSuccess) onSuccess();
                        } else {
                            // 失敗したら選択を元に戻す（画面と実データのズレを残さない）
                            selectEl.value = selectEl.getAttribute('data-current');
                            showMessage(result.message || '更新に失敗しました', true);
                        }
                    })
                    .catch(function (err) {
                        console.error(err);
                        selectEl.disabled = false;
                        selectEl.value = selectEl.getAttribute('data-current');
                        showMessage('エラーが発生しました', true);
                    });
            }

            document.querySelectorAll('.org-role-select').forEach(function (select) {
                select.addEventListener('change', function () {
                    var userId = parseInt(this.getAttribute('data-user-id'), 10);
                    var newRole = this.value;
                    var self = this;
                    updateOrg({ user_id: userId, org_role: newRole }, this, function () {
                        self.setAttribute('data-current', newRole);
                        // 上長候補（マネージャー・管理者）が変わるため、一覧を読み直す。
                        setTimeout(function () { window.location.reload(); }, 800);
                    });
                });
            });

            document.querySelectorAll('.org-parent-select').forEach(function (select) {
                select.addEventListener('change', function () {
                    var userId = parseInt(this.getAttribute('data-user-id'), 10);
                    var parentId = this.value === '' ? null : parseInt(this.value, 10);
                    var self = this;
                    updateOrg({ user_id: userId, parent_user_id: parentId }, this, function () {
                        self.setAttribute('data-current', self.value);
                    });
                });
            });

            // 法人プラン：会社ごとの階層分け ON / OFF と、判定に使う「統括のログインメール」。
            // ON/OFF とメールは同じ行の値をまとめて送る（片方だけの更新で相手を消さないため）。
            function orgPlanSave(row, onDone) {
                var toggle = row.querySelector('.org-plan-toggle');
                var emailInput = row.querySelector('.org-plan-email');
                var badge = row.querySelector('.org-plan-badge');
                if (!toggle) return;

                var enabled = toggle.checked;
                var payload = {
                    license_key: toggle.getAttribute('data-license-key'),
                    license_text: toggle.getAttribute('data-license-text') || '',
                    company_name: toggle.getAttribute('data-company-name') || '',
                    admin_email: emailInput ? emailInput.value.trim() : '',
                    enabled: enabled
                };

                fetch('../backend/api/admin/update-org-hierarchy-plan.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.success && badge) {
                            badge.textContent = enabled ? 'ON' : 'OFF';
                            badge.className = 'org-plan-badge ' + (enabled ? 'org-plan-on' : 'org-plan-off');
                        }
                        showMessage(result.message || (result.success ? '更新しました。' : '更新に失敗しました'), !result.success);
                        if (onDone) onDone(result.success === true);
                    })
                    .catch(function (err) {
                        console.error(err);
                        showMessage('エラーが発生しました', true);
                        if (onDone) onDone(false);
                    });
            }

            document.querySelectorAll('.org-plan-toggle').forEach(function (toggle) {
                toggle.addEventListener('change', function () {
                    var self = this;
                    var enabled = self.checked;
                    var row = self.closest('tr');
                    var label = self.getAttribute('data-company-name') || self.getAttribute('data-license-text') || 'この会社';

                    var confirmText = enabled
                        ? label + ' の階層分け機能をONにします。\nマイページに「組織・配下顧客」が表示されるようになります。'
                        : label + ' の階層分け機能をOFFにします。\nマイページから「組織・配下顧客」が非表示になります。\n（統括・店長・配下の設定は消えません）';
                    if (!confirm(confirmText)) {
                        self.checked = !enabled;
                        return;
                    }

                    self.disabled = true;
                    orgPlanSave(row, function (ok) {
                        self.disabled = false;
                        // 失敗したら表示を元に戻す（画面と実データのズレを残さない）
                        if (!ok) self.checked = !enabled;
                    });
                });
            });

            document.querySelectorAll('.org-plan-email').forEach(function (emailInput) {
                var original = emailInput.value;
                emailInput.addEventListener('change', function () {
                    var self = this;
                    self.disabled = true;
                    orgPlanSave(self.closest('tr'), function (ok) {
                        self.disabled = false;
                        if (ok) original = self.value;
                        else self.value = original;
                    });
                });
            });

            var importBtn = document.getElementById('org-csv-import');
            if (importBtn) {
                importBtn.addEventListener('click', function () {
                    var input = document.getElementById('org-csv-file');
                    if (!input || !input.files || input.files.length === 0) {
                        showMessage('CSVファイルを選択してください', true);
                        return;
                    }
                    var formData = new FormData();
                    formData.append('csv_file', input.files[0]);
                    importBtn.disabled = true;
                    document.getElementById('org-import-log').innerHTML = '';

                    fetch('../backend/api/admin/import-user-org-csv.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (result) {
                            importBtn.disabled = false;
                            if (!result.success) {
                                showMessage(result.message || '取込みに失敗しました', true);
                                return;
                            }
                            var data = result.data || {};
                            showMessage('取込み完了：権限 ' + (data.role_updated || 0) + '件 / 上長 ' + (data.parent_updated || 0) + '件を更新、' + (data.skipped || 0) + '件スキップ', false);
                            if (data.errors && data.errors.length) {
                                var log = data.errors.map(function (line) {
                                    var div = document.createElement('div');
                                    div.textContent = line;
                                    return div.outerHTML;
                                }).join('');
                                document.getElementById('org-import-log').innerHTML = log;
                            }
                            setTimeout(function () { window.location.reload(); }, 2500);
                        })
                        .catch(function (err) {
                            console.error(err);
                            importBtn.disabled = false;
                            showMessage('エラーが発生しました', true);
                        });
                });
            }
        })();
    </script>
</body>
</html>

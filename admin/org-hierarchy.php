<?php
/**
 * 組織階層設定ページ
 * ------------------
 * 統括（管理者）→ 店長（マネージャー）→ 営業（担当者）の3階層を、
 * ユーザーごとの「権限」と「上長」で設定する。
 *
 * ここで設定した内容は、マイページの「組織・配下顧客」で
 * 上長が配下の担当者と顧客を “閲覧するため” にだけ使われる。
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
    $where[] = '(u.email LIKE ? OR bc.name LIKE ? OR bc.company_name LIKE ? OR bc.branch_department LIKE ?)';
    $like = '%' . $keyword . '%';
    array_push($params, $like, $like, $like, $like);
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
           bc.position
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
                    ここでの主な作業は<strong>「各社の一番上の方（統括）を『管理者』に指名すること」</strong>です。<br>
                    その先の 店長（マネージャー）と 営業（担当者）の紐付けは、指名された統括がご自身のマイページ
                    「組織・配下顧客」画面から、<strong>自社のメンバーだけ</strong>を対象に設定します。<br>
                    上長は<strong>配下の担当者と顧客を閲覧できるだけ</strong>で、顧客情報の編集・削除はできません。<br>
                    権限を「担当者」へ戻しても配下の紐付けは自動では外れません。配下がいる方を担当者に戻す場合は、配下の「上長」も付け替えてください。
                </p>

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
                            <th>メールアドレス</th>
                            <th>権限</th>
                            <th>上長</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr><td colspan="6">該当するユーザーがいません。</td></tr>
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

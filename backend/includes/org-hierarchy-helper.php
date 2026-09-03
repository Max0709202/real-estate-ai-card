<?php
/**
 * 組織階層（統括 → 店長 → 営業）と閲覧権限のヘルパー
 * ---------------------------------------------------
 * users.org_role       … staff=担当者 / manager=マネージャー(店長) / admin=管理者(統括)
 * users.parent_user_id … 直属の上長。親を辿ることで階層を表現する。
 *
 * 上長にできるのは「配下担当者と顧客の一覧閲覧」と「自組織の階層づくり」だけ。
 * 配下の顧客そのものを編集・削除する導線はここでは一切提供しない。
 *
 * 【重要】他社の情報が混ざらないための境界:
 *   ・同一会社の判定は「宅建業免許番号（都道府県＋登録番号）」で行う。
 *     会社名は表記ゆれ（株式会社の有無・全角半角・支店名の付記）が避けられないため使わない。
 *   ・統括（全閲覧）は同じ免許番号のメンバー全員を、
 *     マネージャー（店長）は parent_user_id を辿った自分の配下だけを閲覧できる。
 *   ・一覧・候補に出るのは「入金済み（CR / 振込済 / ST送金）かつ OPEN」の方だけ。
 *   ・そもそも階層分けを使えるのは、運営が ON にした会社（法人プラン）だけ。
 *     OFF の会社にはメニューもAPIも出さない。判定は orgHierarchyEnabledForUser() で、
 *     次の2つを「両方」満たすこと（AND）:
 *       ① 免許番号キーが一致する会社が ON
 *       ② その会社に登録したログインメールに自分のメールが含まれている、
 *          または自分がその会社の統括に指名されたマネージャー（店長）である
 *     ②のメール登録が空の会社は、まず統括が使えない。ON にしただけでは開かないので、
 *     統括の方のメールを必ず登録する。店長は統括が画面から指名するため登録は不要。
 *   なお admin/ 配下の管理画面は運営（リニュアル仲介）専用で、users とは
 *   別テーブル（admins）のログインが必要。各社の統括がそこへ入ることはない。
 */

require_once __DIR__ . '/../config/config.php';

/** 権限の並び順。上ほど強い（配下を多く見られる）。 */
const ORG_ROLES = ['staff', 'manager', 'admin'];

/** 階層の想定は3段（統括→店長→営業）。壊れたデータで無限に辿らないための上限。 */
const ORG_MAX_DEPTH = 5;

/**
 * users に org_role / parent_user_id が無ければ追加する（冪等）。
 *
 * このDBは native prepares（PDO::ATTR_EMULATE_PREPARES=false）で動くため、
 * SHOW COLUMNS ... LIKE ? のようにプレースホルダを束縛すると構文エラーで落ちる。
 * プレースホルダを使わない SHOW COLUMNS で全列を取り、PHP 側で突き合わせる。
 */
function orgEnsureUserColumns(PDO $db): void
{
    static $done = false;
    if ($done) return;

    try {
        $columns = [];
        foreach ($db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[strtolower($column['Field'])] = true;
        }

        $added = false;
        if (!isset($columns['org_role'])) {
            $db->exec(
                "ALTER TABLE users ADD COLUMN org_role ENUM('staff','manager','admin') NOT NULL DEFAULT 'staff'
                 COMMENT '組織権限 staff=担当者 / manager=マネージャー(店長) / admin=管理者(統括)'"
            );
            $added = true;
        }
        if (!isset($columns['parent_user_id'])) {
            $db->exec(
                "ALTER TABLE users ADD COLUMN parent_user_id INT NULL
                 COMMENT '直属の上長のユーザーID（営業→店長→統括）'"
            );
            $added = true;
        }

        // 索引の確認は列を追加した回だけ。列が既にある＝移行SQL適用済みとみなし、
        // 毎リクエストで SHOW INDEX を投げないようにする。
        if ($added) {
            $indexes = [];
            foreach ($db->query('SHOW INDEX FROM users')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                $indexes[strtolower($index['Key_name'])] = true;
            }
            if (!isset($indexes['idx_users_parent_user'])) {
                $db->exec('CREATE INDEX idx_users_parent_user ON users (parent_user_id)');
            }
            if (!isset($indexes['idx_users_org_role'])) {
                $db->exec('CREATE INDEX idx_users_org_role ON users (org_role)');
            }
        }

        $done = true;
    } catch (Exception $e) {
        // 追加できなくても既存機能は壊さない。組織機能だけが使えない状態になる。
        error_log('orgEnsureUserColumns error: ' . $e->getMessage());
    }
}

/** 想定外の値が来ても必ず既定の 'staff' に落とす。 */
function orgNormalizeRole($role): string
{
    $role = strtolower(trim((string)$role));
    return in_array($role, ORG_ROLES, true) ? $role : 'staff';
}

/** 画面表示用の日本語ラベル。 */
function orgRoleLabel($role): string
{
    switch (orgNormalizeRole($role)) {
        case 'admin':
            return '統括（全閲覧）';
        case 'manager':
            return 'マネージャー（店長）';
        default:
            return '担当者（営業）';
    }
}

/** 配下の担当者・顧客を閲覧できるのは統括（全閲覧）とマネージャー（店長）のみ。担当者（営業）は不可。 */
function orgCanViewTeam($role): bool
{
    return in_array(orgNormalizeRole($role), ['manager', 'admin'], true);
}

/**
 * 電話番号を国内表記（先頭0・ハイフンなし）へ揃える。
 *
 * 顧客の電話番号はSMS認証の都合で E.164（+819072234273）で保存されている。
 * そのままでは電話帳への貼り付けや発信に使えないため、日本の国番号（+81）を外して
 * 「09072234273」の形に戻す。CSV出力と配下顧客の閲覧画面で使う。
 *
 * 日本以外の国番号は桁数から判別できないため、+ を残した数字だけの形で返す。
 */
function orgFormatPhoneLocal($phone): string
{
    $raw = trim((string)$phone);
    if ($raw === '') return '';

    $digits = preg_replace('/\D+/', '', mb_convert_kana($raw, 'n'));
    if ($digits === '' || $digits === null) return '';

    // 81 + 市外局番（0以外で始まる）… 携帯は12桁、固定は11桁になる。
    if (strpos($digits, '81') === 0 && strlen($digits) >= 11 && substr($digits, 2, 1) !== '0') {
        return '0' . substr($digits, 2);
    }

    // 国内表記（090-1234-5678 など）はハイフン等を落とすだけでよい。
    return strpos($raw, '+') === 0 ? '+' . $digits : $digits;
}

/**
 * ログイン中ユーザーの組織情報を取得する。
 * 列がまだ無いDBでも落ちないよう、失敗時は staff 相当を返す。
 */
function orgLoadViewer(PDO $db, int $userId): array
{
    $fallback = ['id' => $userId, 'email' => '', 'org_role' => 'staff', 'parent_user_id' => null];
    if ($userId <= 0) return $fallback;

    orgEnsureUserColumns($db);
    try {
        $stmt = $db->prepare('SELECT id, email, org_role, parent_user_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $fallback;

        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'org_role' => orgNormalizeRole($row['org_role'] ?? 'staff'),
            'parent_user_id' => $row['parent_user_id'] !== null ? (int)$row['parent_user_id'] : null,
        ];
    } catch (Exception $e) {
        error_log('orgLoadViewer error: ' . $e->getMessage());
        return $fallback;
    }
}

/**
 * 指定ユーザーの配下（子・孫…）のユーザーIDを深さ優先ではなく段階的に集める。
 * 統括であれば「店長 + その配下の営業」が返る。自分自身は含めない。
 *
 * データ不整合（親子が循環している等）でも止まるよう、既訪問集合と深さ上限で守る。
 *
 * @return array<int, array{id:int, depth:int}> 発見順（＝上の階層から）
 */
function orgDescendants(PDO $db, int $userId, int $maxDepth = ORG_MAX_DEPTH): array
{
    if ($userId <= 0) return [];
    orgEnsureUserColumns($db);

    $found = [];
    $visited = [$userId => true];
    $frontier = [$userId];

    try {
        for ($depth = 1; $depth <= $maxDepth && !empty($frontier); $depth++) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $db->prepare("SELECT id FROM users WHERE parent_user_id IN ($placeholders)");
            $stmt->execute($frontier);

            $next = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                $childId = (int)$childId;
                if ($childId <= 0 || isset($visited[$childId])) continue;
                $visited[$childId] = true;
                $found[] = ['id' => $childId, 'depth' => $depth];
                $next[] = $childId;
            }
            $frontier = $next;
        }
    } catch (Exception $e) {
        error_log('orgDescendants error: ' . $e->getMessage());
        return [];
    }

    return $found;
}

/**
 * 「顧客一覧に出すべきチャットセッション」の条件。
 * chat/sessions.php の一覧条件と同じ（連絡先が登録済み、または担当が事前作成した顧客ページ）。
 *
 * @param string $alias chat_sessions のエイリアス
 */
function orgCustomerSessionCondition(string $alias = 'cs'): string
{
    return "(
        EXISTS (
            SELECT 1
            FROM chat_lead_contacts org_lc
            WHERE org_lc.session_id = {$alias}.id
              AND org_lc.business_card_id = {$alias}.business_card_id
              AND (
                  NULLIF(TRIM(org_lc.customer_name), '') IS NOT NULL
                  OR NULLIF(TRIM(org_lc.phone), '') IS NOT NULL
                  OR NULLIF(TRIM(org_lc.email), '') IS NOT NULL
                  OR NULLIF(TRIM(org_lc.line_id), '') IS NOT NULL
                  OR NULLIF(TRIM(org_lc.contact_value), '') IS NOT NULL
              )
        )
        OR EXISTS (
            SELECT 1
            FROM chat_customer_invitations org_ci
            WHERE org_ci.session_id = {$alias}.id
              AND org_ci.business_card_id = {$alias}.business_card_id
        )
    )";
}

/**
 * 配下メンバーの一覧（担当者ごとの概要）を返す。閲覧専用。
 *
 * @param array<int, array{id:int, depth:int}> $descendants orgDescendants() の戻り値
 */
function orgFetchMembers(PDO $db, array $descendants): array
{
    if (empty($descendants)) return [];

    $depthById = [];
    foreach ($descendants as $item) {
        $depthById[(int)$item['id']] = (int)$item['depth'];
    }
    $ids = array_keys($depthById);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sessionCondition = orgCustomerSessionCondition('cs');

    $sql = "
        SELECT u.id AS user_id,
               u.email,
               u.org_role,
               u.parent_user_id,
               u.status,
               u.last_login_at,
               parent.email AS parent_email,
               parent_bc.name AS parent_name,
               bc.id AS business_card_id,
               bc.name AS member_name,
               bc.company_name,
               bc.branch_department,
               bc.position,
               -- 件数は「そのユーザーの全名刺」を対象にする（下の顧客一覧と数が食い違わないように）。
               (SELECT COUNT(*)
                  FROM chat_sessions cs
                  JOIN business_cards count_bc ON count_bc.id = cs.business_card_id
                 WHERE count_bc.user_id = u.id
                   AND $sessionCondition) AS customer_count,
               (SELECT COUNT(*)
                  FROM chat_sessions cs
                  JOIN business_cards unread_bc ON unread_bc.id = cs.business_card_id
                  JOIN chat_messages cm ON cm.session_id = cs.id
                 WHERE unread_bc.user_id = u.id
                   AND cm.role = 'user'
                   AND cm.read_at IS NULL
                   AND $sessionCondition) AS unread_count
        FROM users u
        LEFT JOIN users parent ON parent.id = u.parent_user_id
        -- 名刺が複数ある場合は最も古い1枚を代表として使う（chat/sessions.php と同じ選び方）。
        LEFT JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) first_card ON first_card.user_id = u.id
        LEFT JOIN business_cards bc ON bc.id = first_card.id
        -- 上長の氏名。統括から見たときに「どの店長の配下の営業か」を出すために使う。
        LEFT JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) parent_card ON parent_card.user_id = u.parent_user_id
        LEFT JOIN business_cards parent_bc ON parent_bc.id = parent_card.id
        WHERE u.id IN ($placeholders)
        ORDER BY u.id ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $members = [];
    foreach ($rows as $row) {
        $userId = (int)$row['user_id'];
        $members[] = [
            'user_id' => $userId,
            'depth' => $depthById[$userId] ?? 1,
            'email' => (string)$row['email'],
            'org_role' => orgNormalizeRole($row['org_role'] ?? 'staff'),
            'org_role_label' => orgRoleLabel($row['org_role'] ?? 'staff'),
            'parent_user_id' => $row['parent_user_id'] !== null ? (int)$row['parent_user_id'] : null,
            'parent_email' => $row['parent_email'] ?? '',
            'parent_name' => (string)($row['parent_name'] ?? ''),
            'status' => (string)$row['status'],
            'last_login_at' => $row['last_login_at'],
            'business_card_id' => $row['business_card_id'] !== null ? (int)$row['business_card_id'] : null,
            'name' => (string)($row['member_name'] ?? ''),
            'company_name' => (string)($row['company_name'] ?? ''),
            'branch_department' => (string)($row['branch_department'] ?? ''),
            'position' => (string)($row['position'] ?? ''),
            'customer_count' => (int)($row['customer_count'] ?? 0),
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }

    // 並び順は「店長 → その店長の配下の営業 → 次の店長」。
    // depth だけで並べると、統括から見たとき全店舗の営業がひとまとめになり、
    // どの店舗の誰なのか読み取れなくなるため、親子を辿って組み立てる。
    return orgSortMembersAsTree($members);
}

/**
 * 配下メンバーを階層順（親のすぐ後ろにその子）に並べ替える。
 *
 * @param array $members orgFetchMembers() が組み立てた配列
 */
function orgSortMembersAsTree(array $members): array
{
    $childrenOf = [];
    foreach ($members as $member) {
        $parentId = $member['parent_user_id'] !== null ? (int)$member['parent_user_id'] : 0;
        $childrenOf[$parentId][] = $member;
    }
    foreach ($childrenOf as $parentId => $children) {
        usort($children, function ($a, $b) { return $a['user_id'] <=> $b['user_id']; });
        $childrenOf[$parentId] = $children;
    }

    $ordered = [];
    $visited = [];
    $appendSubtree = function (array $node) use (&$appendSubtree, &$ordered, &$visited, $childrenOf) {
        $id = (int)$node['user_id'];
        if (isset($visited[$id])) return;
        $visited[$id] = true;
        $ordered[] = $node;
        foreach ($childrenOf[$id] ?? [] as $child) {
            $appendSubtree($child);
        }
    };

    // 起点は直属（depth 1）。ここから親子を辿ると店舗単位のまとまりになる。
    $roots = [];
    foreach ($members as $member) {
        if ((int)($member['depth'] ?? 1) === 1) $roots[] = $member;
    }
    usort($roots, function ($a, $b) { return $a['user_id'] <=> $b['user_id']; });
    foreach ($roots as $root) {
        $appendSubtree($root);
    }

    // 親子が壊れているデータでも件数が減らないよう、辿れなかった分は末尾に足す。
    foreach ($members as $member) {
        if (!isset($visited[(int)$member['user_id']])) $ordered[] = $member;
    }

    return $ordered;
}

/**
 * 配下メンバーが担当している顧客の一覧を返す。閲覧専用。
 *
 * @param int[]    $memberIds 閲覧を許可されたユーザーIDの集合（呼び出し側で検証済みであること）
 * @param int|null $onlyUserId 指定時はその担当者だけに絞る
 */
function orgFetchCustomers(PDO $db, array $memberIds, ?int $onlyUserId = null, int $limit = 500): array
{
    $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
    if (empty($memberIds)) return [];

    // 指定された担当者が配下でなければ空を返す（他組織の顧客が漏れないようにする）。
    if ($onlyUserId !== null) {
        if (!in_array($onlyUserId, $memberIds, true)) return [];
        $memberIds = [$onlyUserId];
    }

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $sessionCondition = orgCustomerSessionCondition('cs');
    $limit = max(1, min(2000, $limit));

    $sql = "
        SELECT cs.id AS session_id,
               cs.business_card_id,
               cs.created_at,
               cs.last_seen_at,
               bc.user_id AS member_user_id,
               bc.name AS member_name,
               u.email AS member_email,
               u.org_role AS member_org_role,
               (SELECT lc.customer_name FROM chat_lead_contacts lc WHERE lc.session_id = cs.id LIMIT 1) AS customer_name,
               (SELECT lc.phone FROM chat_lead_contacts lc WHERE lc.session_id = cs.id LIMIT 1) AS customer_phone,
               (SELECT lc.email FROM chat_lead_contacts lc WHERE lc.session_id = cs.id LIMIT 1) AS customer_email,
               (SELECT IF(COALESCE(ci.first_name, '') = '', ci.last_name, CONCAT(ci.last_name, '　', ci.first_name))
                  FROM chat_customer_invitations ci WHERE ci.session_id = cs.id LIMIT 1) AS invitation_name,
               (SELECT ci.status FROM chat_customer_invitations ci WHERE ci.session_id = cs.id LIMIT 1) AS invitation_status,
               (SELECT COUNT(*) FROM chat_messages cm WHERE cm.session_id = cs.id) AS message_count,
               (SELECT COUNT(*) FROM chat_messages cmu WHERE cmu.session_id = cs.id AND cmu.role = 'user' AND cmu.read_at IS NULL) AS unread_count,
               (SELECT cml.created_at FROM chat_messages cml WHERE cml.session_id = cs.id ORDER BY cml.id DESC LIMIT 1) AS last_message_at
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        JOIN users u ON u.id = bc.user_id
        WHERE bc.user_id IN ($placeholders)
          AND $sessionCondition
        ORDER BY COALESCE(last_message_at, cs.created_at) DESC, cs.id DESC
        LIMIT $limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($memberIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $customers = [];
    foreach ($rows as $row) {
        // 顧客本人が登録した氏名を優先し、未登録なら担当が入力した申告値を使う。
        $displayName = trim((string)($row['customer_name'] ?? ''));
        if ($displayName === '') $displayName = trim((string)($row['invitation_name'] ?? ''));

        $customers[] = [
            'session_id' => (string)$row['session_id'],
            'business_card_id' => (int)$row['business_card_id'],
            'member_user_id' => (int)$row['member_user_id'],
            'member_name' => (string)($row['member_name'] ?? ''),
            'member_email' => (string)($row['member_email'] ?? ''),
            'member_org_role_label' => orgRoleLabel($row['member_org_role'] ?? 'staff'),
            'customer_name' => $displayName,
            'customer_phone' => (string)($row['customer_phone'] ?? ''),
            'customer_email' => (string)($row['customer_email'] ?? ''),
            'invitation_status' => (string)($row['invitation_status'] ?? ''),
            'message_count' => (int)($row['message_count'] ?? 0),
            'unread_count' => (int)($row['unread_count'] ?? 0),
            'last_message_at' => $row['last_message_at'],
            'last_seen_at' => $row['last_seen_at'],
            'created_at' => $row['created_at'],
        ];
    }

    return $customers;
}

/**
 * 「その方の店舗ぶん」の担当者IDを返す（店長本人＋その配下の営業）。
 *
 * 統括が店長を選んだときに、店舗まるごとの顧客を見るために使う。
 * 店長本人が顧客を持たない運用でも、店舗の顧客が空にならないようにする狙い。
 *
 * @param int[] $allowedIds 閲覧を許可された担当者IDの集合。ここに無いIDは必ず落とす。
 * @return int[]
 */
function orgTeamScopeIds(PDO $db, int $memberId, array $allowedIds): array
{
    $allowed = array_flip(array_map('intval', $allowedIds));
    if ($memberId <= 0 || !isset($allowed[$memberId])) return [];

    $ids = [$memberId];
    foreach (orgDescendants($db, $memberId) as $descendant) {
        $id = (int)$descendant['id'];
        // 許可集合との積を取るので、閲覧範囲の外が混ざることはない。
        if (isset($allowed[$id])) $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/**
 * 免許番号（宅建業者番号）の比較キーを作る。
 *
 * 同一会社の判定は会社名ではなく免許番号で行う。会社名は「株式会社の位置」「全角半角」
 * 「支店名の付記」などの表記ゆれが避けられず、同じ会社を別会社と誤判定するため。
 * 更新回数（東京都知事「(3)」第12345号 の (3) 部分）は5年ごとの免許更新で変わり、
 * 同じ会社でも名刺を作った時期で食い違うので、意図的にキーへ含めない。
 *
 * @return array{prefecture:string, number:string, key:string} key が空なら判定不能
 */
function orgLicenseParts($prefecture, $registrationNumber): array
{
    $blank = ['prefecture' => '', 'number' => '', 'key' => ''];

    $prefecture = trim((string)$prefecture);
    $number = trim((string)$registrationNumber);
    if ($prefecture === '' || $number === '') return $blank;

    // 全角を半角に寄せ、空白を落とす。
    $prefecture = preg_replace('/\s+/u', '', mb_convert_kana($prefecture, 'as')) ?? '';
    // 「第12345号」「No.12345」などの飾りを落として英数字だけにする。
    $number = preg_replace('/[^0-9A-Za-z]/u', '', mb_convert_kana($number, 'as')) ?? '';
    // 「012345」と「12345」は同じ会社として扱う。ただし全部0のときは落としすぎない。
    $withoutZeros = ltrim($number, '0');
    if ($withoutZeros !== '') $number = $withoutZeros;

    if ($prefecture === '' || $number === '') return $blank;

    $number = mb_strtolower($number, 'UTF-8');
    return [
        'prefecture' => $prefecture,
        'number' => $number,
        'key' => $prefecture . '|' . $number,
    ];
}

/**
 * ユーザーの免許番号（代表名刺のもの）を返す。
 *
 * @return array{text:string, prefecture:string, number:string, key:string}
 */
function orgLicenseForUser(PDO $db, int $userId): array
{
    $blank = ['text' => '', 'prefecture' => '', 'number' => '', 'key' => ''];
    if ($userId <= 0) return $blank;

    try {
        $stmt = $db->prepare('
            SELECT real_estate_license_prefecture,
                   real_estate_license_renewal_number,
                   real_estate_license_registration_number
            FROM business_cards
            WHERE user_id = ?
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $blank;

        $parts = orgLicenseParts(
            $row['real_estate_license_prefecture'] ?? '',
            $row['real_estate_license_registration_number'] ?? ''
        );
        if ($parts['key'] === '') return $blank;

        // 画面表示用（例：東京都知事（3）第12345号）。
        $renewal = trim((string)($row['real_estate_license_renewal_number'] ?? ''));
        $text = trim((string)($row['real_estate_license_prefecture'] ?? ''))
            . ($renewal !== '' ? '（' . $renewal . '）' : '')
            . '第' . trim((string)($row['real_estate_license_registration_number'] ?? '')) . '号';

        return [
            'text' => $text,
            'prefecture' => $parts['prefecture'],
            'number' => $parts['number'],
            'key' => $parts['key'],
        ];
    } catch (Exception $e) {
        error_log('orgLicenseForUser error: ' . $e->getMessage());
        return $blank;
    }
}

/**
 * org_license_settings（会社ごとの階層機能 ON/OFF）が無ければ作る（冪等）。
 *
 * 移行SQLを流し忘れた環境でも、階層機能が「OFF」として安全に動くようにしておく。
 */
function orgEnsureLicenseSettingsTable(PDO $db): void
{
    static $done = false;
    if ($done) return;

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS org_license_settings (
                license_key VARCHAR(191) NOT NULL PRIMARY KEY
                    COMMENT '免許番号の正規化キー（都道府県|登録番号）',
                license_text VARCHAR(255) NULL
                    COMMENT '画面表示用の免許番号',
                company_name VARCHAR(255) NULL
                    COMMENT '確認用の会社名。判定には使わない',
                admin_email TEXT NULL
                    COMMENT '階層機能を使えるログインメール。カンマ／改行区切りで複数可',
                hierarchy_enabled TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1=法人プラン（階層分けを使える） / 0=使えない',
                updated_by_admin_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_org_license_enabled (hierarchy_enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 先に作られた環境にも admin_email を足す（冪等）。
        // プレースホルダを使えないDB設定のため、SHOW COLUMNS で全列を取って突き合わせる。
        $columns = [];
        foreach ($db->query('SHOW COLUMNS FROM org_license_settings')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[strtolower($column['Field'])] = (string)$column['Type'];
        }
        if (!isset($columns['admin_email'])) {
            $db->exec("
                ALTER TABLE org_license_settings
                ADD COLUMN admin_email TEXT NULL
                    COMMENT '階層機能を使えるログインメール。カンマ／改行区切りで複数可'
                AFTER company_name
            ");
        } elseif (stripos($columns['admin_email'], 'text') === false) {
            // 先に VARCHAR(255) で作られた環境を、複数メールが入る TEXT に広げる。
            $db->exec("
                ALTER TABLE org_license_settings
                MODIFY COLUMN admin_email TEXT NULL
                    COMMENT '階層機能を使えるログインメール。カンマ／改行区切りで複数可'
            ");
        }

        $done = true;
    } catch (Exception $e) {
        // 作れなくても既存機能は壊さない。階層機能が使えない状態になるだけ。
        error_log('orgEnsureLicenseSettingsTable error: ' . $e->getMessage());
    }
}

/**
 * その免許番号（会社）で階層分けを使えるか。
 * 行が無い会社は OFF（＝法人プラン未契約）として扱う。
 */
function orgHierarchyEnabledForKey(PDO $db, string $licenseKey): bool
{
    if ($licenseKey === '') return false;
    orgEnsureLicenseSettingsTable($db);

    // 1リクエスト中に何度も呼ばれるため、免許番号ごとに結果を覚えておく。
    static $cache = [];
    if (array_key_exists($licenseKey, $cache)) return $cache[$licenseKey];

    try {
        $stmt = $db->prepare('SELECT hierarchy_enabled FROM org_license_settings WHERE license_key = ? LIMIT 1');
        $stmt->execute([$licenseKey]);
        $value = $stmt->fetchColumn();
        $cache[$licenseKey] = ($value !== false && (int)$value === 1);
    } catch (Exception $e) {
        error_log('orgHierarchyEnabledForKey error: ' . $e->getMessage());
        $cache[$licenseKey] = false;
    }

    return $cache[$licenseKey];
}

/**
 * 「利用できるログインメール」の入力欄を配列にほどく。
 *
 * カンマ・改行・空白・全角カンマのどれで区切っても同じように扱う。
 * 比較は小文字・前後空白除去で行うため、ここで正規化しておく。
 *
 * @return string[] 重複を除いた小文字のメールアドレス
 */
function orgParseEmailList($raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') return [];

    $parts = preg_split('/[\s,;、，]+/u', $raw) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = strtolower(trim($part));
        if ($email === '') continue;
        $emails[$email] = true;
    }

    return array_keys($emails);
}

/**
 * その会社（免許番号）で、このメールが階層機能を使えるか。
 *
 * 登録が空の会社は「誰も使えない」。ON にしただけでは開かず、
 * 利用する方（統括・店長）のメールを必ず登録してもらう運用にするため。
 */
function orgEmailAllowedForKey(PDO $db, string $licenseKey, string $email): bool
{
    $email = strtolower(trim($email));
    if ($licenseKey === '' || $email === '') return false;
    orgEnsureLicenseSettingsTable($db);

    // 1リクエスト中に何度も呼ばれるため、会社ごとに登録メールを覚えておく。
    static $cache = [];
    if (!array_key_exists($licenseKey, $cache)) {
        try {
            $stmt = $db->prepare('SELECT admin_email FROM org_license_settings WHERE license_key = ? LIMIT 1');
            $stmt->execute([$licenseKey]);
            $cache[$licenseKey] = orgParseEmailList($stmt->fetchColumn() ?: '');
        } catch (Exception $e) {
            error_log('orgEmailAllowedForKey error: ' . $e->getMessage());
            $cache[$licenseKey] = [];
        }
    }

    return in_array($email, $cache[$licenseKey], true);
}

/**
 * ログイン中のユーザーが階層分けを使えるか。
 *
 * 次の2つを「両方」満たすときだけ使える（AND）:
 *   ① 免許番号キーが一致する会社が ON になっている
 *   ② その会社に登録したログインメールに、自分のメールが含まれている
 *
 * ②が空の会社は誰も使えない。ON にし忘れ／メール登録し忘れのどちらでも
 * 「表示されない」になるため、管理画面側で未登録の会社に注意書きを出している。
 */
function orgHierarchyEnabledForUser(PDO $db, int $userId): bool
{
    if ($userId <= 0) return false;

    $viewer = orgLoadViewer($db, $userId);
    $isManager = orgNormalizeRole($viewer['org_role']) === 'manager';
    $licenseKey = orgLicenseForUser($db, $userId)['key'];

    if (orgHierarchyEnabledForKey($db, $licenseKey)) {
        if (orgEmailAllowedForKey($db, $licenseKey, (string)$viewer['email'])) return true;

        // マネージャー（店長）は、その会社の統括（全閲覧）が「組織・配下顧客」で指名した方に限られる
        // （update-role.php / orgCanManageMember() で自社かどうかを検証済み）。
        // 店長の配下メンバーは店長自身が指定する運用のため、運営へのメール登録を待たずに使えるようにする。
        // 統括（全閲覧）は従来どおりメール登録が必要（統括の指名は運営の管理画面で行うため）。
        if ($isManager) return true;
    }

    // 店長の名刺に宅建業者番号がまだ入っていないと、自分の免許番号キーを作れず
    // 会社の ON / OFF を判定できずにメニューごと消えてしまう。
    // 店長は統括が指名した方なので、指名した上長（統括）の会社で判定し直す。
    // 店長が閲覧できる範囲は parent_user_id を辿った自分の配下だけで、
    // 免許番号は使わないため、これで他社の情報が見えるようになることはない。
    if ($isManager && !empty($viewer['parent_user_id'])) {
        $parentKey = orgLicenseForUser($db, (int)$viewer['parent_user_id'])['key'];
        if ($parentKey !== '' && $parentKey !== $licenseKey && orgHierarchyEnabledForKey($db, $parentKey)) {
            return true;
        }
    }

    return false;
}

/**
 * 会社ごとの階層機能を ON / OFF する（運営の管理画面からのみ呼ぶ）。
 *
 * 階層そのもの（権限・上長）は消さない。OFF にしても設定は残り、
 * ON に戻せばそのまま元の階層で使える。
 */
function orgSetHierarchyEnabled(PDO $db, string $licenseKey, string $licenseText, string $companyName, string $adminEmail, bool $enabled, ?int $adminId = null): bool
{
    if ($licenseKey === '') return false;
    orgEnsureLicenseSettingsTable($db);

    // 入力の区切り文字を吸収し、小文字・重複なしの一覧にしてから保存する。
    $adminEmail = implode(', ', orgParseEmailList($adminEmail));

    try {
        $stmt = $db->prepare('
            INSERT INTO org_license_settings (license_key, license_text, company_name, admin_email, hierarchy_enabled, updated_by_admin_id)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                license_text = VALUES(license_text),
                company_name = VALUES(company_name),
                admin_email = VALUES(admin_email),
                hierarchy_enabled = VALUES(hierarchy_enabled),
                updated_by_admin_id = VALUES(updated_by_admin_id)
        ');
        $stmt->execute([$licenseKey, $licenseText, $companyName, ($adminEmail !== '' ? $adminEmail : null), $enabled ? 1 : 0, $adminId]);
        return true;
    } catch (Exception $e) {
        error_log('orgSetHierarchyEnabled error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 会社ごとの ON/OFF 設定をまとめて返す（運営の一覧画面用）。
 *
 * @return array<string, array{license_text:string, company_name:string, admin_email:string, hierarchy_enabled:bool, updated_at:?string}>
 */
function orgFetchLicenseSettings(PDO $db): array
{
    orgEnsureLicenseSettingsTable($db);

    try {
        $rows = $db->query('
            SELECT license_key, license_text, company_name, admin_email, hierarchy_enabled, updated_at
            FROM org_license_settings
        ')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('orgFetchLicenseSettings error: ' . $e->getMessage());
        return [];
    }

    $settings = [];
    foreach ($rows as $row) {
        $settings[(string)$row['license_key']] = [
            'license_text' => (string)($row['license_text'] ?? ''),
            'company_name' => (string)($row['company_name'] ?? ''),
            'admin_email' => (string)($row['admin_email'] ?? ''),
            'hierarchy_enabled' => ((int)$row['hierarchy_enabled'] === 1),
            'updated_at' => $row['updated_at'],
        ];
    }
    return $settings;
}

/**
 * 2人が同じ会社（＝同じ免許番号）か。
 * 免許番号が未登録で判定できない場合は false（＝許可しない）。他社を取り込ませないための関門。
 */
function orgIsSameLicense(PDO $db, int $userIdA, int $userIdB): bool
{
    $a = orgLicenseForUser($db, $userIdA);
    $b = orgLicenseForUser($db, $userIdB);
    return $a['key'] !== '' && $a['key'] === $b['key'];
}

/**
 * 一覧・候補に出してよい方の条件。
 * 「入金済み（CR / 振込済 / ST送金）かつ OPEN（is_published）」の名刺を持つ方だけを対象にする。
 *
 * @param string $userAlias users のエイリアス
 */
function orgActiveCardCondition(string $userAlias = 'u'): string
{
    return "EXISTS (
        SELECT 1
        FROM business_cards org_active_bc
        WHERE org_active_bc.user_id = {$userAlias}.id
          AND org_active_bc.payment_status IN ('CR', 'BANK_PAID', 'ST')
          AND org_active_bc.is_published = 1
    )";
}

/**
 * 与えたユーザーIDのうち、入金済みかつOPENの方だけを残す。
 *
 * @param int[] $ids
 * @return int[] 入力の並び順を保つ
 */
function orgFilterActiveMemberIds(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) return [];

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT DISTINCT user_id
            FROM business_cards
            WHERE user_id IN ($placeholders)
              AND payment_status IN ('CR', 'BANK_PAID', 'ST')
              AND is_published = 1
        ");
        $stmt->execute($ids);
        $active = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Exception $e) {
        error_log('orgFilterActiveMemberIds error: ' . $e->getMessage());
        return [];
    }

    $kept = [];
    foreach ($ids as $id) {
        if (isset($active[$id])) $kept[] = $id;
    }
    return $kept;
}

/**
 * 同じ免許番号のメンバー（入金済み・OPEN）を返す。統括（全閲覧）の閲覧範囲そのもの。
 *
 * SQLでは登録番号の部分一致で粗く絞り、最終判定は orgLicenseParts() のキー完全一致で行う
 * （「第12345号」のような表記ゆれをPHP側で吸収するため）。
 *
 * @param array $license orgLicenseForUser() の戻り値
 * @return array<int, array{id:int, org_role:string, parent_user_id:int|null}>
 */
function orgFetchLicenseMembers(PDO $db, int $actorId, array $license, int $limit = 1000): array
{
    if (($license['key'] ?? '') === '') return [];
    $limit = max(1, min(5000, $limit));
    $activeCondition = orgActiveCardCondition('u');
    // 登録番号が全角数字で入力されている名刺も拾えるよう、半角・全角の両方で粗く絞る。
    $numberWide = mb_convert_kana($license['number'], 'A');

    $sql = "
        SELECT u.id,
               u.org_role,
               u.parent_user_id,
               bc.real_estate_license_prefecture AS license_prefecture,
               bc.real_estate_license_registration_number AS license_number
        FROM users u
        JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) first_card ON first_card.user_id = u.id
        JOIN business_cards bc ON bc.id = first_card.id
        WHERE u.id <> ?
          AND bc.real_estate_license_registration_number IS NOT NULL
          AND bc.real_estate_license_registration_number <> ''
          AND (
                REPLACE(REPLACE(bc.real_estate_license_registration_number, ' ', ''), '　', '') LIKE CONCAT('%', ?, '%')
             OR REPLACE(REPLACE(bc.real_estate_license_registration_number, ' ', ''), '　', '') LIKE CONCAT('%', ?, '%')
          )
          AND $activeCondition
        ORDER BY u.id ASC
        LIMIT $limit
    ";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$actorId, $license['number'], $numberWide]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('orgFetchLicenseMembers error: ' . $e->getMessage());
        return [];
    }

    $members = [];
    foreach ($rows as $row) {
        // 最終判定。粗い部分一致で拾った他社を、免許番号キーの完全一致で落とす。
        $parts = orgLicenseParts($row['license_prefecture'] ?? '', $row['license_number'] ?? '');
        if ($parts['key'] !== $license['key']) continue;

        $members[] = [
            'id' => (int)$row['id'],
            'org_role' => orgNormalizeRole($row['org_role'] ?? 'staff'),
            'parent_user_id' => $row['parent_user_id'] !== null ? (int)$row['parent_user_id'] : null,
        ];
    }

    return $members;
}

/**
 * 閲覧できるメンバーの集合を返す（orgDescendants() と同じ形）。
 *
 *   統括（全閲覧）… 同じ免許番号のメンバー全員。上長として登録していなくても見える。
 *   マネージャー（店長）… parent_user_id を辿った自分の配下だけ。
 *
 * どちらも「入金済み かつ OPEN」の方に限る。
 *
 * @param array $viewer orgLoadViewer() の戻り値
 * @return array<int, array{id:int, depth:int}>
 */
function orgVisibleMemberScope(PDO $db, array $viewer): array
{
    $viewerId = (int)($viewer['id'] ?? 0);
    $role = orgNormalizeRole($viewer['org_role'] ?? 'staff');
    if ($viewerId <= 0 || !orgCanViewTeam($role)) return [];

    if ($role !== 'admin') {
        $ids = [];
        $depthById = [];
        foreach (orgDescendants($db, $viewerId) as $descendant) {
            $id = (int)$descendant['id'];
            $ids[] = $id;
            $depthById[$id] = (int)$descendant['depth'];
        }
        $scope = [];
        foreach (orgFilterActiveMemberIds($db, $ids) as $id) {
            $scope[] = ['id' => $id, 'depth' => $depthById[$id] ?? 1];
        }
        return $scope;
    }

    $license = orgLicenseForUser($db, $viewerId);
    if ($license['key'] === '') return [];

    $members = orgFetchLicenseMembers($db, $viewerId, $license);
    $inScope = [];
    foreach ($members as $member) {
        $inScope[$member['id']] = true;
    }

    $scope = [];
    foreach ($members as $member) {
        $parentId = $member['parent_user_id'];
        // 店長の配下にいる営業だけを2段目に置く。未所属・統括直下・店長本人は1段目。
        $depth = ($parentId !== null && $parentId !== $viewerId && isset($inScope[$parentId])) ? 2 : 1;
        $scope[] = ['id' => $member['id'], 'depth' => $depth];
    }

    return $scope;
}

/**
 * 実行者が「その担当者の顧客」を閲覧できるか（閲覧専用の判定）。
 *
 * 顧客詳細（org/customer-detail.php）と添付ファイルの配信で、
 * 同じ条件を使うためにここへまとめている。判定は次のすべてを満たすこと:
 *   ・実行者が統括（全閲覧）かマネージャー（店長）
 *   ・実行者の会社で階層分けが使える（法人プラン）
 *   ・相手が実行者の閲覧範囲（orgVisibleMemberScope）に入っている
 *
 * @param int $memberId 顧客を担当しているユーザーのID
 */
function orgCanViewMemberCustomers(PDO $db, int $viewerId, int $memberId): bool
{
    if ($viewerId <= 0 || $memberId <= 0) return false;

    $viewer = orgLoadViewer($db, $viewerId);
    if (!orgCanViewTeam($viewer['org_role'])) return false;
    if (!orgHierarchyEnabledForUser($db, $viewerId)) return false;

    foreach (orgVisibleMemberScope($db, $viewer) as $member) {
        if ((int)$member['id'] === $memberId) return true;
    }

    return false;
}

/**
 * 実行者がその相手の階層（権限・上長）を変更できるか。
 *
 *   統括（全閲覧）… 同じ免許番号のメンバーなら誰でも
 *   マネージャー（店長）… 自分の直属の配下だけ
 *
 * どちらも他の統括には触れない（統括の指名・解除は運営の管理画面だけで行う）。
 */
function orgCanManageMember(PDO $db, array $viewer, int $targetId): bool
{
    $viewerId = (int)($viewer['id'] ?? 0);
    $role = orgNormalizeRole($viewer['org_role'] ?? 'staff');
    if ($viewerId <= 0 || $targetId <= 0 || $viewerId === $targetId) return false;
    if (!orgCanViewTeam($role)) return false;

    try {
        $stmt = $db->prepare('SELECT org_role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $targetRole = orgNormalizeRole($stmt->fetchColumn() ?: 'staff');
    } catch (Exception $e) {
        error_log('orgCanManageMember error: ' . $e->getMessage());
        return false;
    }
    if ($targetRole === 'admin') return false;

    return $role === 'admin'
        ? orgIsSameLicense($db, $viewerId, $targetId)
        : orgIsDirectChild($db, $viewerId, $targetId);
}

/**
 * 配下として登録できる候補を返す。
 *
 * 条件は「同じ免許番号」かつ「入金済み・OPEN」かつ「統括ではない」かつ「まだ自分の直属ではない」。
 * すでに他の店長の配下にいる方も候補に含める（店舗異動をこの画面で行えるようにするため）。
 * その場合は現在の上長を添えて返し、画面側で「現在：◯◯の配下」と表示する。
 *
 * マネージャー（店長）の配下に置けるのは担当者（営業）だけなので、店長には営業のみ返す。
 */
function orgFetchAssignCandidates(PDO $db, int $actorId, string $actorRole = 'admin', int $limit = 300): array
{
    $license = orgLicenseForUser($db, $actorId);
    if ($license['key'] === '') return [];

    $actorRole = orgNormalizeRole($actorRole);
    $limit = max(1, min(1000, $limit));
    $activeCondition = orgActiveCardCondition('u');
    // 登録番号が全角数字で入力されている名刺も拾えるよう、半角・全角の両方で粗く絞る。
    $numberWide = mb_convert_kana($license['number'], 'A');
    // 統括は店長候補も選ぶため担当者・店長の両方、店長は担当者のみ。
    $roleCondition = $actorRole === 'admin' ? "u.org_role <> 'admin'" : "u.org_role = 'staff'";

    $sql = "
        SELECT u.id AS user_id,
               u.email,
               u.org_role,
               u.parent_user_id,
               bc.name AS member_name,
               bc.company_name,
               bc.branch_department,
               bc.real_estate_license_prefecture AS license_prefecture,
               bc.real_estate_license_registration_number AS license_number,
               parent_bc.name AS parent_name,
               parent.email AS parent_email
        FROM users u
        JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) first_card ON first_card.user_id = u.id
        JOIN business_cards bc ON bc.id = first_card.id
        LEFT JOIN users parent ON parent.id = u.parent_user_id
        LEFT JOIN (
            SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
        ) parent_card ON parent_card.user_id = u.parent_user_id
        LEFT JOIN business_cards parent_bc ON parent_bc.id = parent_card.id
        WHERE u.id <> ?
          AND (u.parent_user_id IS NULL OR u.parent_user_id <> ?)
          AND $roleCondition
          AND bc.real_estate_license_registration_number IS NOT NULL
          AND bc.real_estate_license_registration_number <> ''
          AND (
                REPLACE(REPLACE(bc.real_estate_license_registration_number, ' ', ''), '　', '') LIKE CONCAT('%', ?, '%')
             OR REPLACE(REPLACE(bc.real_estate_license_registration_number, ' ', ''), '　', '') LIKE CONCAT('%', ?, '%')
          )
          AND $activeCondition
        ORDER BY u.id ASC
        LIMIT $limit
    ";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$actorId, $actorId, $license['number'], $numberWide]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('orgFetchAssignCandidates error: ' . $e->getMessage());
        return [];
    }

    $candidates = [];
    foreach ($rows as $row) {
        // 最終判定。粗い部分一致で拾った他社を、免許番号キーの完全一致で落とす。
        $parts = orgLicenseParts($row['license_prefecture'] ?? '', $row['license_number'] ?? '');
        if ($parts['key'] !== $license['key']) continue;

        $candidates[] = [
            'user_id' => (int)$row['user_id'],
            'email' => (string)$row['email'],
            'org_role' => orgNormalizeRole($row['org_role'] ?? 'staff'),
            'org_role_label' => orgRoleLabel($row['org_role'] ?? 'staff'),
            'name' => (string)($row['member_name'] ?? ''),
            'company_name' => (string)($row['company_name'] ?? ''),
            'branch_department' => (string)($row['branch_department'] ?? ''),
            'parent_user_id' => $row['parent_user_id'] !== null ? (int)$row['parent_user_id'] : null,
            'parent_name' => (string)($row['parent_name'] ?: ($row['parent_email'] ?? '')),
        ];
    }

    return $candidates;
}

/**
 * 免許番号が初めて登録されたときに、その方を統括（全閲覧）にする。
 *
 * 「その免許番号で最初に登録した人が統括、以降の人は担当者（営業）」という運用のため、
 * 同じ免許番号にすでに統括がいる場合は何もしない。既に権限が付いている方も変更しない。
 * 統括の追加・解除は運営の管理画面（admin/dashboard.php の☑）から行う。
 *
 * @return bool 統括に設定したら true
 */
function orgAutoAssignFirstAdmin(PDO $db, int $userId): bool
{
    if ($userId <= 0) return false;
    orgEnsureUserColumns($db);

    try {
        $license = orgLicenseForUser($db, $userId);
        if ($license['key'] === '') return false;

        $stmt = $db->prepare('SELECT org_role, parent_user_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        // すでに権限や上長が付いている方は運用側の設定を尊重して触らない。
        if (!$current) return false;
        if (orgNormalizeRole($current['org_role'] ?? 'staff') !== 'staff') return false;
        if ($current['parent_user_id'] !== null) return false;

        // 同じ免許番号に統括がいるかを確認する。統括は数が少ないため全件を突き合わせる。
        $stmt = $db->prepare('
            SELECT bc.real_estate_license_prefecture AS license_prefecture,
                   bc.real_estate_license_registration_number AS license_number
            FROM users u
            JOIN (
                SELECT user_id, MIN(id) AS id FROM business_cards GROUP BY user_id
            ) first_card ON first_card.user_id = u.id
            JOIN business_cards bc ON bc.id = first_card.id
            WHERE u.org_role = ? AND u.id <> ?
        ');
        $stmt->execute(['admin', $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts = orgLicenseParts($row['license_prefecture'] ?? '', $row['license_number'] ?? '');
            if ($parts['key'] === $license['key']) return false;
        }

        $stmt = $db->prepare("UPDATE users SET org_role = 'admin', parent_user_id = NULL WHERE id = ? AND org_role = 'staff'");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('orgAutoAssignFirstAdmin error: ' . $e->getMessage());
        return false;
    }
}

/** $targetId が $actorId の直属の配下か。 */
function orgIsDirectChild(PDO $db, int $actorId, int $targetId): bool
{
    if ($actorId <= 0 || $targetId <= 0) return false;
    try {
        $stmt = $db->prepare('SELECT parent_user_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $parentId = $stmt->fetchColumn();
        return $parentId !== false && $parentId !== null && (int)$parentId === $actorId;
    } catch (Exception $e) {
        error_log('orgIsDirectChild error: ' . $e->getMessage());
        return false;
    }
}

/** $targetId が $actorId の配下（孫以降も含む）か。 */
function orgIsInSubtree(PDO $db, int $actorId, int $targetId): bool
{
    foreach (orgDescendants($db, $actorId) as $descendant) {
        if ((int)$descendant['id'] === $targetId) return true;
    }
    return false;
}

/**
 * 上長として指定できるか（循環を作らないか）を確認する。
 * $parentId が $userId 自身、または $userId の配下であれば false。
 */
function orgIsAssignableParent(PDO $db, int $userId, ?int $parentId): bool
{
    if ($parentId === null) return true;
    if ($parentId <= 0 || $parentId === $userId) return false;

    foreach (orgDescendants($db, $userId) as $descendant) {
        if ((int)$descendant['id'] === $parentId) return false;
    }
    return true;
}

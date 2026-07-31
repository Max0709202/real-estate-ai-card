<?php
/**
 * 組織階層（統括 → 店長 → 営業）と閲覧権限のヘルパー
 * ---------------------------------------------------
 * users.org_role       … staff=担当者 / manager=マネージャー(店長) / admin=管理者(統括)
 * users.parent_user_id … 直属の上長。親を辿ることで階層を表現する。
 *
 * 今回の要件は「配下担当者と顧客の一覧閲覧」のみ。
 * 上長が配下の顧客を編集・削除する導線はここでは一切提供しない
 * （読み取り専用のクエリしか置かない）。
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
            return '管理者（統括）';
        case 'manager':
            return 'マネージャー（店長）';
        default:
            return '担当者（営業）';
    }
}

/** 配下の担当者・顧客を閲覧できるのはマネージャーと管理者のみ。 */
function orgCanViewTeam($role): bool
{
    return in_array(orgNormalizeRole($role), ['manager', 'admin'], true);
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

    // 階層順（店長 → その配下の営業）に並べ替えると一覧が読みやすい。
    usort($members, function ($a, $b) {
        if ($a['depth'] !== $b['depth']) return $a['depth'] <=> $b['depth'];
        return $a['user_id'] <=> $b['user_id'];
    });

    return $members;
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

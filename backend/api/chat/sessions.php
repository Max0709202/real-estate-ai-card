<?php
/**
 * List chat sessions for the current user's business card(s). My Page use.
 * GET ?business_card_id= optional filter
 * GET ?deleted=1 ゴミ箱（削除済み履歴）だけを返す。復元期限の情報も付ける。
 * GET ?sort=latest|viewing|contracted|created 顧客一覧の表示順（既定 latest）。
 *   latest     … 最新のアクセス順（既定）
 *   viewing    … 内見を予定している顧客（物件ステータス「内見希望」あり）を上位表示
 *   contracted … 契約済みの顧客（物件ステータス「契約」あり）を上位表示
 *   created    … 登録日が新しい順
 *   ※ viewing / contracted とも、グループ内および以降はいずれも最新のアクセス順。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../includes/loan-simulation-helper.php';
require_once __DIR__ . '/../../includes/customer-invitation-helper.php';
require_once __DIR__ . '/../../includes/chat-session-trash-helper.php';
require_once __DIR__ . '/../../includes/property-helper.php'; // 並び替え（内見予定／契約済み）で properties を参照する
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

try {
    $database = new Database();
    $db = $database->getConnection();

    $cardId = isset($_GET['business_card_id']) ? (int) $_GET['business_card_id'] : null;
    // ゴミ箱表示。既定（0）は通常一覧で、削除済みは除外する。
    $showDeleted = isset($_GET['deleted']) && (string)$_GET['deleted'] === '1';
    // 顧客一覧の表示順。未対応の値は既定（最新のアクセス順）に落とす。
    $sort = trim((string)($_GET['sort'] ?? ''));
    if (!in_array($sort, ['latest', 'viewing', 'contracted', 'created'], true)) $sort = 'latest';
    chatSessionTrashEnsureColumns($db);
    ensureChatLeadContactTable($db);
    // エージェントが事前作成した顧客ページも一覧に出すため、先に表を用意しておく
    // （下の SQL が参照するので、存在しないと SELECT で落ちる）。
    customerInviteEnsureTable($db);
    // 並び替え（内見予定／契約済み）で properties を参照するため、未作成なら用意しておく。
    propertyEnsureTables($db);

    // 「内見を予定している」「契約済み」は、その顧客に該当ステータスの物件があるかで上位グループを決める。
    // 既定（最新のアクセス順・登録日順）では不要なので、判定用の列は必要なときだけ足す。
    // ゴミ箱は削除順で固定のため、判定用の列は付けない。
    $sortGroupSelect = '';
    if ($showDeleted) {
        // 何もしない。
    } elseif ($sort === 'viewing') {
        $sortGroupSelect = ", EXISTS(SELECT 1 FROM properties psv
                  WHERE psv.session_id = cs.id AND psv.status = 'viewing_request') as sort_group";
    } elseif ($sort === 'contracted') {
        $sortGroupSelect = ", EXISTS(SELECT 1 FROM properties psc
                  WHERE psc.session_id = cs.id AND psc.status = 'contracted') as sort_group";
    }

    $sql = "
        SELECT cs.id, cs.business_card_id, cs.last_seen_at, cs.created_at, cs.handoff_mode, cs.deleted_at,
               bc.name as card_holder_name,
               (SELECT COUNT(*) FROM chat_messages cm WHERE cm.session_id = cs.id) as message_count,
               (SELECT COUNT(*) FROM chat_messages cmu WHERE cmu.session_id = cs.id AND cmu.role = 'user' AND cmu.read_at IS NULL) as unread_count,
               (SELECT cml.created_at FROM chat_messages cml WHERE cml.session_id = cs.id ORDER BY cml.id DESC LIMIT 1) as last_message_at,
               (SELECT cmlr.role FROM chat_messages cmlr WHERE cmlr.session_id = cs.id ORDER BY cmlr.id DESC LIMIT 1) as last_message_role,
               (SELECT cl.id FROM chat_leads cl WHERE cl.session_id = cs.id LIMIT 1) as has_lead,
               (SELECT cc.id FROM chat_lead_contacts cc WHERE cc.session_id = cs.id LIMIT 1) as has_contact,
               (SELECT cc.customer_name FROM chat_lead_contacts cc WHERE cc.session_id = cs.id LIMIT 1) as customer_name,
               -- エージェントが事前作成した顧客ページ（顧客はまだSMS認証前）の情報
               (SELECT ci.status FROM chat_customer_invitations ci WHERE ci.session_id = cs.id LIMIT 1) as invitation_status,
               -- 名は任意入力のため、未入力なら姓だけを返す（末尾に全角スペースが残らないように）
               (SELECT IF(COALESCE(ci.first_name, '') = '', ci.last_name, CONCAT(ci.last_name, '　', ci.first_name))
                  FROM chat_customer_invitations ci WHERE ci.session_id = cs.id LIMIT 1) as invitation_name
               {$sortGroupSelect}
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE bc.user_id = ?
    ";
    if ($showDeleted) {
        // ゴミ箱では通常一覧の絞り込み（連絡先あり／事前作成の顧客ページ）を課さない。
        // ここに出てこない履歴は復元できなくなるため、削除済みは取りこぼさず全て出す。
        $sql .= " AND cs.deleted_at IS NOT NULL";
    } else {
        // ゴミ箱に入れた履歴は通常一覧から隠す（実体は残っているので復元できる）。
        $sql .= "
          AND cs.deleted_at IS NULL
          AND (
            EXISTS (
              SELECT 1
              FROM chat_lead_contacts listed_contact
              WHERE listed_contact.session_id = cs.id
                AND listed_contact.business_card_id = cs.business_card_id
                AND (
                    NULLIF(TRIM(listed_contact.customer_name), '') IS NOT NULL
                    OR NULLIF(TRIM(listed_contact.phone), '') IS NOT NULL
                    OR NULLIF(TRIM(listed_contact.email), '') IS NOT NULL
                    OR NULLIF(TRIM(listed_contact.line_id), '') IS NOT NULL
                    OR NULLIF(TRIM(listed_contact.contact_value), '') IS NOT NULL
                )
            )
            -- 顧客がまだ登録していなくても、エージェントが事前作成した顧客ページは一覧に出す。
            OR EXISTS (
              SELECT 1
              FROM chat_customer_invitations listed_invite
              WHERE listed_invite.session_id = cs.id
                AND listed_invite.business_card_id = cs.business_card_id
            )
          )";
    }
    $params = [$userId];
    if ($cardId > 0) {
        $sql .= " AND cs.business_card_id = ?";
        $params[] = $cardId;
    }
    // 「最新のアクセス順」。一覧に表示している日時（last_seen_at、無ければ created_at）と
    // 同じ基準で並べるので、画面上でも日時がそのまま新しい順に並ぶ。
    // やり取りがまだ無いセッション（＝事前作成した顧客ページ）は last_seen_at が NULL のため、
    // 作成日時で代替して LIMIT 200 から溢れないようにする。
    $latestAccessOrder = "COALESCE(cs.last_seen_at, cs.created_at) DESC, cs.id DESC";
    if ($showDeleted) {
        // ゴミ箱は削除した順（新しいものが上）。
        $sql .= " ORDER BY cs.deleted_at DESC LIMIT 200";
    } elseif ($sort === 'created') {
        // 登録日が新しい順。
        $sql .= " ORDER BY cs.created_at DESC, cs.id DESC LIMIT 200";
    } elseif ($sort === 'viewing' || $sort === 'contracted') {
        // 該当する顧客を上位に、グループ内も以降も最新のアクセス順。
        $sql .= " ORDER BY sort_group DESC, {$latestAccessOrder} LIMIT 200";
    } else {
        $sql .= " ORDER BY {$latestAccessOrder} LIMIT 200";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$sessionRow) {
        $loanSimulation = loanSimulationFetchForSession($db, $sessionRow["id"] ?? "", (int)($sessionRow["business_card_id"] ?? 0));
        $sessionRow["has_loan_simulation"] = $loanSimulation && loanSimulationHasDisplayValues($loanSimulation) ? 1 : 0;
        $sessionRow["loan_simulation_summary"] = $loanSimulation ? loanSimulationDisplaySummary($loanSimulation, 3) : "";
        $sessionRow["loan_simulation_updated_at"] = $loanSimulation["updated_at"] ?? null;
        // ゴミ箱の各行に復元期限（完全削除される日時と残り日数）を付ける。
        $sessionRow["purge_at"] = chatSessionTrashPurgeAt($sessionRow["deleted_at"] ?? null);
        $sessionRow["days_left"] = chatSessionTrashDaysLeft($sessionRow["deleted_at"] ?? null);
    }
    unset($sessionRow);

    $registeredPhones = [];
    if ($cardId > 0) {
        $registeredPhones = chatRegisteredPhonesForCard($db, $cardId, 100);
    } else {
        $stmt = $db->prepare('SELECT id FROM business_cards WHERE user_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$userId]);
        $firstCardId = (int)($stmt->fetchColumn() ?: 0);
        if ($firstCardId > 0) $registeredPhones = chatRegisteredPhonesForCard($db, $firstCardId, 100);
    }

    sendSuccessResponse([
        'sessions' => $sessions,
        'registered_phones' => $registeredPhones,
        'deleted_view' => $showDeleted ? 1 : 0,
        // 実際に適用した表示順（未対応の値を渡されたときは既定に戻したことが分かるように返す）。
        'sort' => $showDeleted ? '' : $sort,
        'retention_days' => chatSessionTrashRetentionDays(),
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat sessions list error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

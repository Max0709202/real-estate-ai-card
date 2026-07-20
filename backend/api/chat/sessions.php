<?php
/**
 * List chat sessions for the current user's business card(s). My Page use.
 * GET ?business_card_id= optional filter
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../includes/loan-simulation-helper.php';
require_once __DIR__ . '/../../includes/customer-invitation-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = requireAuth();

try {
    $database = new Database();
    $db = $database->getConnection();

    $cardId = isset($_GET['business_card_id']) ? (int) $_GET['business_card_id'] : null;
    ensureChatLeadContactTable($db);
    // エージェントが事前作成した顧客ページも一覧に出すため、先に表を用意しておく
    // （下の SQL が参照するので、存在しないと SELECT で落ちる）。
    customerInviteEnsureTable($db);

    $sql = "
        SELECT cs.id, cs.business_card_id, cs.last_seen_at, cs.created_at, cs.handoff_mode,
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
               (SELECT CONCAT(ci.last_name, '　', ci.first_name) FROM chat_customer_invitations ci WHERE ci.session_id = cs.id LIMIT 1) as invitation_name
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE bc.user_id = ?
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
          )
    ";
    $params = [$userId];
    if ($cardId > 0) {
        $sql .= " AND cs.business_card_id = ?";
        $params[] = $cardId;
    }
    // 未読（要返信）を最優先で上位表示し、その中で新しい順に並べる。
    // やり取りがまだ無いセッション（＝事前作成した顧客ページ）は last_message_at が NULL で、
    // DESC では最後尾に落ちて LIMIT 200 から溢れるため、作成日時で代替して並べる。
    $sql .= " ORDER BY (unread_count > 0) DESC, COALESCE(last_message_at, cs.created_at) DESC, cs.last_seen_at DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sessions as &$sessionRow) {
        $loanSimulation = loanSimulationFetchForSession($db, $sessionRow["id"] ?? "", (int)($sessionRow["business_card_id"] ?? 0));
        $sessionRow["has_loan_simulation"] = $loanSimulation && loanSimulationHasDisplayValues($loanSimulation) ? 1 : 0;
        $sessionRow["loan_simulation_summary"] = $loanSimulation ? loanSimulationDisplaySummary($loanSimulation, 3) : "";
        $sessionRow["loan_simulation_updated_at"] = $loanSimulation["updated_at"] ?? null;
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

    sendSuccessResponse(['sessions' => $sessions, 'registered_phones' => $registeredPhones], 'OK');
} catch (Exception $e) {
    error_log('Chat sessions list error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

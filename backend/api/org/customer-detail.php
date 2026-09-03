<?php
/**
 * 配下の顧客1件の詳細（マイページ「組織・配下顧客」用）。閲覧専用。
 *
 * GET ?session_id=<chat_sessions.id>
 *
 * 担当者（営業）のマイページ「顧客詳細」と同じ内容を、上長が閲覧するためだけに返す。
 *   ・顧客情報 / 担当連絡（チャット）/ AI整理サマリー / ヒアリング情報 /
 *     住宅ローン入力 / 選択ボタン履歴 / 提案物件
 * 返すのは読み取りのみで、編集・削除・代理返信のAPIはここには一切用意しない。
 *
 * 閲覧できるのは、その顧客を担当しているユーザーが
 * 自分の閲覧範囲（統括＝自社全員／店長＝自分の配下）に入っている場合だけ。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/customer-invitation-helper.php';
require_once __DIR__ . '/../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../includes/chat-sales-summary-helper.php';
require_once __DIR__ . '/../../includes/loan-simulation-helper.php';
require_once __DIR__ . '/../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$viewerId = (int) requireAuth();

$sessionId = trim($_GET['session_id'] ?? '');
if ($sessionId === '') {
    sendErrorResponse('session_id is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $viewer = orgLoadViewer($db, $viewerId);
    if (!orgCanViewTeam($viewer['org_role'])) {
        sendErrorResponse('配下の顧客を閲覧する権限がありません', 403);
    }
    // 階層分けは法人プランの機能。運営が ON にした会社（免許番号）でのみ使える。
    if (!orgHierarchyEnabledForUser($db, $viewerId)) {
        sendErrorResponse('組織階層の機能は法人プランのみのご提供です', 403);
    }

    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id, cs.created_at, cs.last_seen_at,
               bc.user_id AS member_user_id,
               bc.name AS member_name,
               u.email AS member_email,
               u.org_role AS member_org_role
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        JOIN users u ON u.id = bc.user_id
        WHERE cs.id = ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('お客様が見つかりません', 404);
    }

    // 閲覧範囲の確認。配下でない担当者の顧客は、存在そのものを返さない。
    if (!orgCanViewMemberCustomers($db, $viewerId, (int)$session['member_user_id'])) {
        sendErrorResponse('このお客様は閲覧できません', 403);
    }

    $businessCardId = (int)$session['business_card_id'];

    // ① 顧客情報（連絡先）
    ensureChatLeadContactTable($db);
    $stmt = $db->prepare('SELECT customer_name, phone, email, created_at, updated_at FROM chat_lead_contacts WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 顧客本人の登録が無ければ、担当が事前作成したご案内の氏名を使う（一覧と同じ扱い）。
    customerInviteEnsureTable($db);
    $stmt = $db->prepare("
        SELECT IF(COALESCE(first_name, '') = '', last_name, CONCAT(last_name, '　', first_name)) AS invitation_name, status
        FROM chat_customer_invitations WHERE session_id = ? LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $customerName = trim((string)($contact['customer_name'] ?? ''));
    if ($customerName === '') $customerName = trim((string)($invitation['invitation_name'] ?? ''));

    // ② 担当連絡（チャット）。AIチャネルの会話は生ログではなくAI整理サマリーで見せる。
    $stmt = $db->prepare("
        SELECT id, role, channel, message, read_at, created_at, edited_at, deleted_at
        FROM chat_messages
        WHERE session_id = ? AND channel = 'contact'
        ORDER BY id ASC
    ");
    $stmt->execute([$sessionId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($messages) {
        $attach = agentMsgLoadAttachments($db, array_column($messages, 'id'));
        foreach ($messages as &$messageRow) {
            $messageRow['attachments'] = $attach[(int)$messageRow['id']] ?? [];
            // 顧客が取り消した発言は本文をプレースホルダに、編集済みはフラグを付ける（担当画面と同じ）。
            $messageRow = agentMsgApplyEditState($messageRow, false);
        }
        unset($messageRow);
    }

    // ③ ヒアリング情報（担当画面と同じ分類済みの形）
    $stmt = $db->prepare('SELECT structured_data FROM chat_leads WHERE session_id = ? LIMIT 1');
    $stmt->execute([$sessionId]);
    $leadRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $structuredLead = [];
    if ($leadRow && !empty($leadRow['structured_data'])) {
        $decoded = json_decode($leadRow['structured_data'], true);
        if (is_array($decoded)) $structuredLead = $decoded;
    }
    $lead = $structuredLead ? [
        'structured_data' => $structuredLead,
        'classified_data' => chatIntakeClassifiedLeadItems($structuredLead),
    ] : null;

    // ④ AIチャット履歴（AI整理サマリー）。担当画面と同じく、内容が変わっていなければ保存済みを返す。
    $aiSummary = null;
    try {
        $case = chatCrmLoadCase($db, $sessionId, $businessCardId) ?: chatCrmDefaultCase();
        $summaryResult = chatSalesSummaryResolve($db, $sessionId, $businessCardId, $case, $structuredLead);
        if ($summaryResult !== null) $aiSummary = $summaryResult['summary'];
    } catch (Throwable $e) {
        // 要約が作れなくても他の情報は見せる。
        error_log('org customer detail summary error: ' . $e->getMessage());
    }

    // ⑤ 住宅ローンシミュレーター入力
    $loanSimulationData = null;
    $loanSimulation = loanSimulationFetchForSession($db, $sessionId, $businessCardId);
    if ($loanSimulation && loanSimulationHasDisplayValues($loanSimulation)) {
        $loanSimulationData = [
            'updated_at' => $loanSimulation['updated_at'] ?? null,
            'groups' => loanSimulationDisplayGroups($loanSimulation),
        ];
    }

    // ⑥ 提案物件（物件選定）。閲覧専用のため、一覧に出る項目だけを返す。
    $properties = [];
    try {
        propertyEnsureTables($db);
        $statusDefs = propertyStatusDefs();
        $stmt = $db->prepare("
            SELECT id, status, property_type, property_name, building_name, price_text,
                   address, transport, layout, exclusive_area, land_area, building_area,
                   is_favorite, created_at
            FROM properties
            WHERE session_id = ? AND business_card_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 200
        ");
        $stmt->execute([$sessionId, $businessCardId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusKey = (string)($row['status'] ?? '');
            $properties[] = [
                'id' => (int)$row['id'],
                'name' => trim((string)($row['property_name'] ?: $row['building_name'])),
                'status_label' => $statusDefs[$statusKey]['label'] ?? '',
                'status_color' => $statusDefs[$statusKey]['color'] ?? '',
                'price_text' => (string)($row['price_text'] ?? ''),
                'address' => (string)($row['address'] ?? ''),
                'transport' => (string)($row['transport'] ?? ''),
                'layout' => (string)($row['layout'] ?? ''),
                'area' => (string)($row['exclusive_area'] ?: ($row['land_area'] ?: $row['building_area'])),
                'is_favorite' => (int)($row['is_favorite'] ?? 0),
                'created_at' => $row['created_at'],
            ];
        }
    } catch (Throwable $e) {
        // 物件選定が未使用のDBでも顧客詳細は開けるようにする。
        error_log('org customer detail property error: ' . $e->getMessage());
    }

    sendSuccessResponse([
        'session_id' => (string)$session['id'],
        'member' => [
            'user_id' => (int)$session['member_user_id'],
            'name' => (string)($session['member_name'] ?? ''),
            'email' => (string)($session['member_email'] ?? ''),
            'org_role_label' => orgRoleLabel($session['member_org_role'] ?? 'staff'),
        ],
        'customer' => [
            'name' => $customerName,
            // 電話番号は国番号（+81）を外した国内表記で返す（CSV出力と同じ）。
            'phone' => orgFormatPhoneLocal($contact['phone'] ?? ''),
            'email' => (string)($contact['email'] ?? ''),
            'invitation_status' => (string)($invitation['status'] ?? ''),
            'created_at' => $session['created_at'],
            'last_seen_at' => $session['last_seen_at'],
        ],
        'messages' => $messages,
        'ai_summary' => $aiSummary,
        'lead' => $lead,
        'loan_simulation' => $loanSimulationData,
        'properties' => $properties,
    ], 'OK');
} catch (Exception $e) {
    error_log('org customer detail error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

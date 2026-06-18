<?php
/**
 * Start a chat session (anonymous visitor on card page).
 * POST { "card_slug": "..." } -> { "session_id", "agent_name", "agent_photo_url", "can_use_loan_sim" }
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../../includes/chat-crm-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$cardSlug = trim($input['card_slug'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$currentSessionId = trim($input['current_session_id'] ?? '');
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}
if ($currentSessionId !== '' && !preg_match('/^[A-Fa-f0-9-]{36}$/', $currentSessionId)) {
    $currentSessionId = '';
}

if ($cardSlug === '') {
    sendErrorResponse('card_slug is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $card = getCardBySlugForChat($db, $cardSlug);

    if (!$card) {
        sendErrorResponse('名刺が見つかりません', 404);
    }

    if (!canUseChatbot($card)) {
        sendErrorResponse('この名刺ではチャットボットはご利用いただけません。', 403);
    }

    $sessionId = '';
    $isResumed = false;

    // 同じ端末・同じ訪問者のチャットは、新しい履歴を増やさず既存履歴へ戻す。
    // まず保存済み session_id を優先し、次に visitor_id の最新履歴を探す。
    if ($currentSessionId !== '') {
        $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1");
        $stmt->execute([$currentSessionId, $card['id']]);
        $sessionId = (string)($stmt->fetchColumn() ?: '');
    }

    if ($sessionId === '' && $visitorId !== '') {
        $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE business_card_id = ? AND visitor_identifier = ? ORDER BY last_seen_at DESC, created_at DESC LIMIT 1");
        $stmt->execute([$card['id'], $visitorId]);
        $sessionId = (string)($stmt->fetchColumn() ?: '');
    }

    if ($sessionId !== '') {
        $isResumed = true;
        if ($visitorId !== '') {
            $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(visitor_identifier, ?), last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$visitorId, $sessionId]);
        } else {
            $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$sessionId]);
        }
    }

    if ($sessionId === '') {
        $sessionId = generateChatSessionId();
        $stmt = $db->prepare("INSERT INTO chat_sessions (id, business_card_id, visitor_identifier) VALUES (?, ?, ?)");
        $stmt->execute([$sessionId, $card['id'], $visitorId !== '' ? $visitorId : null]);
    }

    $messages = [];
    $hasPreviousMessages = false;
    if ($isResumed) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $hasPreviousMessages = ((int)$stmt->fetchColumn()) > 0;
        if ($hasPreviousMessages) {
            $messages = loadRecentChatMessagesForResume($db, $sessionId, 40);
        }
    }

    $photoUrl = '';
    if (!empty($card['profile_photo'])) {
        $p = trim($card['profile_photo']);
        $photoUrl = (!preg_match('/^https?:\/\//', $p)) ? (BASE_URL . '/' . ltrim($p, '/')) : $p;
    }

    $agentName = $card['name'] ?? '担当者';
    $customerName = '';
    // 「今までのご相談内容を表示しますか？」を出すかどうかの判定。
    // 単に発言があっただけ（recent_context）では出さず、ヒアリングで相談実体が
    // 溜まっている場合のみ true にする。
    $hasConsultationSummary = false;
    if ($isResumed) {
        $customerName = chatResolveCustomerNameForSession($db, $sessionId, (int)$card['id']);
        $sessionMemory = getChatSessionMemory($db, $sessionId);
        if (is_array($sessionMemory)) {
            foreach (['last_summary', 'intent', 'property_type', 'budget', 'preferred_area', 'family', 'loan_plan', 'income_range', 'lead_summary'] as $memKey) {
                if (!empty($sessionMemory[$memKey])) { $hasConsultationSummary = true; break; }
            }
        }
    }
    $intake = chatIntakeInitialPayload($agentName);
    $resumeIntake = $isResumed ? chatIntakeResumePayload($db, $sessionId, $card['id']) : null;
    // 同じ端末で電話番号・お名前・メールアドレスの登録が済んでいるか。
    // 済んでいれば、リロード時に再入力を求めずそのまま相談へ進める。
    $registrationComplete = false;
    if ($isResumed && $resumeIntake && !empty($resumeIntake['data'])) {
        $ld = $resumeIntake['data'];
        $registrationComplete = !empty($ld['customer_phone_verified'])
            && !empty($ld['customer_last_name'])
            && !empty($ld['customer_first_name'])
            && !empty($ld['customer_email']);
    }
    $resumeMessage = $isResumed ? getChatResumeMessageForSession($db, $sessionId, $agentName, $card['id'], true) : '';
    $resumeQuickReplies = [];
    if ($isResumed && $resumeIntake && !empty($resumeIntake['can_ask_next'])) {
        $resumeQuickReplies = $resumeIntake['quick_replies'] ?? [];
        $resumeMessage = trim($resumeMessage . "

条件整理を続ける場合は、下の選択肢から近いものを選べます。無理に答えなくても大丈夫ですし、そのまま自由に質問していただいても大丈夫です。");
    }
    $handoffMode = 'bot';
    try {
        $stmt = $db->prepare("SELECT handoff_mode FROM chat_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $handoffMode = (string)($stmt->fetchColumn() ?: 'bot');
    } catch (Throwable $e) { /* 既定 bot */ }
    $data = [
        'session_id' => $sessionId,
        'visitor_id' => $visitorId,
        'handoff_mode' => $handoffMode,
        'is_resumed' => $isResumed,
        'messages' => $messages,
        'has_previous_messages' => $hasPreviousMessages,
        'has_consultation_summary' => $hasConsultationSummary,
        'registration_complete' => $registrationComplete,
        'agent_name' => $card['name'] ?? '',
        'customer_name' => $customerName,
        'resume_message' => $resumeMessage,
        'current_field' => $resumeIntake['current_field'] ?? null,
        'current_question' => $resumeIntake['current_question'] ?? '',
        'can_ask_next' => $resumeIntake['can_ask_next'] ?? false,
        'intake_mode' => $resumeIntake['intake_mode'] ?? 'guided',
        'agent_photo_url' => $photoUrl,
        'can_use_loan_sim' => canUseLoanSim($card),
        'initial_message' => $intake['initial_message'],
        'quick_replies' => $isResumed ? $resumeQuickReplies : $intake['quick_replies'],
    ];
    $crmCase = chatCrmLoadCase($db, $sessionId, (int)$card['id']);
    if (!$crmCase) {
        chatCrmUpsertCase($db, $sessionId, (int)$card['id'], [
            'deal_type' => 'purchase',
            'customer_name' => $customerName ?: '',
        ]);
        $crmCase = chatCrmLoadCase($db, $sessionId, (int)$card['id']);
    }
    if ($crmCase) {
        $crmCase['conditions_summary'] = chatCrmSummarizeConditions($crmCase);
        $crmCase['purchase_schedule'] = chatCrmCalculatePurchaseStages($crmCase['progress']['target_date'] ?? null, $crmCase['progress']['manual_overrides'] ?? []);
        $crmCase['sale_schedule'] = chatCrmCalculateSaleStages($crmCase['progress']['target_date'] ?? null, $crmCase['progress']['manual_overrides'] ?? []);
        $data['crm_case'] = $crmCase;
    }
    sendSuccessResponse($data, 'セッションを作成しました');
} catch (Exception $e) {
    error_log('Chat session start error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

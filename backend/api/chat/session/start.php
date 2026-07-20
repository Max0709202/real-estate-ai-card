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
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../../includes/customer-invitation-helper.php';

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
// エージェントが事前作成した顧客ページの専用URL（card.php?...&invite=...）から来た場合のトークン。
$inviteToken = trim($input['invite_token'] ?? '');
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
    // is_demo / expires_at を参照する前に列を補完する（SQL移行が未適用の環境でも動くように）。
    ensureChatDemoColumns($db);
    $card = getCardBySlugForChat($db, $cardSlug);

    if (!$card) {
        sendErrorResponse('名刺が見つかりません', 404);
    }

    if (!canUseChatbot($card)) {
        sendErrorResponse('この名刺ではチャットボットはご利用いただけません。', 403);
    }

    // 体験版（デモ）名刺。プロモーションで配布し、SMS認証なしで試せる。
    // 履歴は訪問者ごとに完全に分離する（共有セッションにすると他人の会話が見える）。
    $isDemo = isDemoCard($card);

    $sessionId = '';
    $isResumed = false;

    // エージェントが事前作成した顧客ページ（招待メールの専用URL）。
    // 該当セッションをこの端末に紐づけ、通常の再開ロジックより優先する。
    // デモ名刺では事前作成を行わないため、デモ時は無視する。
    $invite = null;
    if (!$isDemo && $inviteToken !== '') {
        $found = customerInviteFindByToken($db, $inviteToken);
        // 別の名刺のトークンで他人のセッションを開かせない。
        if ($found && (int)$found['business_card_id'] === (int)$card['id']) {
            $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1");
            $stmt->execute([$found['session_id'], $card['id']]);
            if ($stmt->fetchColumn()) {
                $invite = $found;
            }
        }
    }

    if ($invite) {
        $sessionId = (string)$invite['session_id'];
        $isResumed = true;

        // 事前作成直後のセッションは visitor_identifier が NULL なので、URLを開いた端末を
        // 所有者として紐づける（poll/upload の突合で visitor_id 一致が要るため、
        // COALESCE で温存してはいけない）。
        // ただし、既にやり取りのあるセッションでは所有者を奪わない。招待メールが第三者へ
        // 転送された場合に、本来のお客様の端末が締め出されるのを防ぐ。
        // その場合もSMS認証を済ませた端末なら、通常の再開と同じく所有者を更新する。
        $stmt = $db->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $inviteSessionHasMessages = ((int)$stmt->fetchColumn()) > 0;
        $canClaimInviteSession = !$inviteSessionHasMessages
            || ($visitorId !== '' && chatSessionDeviceAuth($db, $sessionId, $visitorId));

        if ($visitorId !== '' && $canClaimInviteSession) {
            $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = ?, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$visitorId, $sessionId]);
        } else {
            $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$sessionId]);
        }
        customerInviteMarkOpened($db, $sessionId);
    }

    if ($isDemo) {
        // デモは visitor_id に紐づく未失効のデモセッションだけを再開する。
        // session_id 単体では再開させない（他人のデモ session_id を送られると、
        // 下で device_auth を無条件に有効化している都合上、履歴が読めてしまうため）。
        if ($visitorId !== '') {
            $stmt = $db->prepare("SELECT id FROM chat_sessions
                WHERE business_card_id = ? AND visitor_identifier = ? AND is_demo = 1
                  AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY last_seen_at DESC, created_at DESC LIMIT 1");
            $stmt->execute([$card['id'], $visitorId]);
            $sessionId = (string)($stmt->fetchColumn() ?: '');
        }
        if ($sessionId !== '') {
            $isResumed = true;
            $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$sessionId]);
        }
    }

    // 同じ端末・同じ訪問者のチャットは、新しい履歴を増やさず既存履歴へ戻す。
    // まず保存済み session_id を優先し、次に visitor_id の最新履歴を探す。
    if (!$isDemo && !$invite && $currentSessionId !== '') {
        $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1");
        $stmt->execute([$currentSessionId, $card['id']]);
        $sessionId = (string)($stmt->fetchColumn() ?: '');
    }

    if (!$isDemo && !$invite && $sessionId === '' && $visitorId !== '') {
        $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE business_card_id = ? AND visitor_identifier = ? ORDER BY last_seen_at DESC, created_at DESC LIMIT 1");
        $stmt->execute([$card['id'], $visitorId]);
        $sessionId = (string)($stmt->fetchColumn() ?: '');
    }

    if (!$isDemo && !$invite && $sessionId !== '') {
        $isResumed = true;
        $deviceAuth = $visitorId !== '' ? chatSessionDeviceAuth($db, $sessionId, $visitorId) : null;
        if ($visitorId !== '' && $deviceAuth) {
            // 再開した端末を現所有者に更新する。COALESCE で元所有者を保持すると、
            // 端末側 visitor_id とセッションの visitor_identifier が食い違い、
            // poll/upload の突合で 403（セッションを確認できません）になるため。
            // SMS認証から3時間以内の端末だけ、履歴閲覧権を付与する。
            $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = ?, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$visitorId, $sessionId]);
        } else {
            $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$sessionId]);
        }
    }

    if ($sessionId === '') {
        $sessionId = generateChatSessionId();
        if ($isDemo) {
            // 体験者ごとに新しいセッションを発行し、TTL経過後に cron が削除する。
            $expiresAt = date('Y-m-d H:i:s', time() + chatDemoSessionTtlSeconds());
            $stmt = $db->prepare("INSERT INTO chat_sessions (id, business_card_id, visitor_identifier, is_demo, expires_at) VALUES (?, ?, ?, 1, ?)");
            $stmt->execute([$sessionId, $card['id'], $visitorId !== '' ? $visitorId : null, $expiresAt]);
        } else {
            $stmt = $db->prepare("INSERT INTO chat_sessions (id, business_card_id, visitor_identifier) VALUES (?, ?, ?)");
            $stmt->execute([$sessionId, $card['id'], $visitorId !== '' ? $visitorId : null]);
        }
    }

    if ($isDemo) {
        // SMS認証・お名前・メールアドレスの入力を省くため、ダミーの本人情報を入れておく。
        chatIntakeApplyDemoRegistration($db, $sessionId, (int)$card['id']);
    }

    $messages = [];
    $hasPreviousMessages = false;
    $deviceAuth = (!$isDemo && $isResumed && $visitorId !== '') ? chatSessionDeviceAuth($db, $sessionId, $visitorId) : null;
    // デモは visitor_id 一致でしか再開しないため、この端末は常に自分のセッションの持ち主。
    $deviceAuthValid = $isDemo ? true : (bool)$deviceAuth;
    if ($isResumed) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $hasPreviousMessages = ((int)$stmt->fetchColumn()) > 0;
        if ($hasPreviousMessages && $deviceAuthValid) {
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
    if ($isResumed && $deviceAuthValid) {
        $customerName = chatResolveCustomerNameForSession($db, $sessionId, (int)$card['id']);
        if ($customerName === '' && !empty($deviceAuth['customer_name'])) {
            $customerName = chatCleanCustomerNameValue($deviceAuth['customer_name']);
        }
        $sessionMemory = getChatSessionMemory($db, $sessionId);
        if (is_array($sessionMemory)) {
            foreach (['last_summary', 'intent', 'property_type', 'budget', 'preferred_area', 'family', 'loan_plan', 'income_range', 'lead_summary'] as $memKey) {
                if (!empty($sessionMemory[$memKey])) { $hasConsultationSummary = true; break; }
            }
        }
    }
    $intake = chatIntakeInitialPayload($agentName);
    $resumeIntake = ($isResumed && $deviceAuthValid) ? chatIntakeResumePayload($db, $sessionId, $card['id']) : null;
    // 同じ端末で電話番号・お名前・メールアドレスの登録が済んでいるか。
    // 済んでいれば、リロード時に再入力を求めずそのまま相談へ進める。
    $registrationComplete = false;
    if ($isDemo) {
        // デモはダミー情報を投入済みなので、登録フローを一切出さない。
        $registrationComplete = true;
    } elseif ($isResumed && $deviceAuthValid && $resumeIntake && !empty($resumeIntake['data'])) {
        $ld = $resumeIntake['data'];
        $registrationComplete = chatIntakeProfileComplete($ld);
    }
    $resumeMessage = ($isResumed && $deviceAuthValid) ? getChatResumeMessageForSession($db, $sessionId, $agentName, $card['id'], true) : '';
    $resumeQuickReplies = [];
    if ($isResumed && $resumeIntake && !empty($resumeIntake['can_ask_next'])) {
        $resumeQuickReplies = $resumeIntake['quick_replies'] ?? [];
        $resumeMessage = trim($resumeMessage . "

条件整理を続ける場合は、下の選択肢から近いものを選べます。無理に答えなくても大丈夫ですし、そのまま自由に質問していただいても大丈夫です。");
    }
    // 事前作成された顧客ページの初回表示。専用の歓迎メッセージを出し、そのあとは
    // 通常どおり SMS認証 → お名前 → メールアドレスの登録へ進む。
    // エージェントが申告した氏名・メールは本人に確認し直すため、ここでは登録に使わない
    // （氏名の誤りや、別のメールアドレスで受け取りたい場合があるため）。
    $inviteWelcome = '';
    $inviteCustomerName = '';
    if ($invite) {
        $inviteCustomerName = customerInviteFullName((string)$invite['last_name'], (string)$invite['first_name']);
        if (!$registrationComplete) {
            $inviteWelcome = customerInviteWelcomeMessage($inviteCustomerName);
        }
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
        'is_demo' => $isDemo,
        'is_resumed' => $isResumed,
        'messages' => $messages,
        'has_previous_messages' => $hasPreviousMessages,
        'has_consultation_summary' => $hasConsultationSummary,
        'registration_complete' => $registrationComplete,
        'device_auth_valid' => $deviceAuthValid,
        'device_auth_expires_at' => $deviceAuth['verified_until'] ?? null,
        'agent_name' => $card['name'] ?? '',
        'customer_name' => $customerName,
        'is_invited' => (bool)$invite,
        'invite_welcome' => $inviteWelcome,
        'invite_customer_name' => $inviteCustomerName,
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
    if ($registrationComplete || !$isResumed) {
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
    }
    sendSuccessResponse($data, 'セッションを作成しました');
} catch (Exception $e) {
    error_log('Chat session start error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

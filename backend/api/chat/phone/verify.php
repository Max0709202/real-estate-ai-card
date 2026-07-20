<?php
/**
 * Verify Firebase phone auth ID token and resolve a previous chat session.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';

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
$idToken = trim($input['id_token'] ?? '');
$cardSlug = trim($input['card_slug'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$reason = trim($input['reason'] ?? '');
$currentSessionId = trim($input['current_session_id'] ?? '');
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}
if ($currentSessionId !== '' && !preg_match('/^[A-Fa-f0-9-]{36}$/', $currentSessionId)) {
    $currentSessionId = '';
}
if ($idToken === '' || $cardSlug === '') {
    sendErrorResponse('id_token and card_slug are required', 400);
}

try {
    $firebase = chatFirebaseLookupIdToken($idToken);
    if (!empty($firebase['error'])) {
        sendErrorResponse('SMS認証を確認できませんでした。もう一度お試しください。', 401);
    }
    $firebaseUser = $firebase['user'];
    $phone = trim($firebaseUser['phoneNumber'] ?? '');
    $uid = trim($firebaseUser['localId'] ?? '');
    if ($phone === '') {
        sendErrorResponse('認証済みの電話番号を確認できませんでした。', 400);
    }

    $database = new Database();
    $db = $database->getConnection();
    ensureChatDemoColumns($db);
    $card = getCardBySlugForChat($db, $cardSlug);
    if (!$card) {
        sendErrorResponse('名刺が見つかりません', 404);
    }
    if (!canUseChatbot($card)) {
        sendErrorResponse('この名刺ではチャットボットはご利用いただけません。', 403);
    }
    // 体験版名刺では電話番号を保存しない（lookup.php と同じ理由。セッション共有を防ぐ）。
    if (isDemoCard($card)) {
        sendErrorResponse('体験版名刺ではSMS認証は不要です。', 403);
    }

    $businessCardId = (int)$card['id'];
    $found = chatFindSessionByVerifiedPhone($db, $businessCardId, $phone);
    $sessionId = '';
    $matched = false;
    $registrationCompleted = false;
    $needsProfile = false;
    $customerName = $found['customer_name'] ?? null;

    // 最初のアクセス時の事前登録フロー（SMS認証→お名前→メールアドレス）。
    // 既に登録済みの電話番号なら過去の相談を引き継ぎ、未登録なら現在のセッションへ登録する。
    if ($reason === 'upfront') {
        if ($found && !empty($found['session_id'])) {
            $sessionId = $found['session_id'];
            $matched = true;
            // SMS本人確認済みの端末を現所有者に更新（NULL指定時のみ既存を保持）。
            // 元所有者を保持すると端末側 visitor_id と食い違い poll/upload が 403 になる。
            $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(?, visitor_identifier), last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$visitorId !== '' ? $visitorId : null, $sessionId]);
        } else {
            if ($currentSessionId !== '') {
                $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1");
                $stmt->execute([$currentSessionId, $businessCardId]);
                $sessionId = (string)($stmt->fetchColumn() ?: '');
            }
            if ($sessionId === '') {
                $sessionId = chatCreateSessionForVerifiedPhone($db, $businessCardId, $visitorId);
            } else {
                // SMS本人確認済みの端末を現所有者に更新（NULL指定時のみ既存を保持）。
                // 元所有者を保持すると端末側 visitor_id と食い違い poll/upload が 403 になる。
                $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(?, visitor_identifier), last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$visitorId !== '' ? $visitorId : null, $sessionId]);
            }
            $leadData = chatIntakeApplyVerifiedPhoneRegistration($db, $sessionId, $businessCardId, $phone);
            $customerName = $leadData['customer_name'] ?? $customerName;
            $needsProfile = true;
        }
    }

    if ($sessionId === '' && $reason === 'register' && $currentSessionId !== '') {
        $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1");
        $stmt->execute([$currentSessionId, $businessCardId]);
        $sessionId = (string)($stmt->fetchColumn() ?: '');
        if ($sessionId !== '') {
            // SMS本人確認済みの端末を現所有者に更新（NULL指定時のみ既存を保持）。
            // 元所有者を保持すると端末側 visitor_id と食い違い poll/upload が 403 になる。
            $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(?, visitor_identifier), last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$visitorId !== '' ? $visitorId : null, $sessionId]);
            $leadData = chatIntakeApplyVerifiedPhoneRegistration($db, $sessionId, $businessCardId, $phone);
            $customerName = $leadData['customer_name'] ?? $customerName;
            $registrationCompleted = true;
        }
    }

    if ($sessionId === '') {
        if ($found && !empty($found['session_id'])) {
            $sessionId = $found['session_id'];
            $matched = true;
            // SMS本人確認済みの端末を現所有者に更新（NULL指定時のみ既存を保持）。
            // 元所有者を保持すると端末側 visitor_id と食い違い poll/upload が 403 になる。
            $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(?, visitor_identifier), last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$visitorId !== '' ? $visitorId : null, $sessionId]);
        } else {
            $sessionId = chatCreateSessionForVerifiedPhone($db, $businessCardId, $visitorId);
        }
    }

    if ($sessionId !== '') {
        $resolvedCustomerName = chatResolveCustomerNameForSession($db, $sessionId, $businessCardId);
        if ($resolvedCustomerName !== '') $customerName = $resolvedCustomerName;
        $leadData = chatIntakeApplyVerifiedPhoneRegistration($db, $sessionId, $businessCardId, $phone);
        $customerName = $leadData['customer_name'] ?? $customerName;
    }

    chatRegisterVerifiedPhone($db, $businessCardId, $phone, $uid, $sessionId, $customerName);

    // SMS認証済みのこの端末を、当該セッションを共有できる認可端末として登録する。
    // これにより同一電話番号の別端末（PC・スマホ等）も同じ相談内容へアクセスできる。
    if ($sessionId !== '' && $visitorId !== '') {
        chatSessionRegisterDevice($db, $sessionId, $visitorId, $phone, $customerName, 10800);
    }

    $agentName = $card['name'] ?? '担当者';
    $intake = chatIntakeInitialPayload($agentName);
    $resumeIntake = $matched ? chatIntakeResumePayload($db, $sessionId, $businessCardId) : null;
    $resumeMessage = $matched ? getChatResumeMessageForSession($db, $sessionId, $agentName, $businessCardId, true) : '';
    $resumeQuickReplies = [];
    if ($matched && $resumeIntake && !empty($resumeIntake['can_ask_next'])) {
        $resumeQuickReplies = $resumeIntake['quick_replies'] ?? [];
    }
    $messages = $matched ? loadRecentChatMessagesForResume($db, $sessionId, 40) : [];
    $profileData = $resumeIntake['data'] ?? ($leadData ?? []);
    $hasName = chatIntakeHasCustomerName($profileData);
    $hasEmail = chatIntakeHasCustomerEmail($profileData);
    $needsProfile = !$hasName || !$hasEmail;
    if ($needsProfile) {
        $messages = [];
        $resumeQuickReplies = [];
    }

    sendSuccessResponse([
        'matched' => $matched,
        'registration_completed' => $registrationCompleted,
        'needs_profile' => $needsProfile,
        'has_name' => $hasName,
        'has_email' => $hasEmail,
        'session_id' => $sessionId,
        'phone' => $phone,
        'customer_name' => $customerName,
        'resume_message' => $resumeMessage,
        'messages' => $messages,
        'quick_replies' => $matched ? $resumeQuickReplies : $intake['quick_replies'],
        'initial_message' => $intake['initial_message'],
        'current_field' => $resumeIntake['current_field'] ?? null,
        'current_question' => $resumeIntake['current_question'] ?? '',
        'can_ask_next' => !$needsProfile && !empty($resumeIntake['can_ask_next']),
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat phone verify error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

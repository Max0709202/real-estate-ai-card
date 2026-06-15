<?php
/**
 * Resolve a registered chat phone before sending Firebase SMS.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';

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
$phone = trim($input['phone'] ?? '');
$cardSlug = trim($input['card_slug'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');

if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

if ($phone === '' || $cardSlug === '') {
    sendErrorResponse('phone and card_slug are required', 400);
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

    $businessCardId = (int)$card['id'];
    $found = chatFindSessionByVerifiedPhone($db, $businessCardId, $phone);

    if (!$found || empty($found['session_id'])) {
        sendSuccessResponse([
            'matched' => false,
            'phone' => $phone,
        ], 'OK');
    }

    $sessionId = (string)$found['session_id'];
    $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(visitor_identifier, ?), last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$visitorId !== '' ? $visitorId : null, $sessionId]);

    $customerName = chatResolveCustomerNameForSession($db, $sessionId, $businessCardId);
    if ($customerName === '') {
        $customerName = $found['customer_name'] ?? null;
    }
    chatRegisterVerifiedPhone($db, $businessCardId, $phone, '', $sessionId, $customerName);

    $agentName = $card['name'] ?? '担当者';
    $resumeIntake = chatIntakeResumePayload($db, $sessionId, $businessCardId);
    $resumeMessage = getChatResumeMessageForSession($db, $sessionId, $agentName, $businessCardId, true);
    $resumeQuickReplies = [];
    if ($resumeIntake && !empty($resumeIntake['can_ask_next'])) {
        $resumeQuickReplies = $resumeIntake['quick_replies'] ?? [];
    }

    sendSuccessResponse([
        'matched' => true,
        'registration_completed' => false,
        'needs_profile' => false,
        'session_id' => $sessionId,
        'phone' => $phone,
        'customer_name' => $customerName,
        'resume_message' => $resumeMessage,
        'messages' => loadRecentChatMessagesForResume($db, $sessionId, 40),
        'quick_replies' => $resumeQuickReplies,
        'initial_message' => chatIntakeInitialPayload($agentName)['initial_message'] ?? '',
        'current_field' => $resumeIntake['current_field'] ?? null,
        'current_question' => $resumeIntake['current_question'] ?? '',
        'can_ask_next' => $resumeIntake['can_ask_next'] ?? false,
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat phone lookup error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

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
$resumeRequested = !empty($input['resume']);
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

    if ($resumeRequested && $visitorId !== '') {
        if ($currentSessionId !== '') {
            $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? AND visitor_identifier = ? LIMIT 1");
            $stmt->execute([$currentSessionId, $card['id'], $visitorId]);
            $sessionId = (string)($stmt->fetchColumn() ?: '');
        }
        if ($sessionId === '') {
            $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE business_card_id = ? AND visitor_identifier = ? ORDER BY last_seen_at DESC, created_at DESC LIMIT 1");
            $stmt->execute([$card['id'], $visitorId]);
            $sessionId = (string)($stmt->fetchColumn() ?: '');
        }
        if ($sessionId !== '') {
            $isResumed = true;
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
    if ($isResumed) {
        $stmt = $db->prepare("SELECT role, message, created_at FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 40");
        $stmt->execute([$sessionId]);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        foreach ($rows as $row) {
            $messages[] = [
                'role' => $row['role'],
                'message' => $row['message'],
                'created_at' => $row['created_at'],
            ];
        }
    }

    $photoUrl = '';
    if (!empty($card['profile_photo'])) {
        $p = trim($card['profile_photo']);
        $photoUrl = (!preg_match('/^https?:\/\//', $p)) ? (BASE_URL . '/' . ltrim($p, '/')) : $p;
    }

    $intake = chatIntakeInitialPayload($card['name'] ?? '担当者');
    $data = [
        'session_id' => $sessionId,
        'visitor_id' => $visitorId,
        'is_resumed' => $isResumed,
        'messages' => $messages,
        'agent_name' => $card['name'] ?? '',
        'agent_photo_url' => $photoUrl,
        'can_use_loan_sim' => canUseLoanSim($card),
        'initial_message' => $intake['initial_message'],
        'quick_replies' => $intake['quick_replies'],
    ];
    sendSuccessResponse($data, 'セッションを作成しました');
} catch (Exception $e) {
    error_log('Chat session start error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

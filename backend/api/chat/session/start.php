<?php
/**
 * Start a chat session (anonymous visitor on card page).
 * POST { "card_slug": "..." } -> { "session_id", "agent_name", "agent_photo_url", "can_use_loan_sim" }
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';

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

    $sessionId = generateChatSessionId();
    $stmt = $db->prepare("INSERT INTO chat_sessions (id, business_card_id, visitor_identifier) VALUES (?, ?, ?)");
    $stmt->execute([$sessionId, $card['id'], $input['visitor_id'] ?? null]);

    $photoUrl = '';
    if (!empty($card['profile_photo'])) {
        $p = trim($card['profile_photo']);
        $photoUrl = (!preg_match('/^https?:\/\//', $p)) ? (BASE_URL . '/' . ltrim($p, '/')) : $p;
    }

    $data = [
        'session_id' => $sessionId,
        'agent_name' => $card['name'] ?? '',
        'agent_photo_url' => $photoUrl,
        'can_use_loan_sim' => canUseLoanSim($card),
    ];
    sendSuccessResponse($data, 'セッションを作成しました');
} catch (Exception $e) {
    error_log('Chat session start error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

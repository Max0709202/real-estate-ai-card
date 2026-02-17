<?php
/**
 * Send a message and get bot response.
 * POST { "session_id": "...", "message": "..." } -> { "reply", "sources" }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-helpers.php';

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
$sessionId = trim($input['session_id'] ?? '');
$message = trim($input['message'] ?? '');

if ($sessionId === '' || $message === '') {
    sendErrorResponse('session_id and message are required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT id, business_card_id FROM chat_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $stmt = $db->prepare("SELECT * FROM business_cards WHERE id = ?");
    $stmt->execute([$session['business_card_id']]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    if (!canUseChatbot($card)) {
        sendErrorResponse('チャットボットはご利用いただけません。', 403);
    }

    // Save user message
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, message) VALUES (?, 'user', ?)");
    $stmt->execute([$sessionId, $message]);

    // Bot reply: Phase 1 placeholder (RAG from blog later)
    $reply = getBotReplyPlaceholder($message);
    $sources = [['url' => 'https://smile.re-agent.info/blog/', 'title' => '戸建てリノベINFO']];

    // Save bot message
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, message) VALUES (?, 'bot', ?)");
    $stmt->execute([$sessionId, $reply]);

    // Update session last_seen
    $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);

    sendSuccessResponse([
        'reply' => $reply,
        'sources' => $sources,
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat send error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * Placeholder bot response until RAG is connected.
 * Includes disclaimer and optional suggestion for loan sim / tools.
 */
function getBotReplyPlaceholder($userMessage) {
    $disclaimer = "※参考情報です。個別のご相談は担当者までお問い合わせください。";
    $intro = "ご質問ありがとうございます。戸建てリノベINFOのコンテンツに基づく回答は現在準備中です。";
    $suggest = "不動産のご相談（購入・売却・リノベなど）や、ローンシミュレーションのご希望がございましたら、下のボタンからお試しください。";
    return $intro . "\n\n" . $suggest . "\n\n" . $disclaimer;
}

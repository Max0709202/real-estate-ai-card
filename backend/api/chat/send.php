<?php
/**
 * Send a message and get bot response.
 * POST { "session_id": "...", "message": "..." } -> { "reply", "sources" }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-helpers.php';
require_once __DIR__ . '/../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';

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
$visitorId = trim($input['visitor_id'] ?? '');
$buttonSelection = isset($input['button_selection']) && is_array($input['button_selection']) ? $input['button_selection'] : null;
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

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
    if ($visitorId !== '') {
        $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = COALESCE(visitor_identifier, ?) WHERE id = ?");
        $stmt->execute([$visitorId, $sessionId]);
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

    if ($buttonSelection !== null) {
        chatIntakeArchiveButtonSelection($db, $sessionId, $card['id'], $buttonSelection, $message);
    }

    // Save user message
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, message) VALUES (?, 'user', ?)");
    $stmt->execute([$sessionId, $message]);

    // Load recent conversation history (last 10 exchanges) for context
    $stmt = $db->prepare("
        SELECT role, message FROM chat_messages
        WHERE session_id = ? AND id < (SELECT MAX(id) FROM chat_messages WHERE session_id = ?)
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute([$sessionId, $sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $conversationHistory = array_reverse(array_map(function ($r) { return ['role' => $r['role'], 'message' => $r['message']]; }, $rows));

    $intake = processChatIntakeMessage($db, $sessionId, $card['id'], $message);
    $quickReplies = $intake['quick_replies'] ?? [];
    $leadData = $intake['data'] ?? null;

    if (!empty($intake['handled'])) {
        $reply = $intake['reply'];
        $sources = [];
    } else {
        $agentName = $card['name'] ?? '担当者';
        $result = getBotReplyWithOpenAI($message, $conversationHistory, $agentName, $db, $sessionId);

        if ($result['error'] !== null || $result['reply'] === null || $result['reply'] === '') {
            error_log('Chat OpenAI error: ' . ($result['error'] ?? 'empty reply'));
            $reply = getBotReplyPlaceholder($message);
            $sources = [['url' => CHAT_BLOG_BASE_URL, 'title' => '戸建てリノベINFO']];
        } else {
            $reply = $result['reply'];
            $sources = $result['sources'];
        }
    }

    // Save bot message
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, message) VALUES (?, 'bot', ?)");
    $stmt->execute([$sessionId, $reply]);

    // Update lightweight conversation memory for future turns and reload continuity
    updateChatSessionMemoryHeuristic($db, $sessionId, $card['id'], $message, $reply);

    // Update session last_seen
    $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);

    sendSuccessResponse([
        'reply' => $reply,
        'sources' => $sources,
        'quick_replies' => $quickReplies,
        'lead_data' => $leadData,
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
    $intro = "ご質問ありがとうございます。現在、AI回答の生成に失敗しました。";
    $suggest = "不動産のご相談（購入・売却・リノベ・制度確認など）は、条件により答えが変わります。担当者への確認やローンシミュレーションもあわせてご利用ください。";
    return $intro . "\n\n" . $suggest . "\n\n" . $disclaimer;
}

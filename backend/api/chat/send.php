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
require_once __DIR__ . '/../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';

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
$attachmentIds = isset($input['attachment_ids']) && is_array($input['attachment_ids']) ? $input['attachment_ids'] : [];
// 現在地（GPS）からの土地情報照会。緯度経度が来た場合のみ有効。日本国内のおおよその
// 範囲（緯度20〜46／経度122〜154）に収まる値だけ採用し、範囲外は通常メッセージ扱い。
$geo = null;
if (isset($input['latitude'], $input['longitude']) && is_numeric($input['latitude']) && is_numeric($input['longitude'])) {
    $lat = (float)$input['latitude'];
    $lon = (float)$input['longitude'];
    if ($lat >= 20 && $lat <= 46 && $lon >= 122 && $lon <= 154) {
        $geo = ['lat' => $lat, 'lon' => $lon];
    }
}
// channel: 'ai'（AIチャット, 既定） | 'contact'（担当連絡＝人間担当へ）
$channel = (($input['channel'] ?? '') === 'contact') ? 'contact' : 'ai';
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

if ($sessionId === '' || ($message === '' && empty($attachmentIds))) {
    sendErrorResponse('session_id and message are required', 400);
}
if ($message === '' && !empty($attachmentIds)) {
    $message = '[ファイルを送信しました]';
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT id, business_card_id, handoff_mode FROM chat_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    if ($visitorId !== '') {
        // 送信元の端末を現所有者に更新（poll/upload の visitor 突合と整合させる）。
        $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = ? WHERE id = ?");
        $stmt->execute([$visitorId, $sessionId]);
    }

    // 担当連絡チャネル：人間の担当者宛のメッセージ。AIの自動応答は行わず、
    // 顧客発言を担当への未読メッセージ（channel='contact'）として保存するだけにする。
    // AI担当チャネルとは独立しているため、AIは別途このチャネルを文脈として捕捉する。
    if ($channel === 'contact') {
        $userMessageId = agentMsgInsertMessage($db, $sessionId, 'user', $message, null, 'contact');
        if (!empty($attachmentIds)) {
            agentMsgAttachMessageId($db, $attachmentIds, $userMessageId, $sessionId, 'customer');
        }
        $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$sessionId]);
        // 担当営業へメール通知（60秒バッチ・未読中は抑制）。失敗は握りつぶす。
        notifyEnqueue($db, $sessionId, 'contact');
        sendSuccessResponse([
            'reply' => '',
            'agent_mode' => true,
            'message_id' => $userMessageId,
            'sources' => [],
            'quick_replies' => [],
        ], '担当者にお伝えしました');
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

    // Save user message (AIチャネル)
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, channel, message) VALUES (?, 'user', 'ai', ?)");
    $stmt->execute([$sessionId, $message]);
    if (!empty($attachmentIds)) {
        agentMsgAttachMessageId($db, $attachmentIds, (int)$db->lastInsertId(), $sessionId, 'customer');
    }

    // Give the model the actual conversation so it analyzes the user's own sentences rather than a
    // mechanical keyword digest. For recap/summary requests, load the whole conversation so "まとめて"
    // produces a real summary; otherwise use a generous recent window for continuity.
    // 両チャネル（ai / contact）を時系列で取り込み、担当連絡（人間担当との会話）も
    // AIの文脈として捕捉する。発言主体は role/channel で区別してプロンプト化する。
    $isRecapRequest = function_exists('chatIsRecapRequest') && chatIsRecapRequest($message);
    $historyLimit = $isRecapRequest ? 200 : 20;
    $stmt = $db->prepare("
        SELECT role, channel, message FROM chat_messages
        WHERE session_id = ? AND deleted_at IS NULL AND id < (SELECT MAX(id) FROM chat_messages WHERE session_id = ?)
        ORDER BY id DESC
        LIMIT " . (int)$historyLimit . "
    ");
    $stmt->execute([$sessionId, $sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $conversationHistory = array_reverse(array_map(function ($r) { return ['role' => $r['role'], 'channel' => $r['channel'], 'message' => $r['message']]; }, $rows));

    $quickReplies = [];
    $leadData = null;

    if ($geo !== null) {
        // 現在地（GPS）照会：intake／マンション名検索は通さず、緯度経度を土地情報の
        // 公的データ取得にそのまま渡してAI回答を生成する。
        $agentName = $card['name'] ?? '担当者';
        $result = getBotReplyWithOpenAI($message, $conversationHistory, $agentName, $db, $sessionId, $geo);
        if ($result['error'] !== null || $result['reply'] === null || $result['reply'] === '') {
            error_log('Chat OpenAI error (geo): ' . ($result['error'] ?? 'empty reply'));
            $reply = getBotReplyPlaceholder($message);
            $sources = [['url' => CHAT_BLOG_BASE_URL, 'title' => '戸建てリノベINFO']];
        } else {
            $reply = $result['reply'];
            $sources = $result['sources'];
        }
    } else {
    $intake = processChatIntakeMessage($db, $sessionId, $card['id'], $message, [
        'from_button' => $buttonSelection !== null,
        'button_selection' => $buttonSelection,
        'agent_name' => $card['name'] ?? '担当者',
    ]);
    $quickReplies = $intake['quick_replies'] ?? [];
    $leadData = $intake['data'] ?? null;

    if (!empty($intake['handled'])) {
        $reply = $intake['reply'];
        $sources = [];
    } else {
        $directMansionAnswer = chatMansionDbDirectAnswer($db, $message, $card['name'] ?? '担当者');
        if ($directMansionAnswer !== null) {
            $reply = $directMansionAnswer['reply'];
            $sources = $directMansionAnswer['sources'];
            if (!empty($directMansionAnswer['meta'])) {
                chatLogPublicDataAccess($db, $sessionId, (int)$card['id'], $message, $directMansionAnswer['meta']);
            }
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
    }
    }

    // Update lightweight conversation memory for future turns and reload continuity
    updateChatSessionMemoryHeuristic($db, $sessionId, $card['id'], $message, $reply);
    $crmCase = chatCrmSyncFromChatSession($db, $sessionId, (int)$card['id']);
    if ($crmCase && chatCrmConditionReminderDue($crmCase)) {
        $conditionReminder = chatCrmBuildConditionReminder($crmCase);
        if ($conditionReminder !== '') {
            $reply = rtrim($reply) . "\n\n" . $conditionReminder;
            chatCrmMarkConditionReminderShown($db, $sessionId, (int)$card['id']);
        }
    }

    // Save bot message after condition reminder has been appended. (AIチャネル)
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, channel, message) VALUES (?, 'bot', 'ai', ?)");
    $stmt->execute([$sessionId, $reply]);

    // Update session last_seen
    $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);

    sendSuccessResponse([
        'reply' => $reply,
        'sources' => $sources,
        'quick_replies' => $quickReplies,
        'lead_data' => $leadData,
        'sms_auth_required' => !empty($intake['sms_auth_required']),
        'sms_auth_phone' => $intake['sms_auth_phone'] ?? '',
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

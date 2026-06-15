<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-helpers.php';
require_once __DIR__ . '/../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

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
$mode = trim($input['mode'] ?? 'polish');

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id, bc.name AS card_holder_name, bc.company_name
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ? LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $case = chatCrmLoadCase($db, $sessionId, (int)$session['business_card_id']) ?: chatCrmDefaultCase();
    $history = loadRecentChatMessagesForResume($db, $sessionId, 12);
    $historyText = [];
    foreach ($history as $row) {
        $historyText[] = ($row['role'] === 'user' ? '顧客: ' : '担当者: ') . ($row['message'] ?? '');
    }

    $customerLabel = $case['customer_name'] ?: 'お客様';
    $agentName = $session['card_holder_name'] ?: '担当者';
    $leadSummary = chatCrmSummarizeConditions($case);
    $prompt = $mode === 'auto'
        ? "あなたは不動産営業の担当者です。顧客への自動返信文を1通作成してください。"
        : "あなたは不動産営業の担当者です。以下の下書きを自然で丁寧な顧客返信に整えてください。";
    $prompt .= "\n\n顧客名: {$customerLabel}\n担当者名: {$agentName}\n要約: {$leadSummary}\n\n直近履歴:\n" . implode("\n", array_slice($historyText, -6));
    if ($message !== '') {
        $prompt .= "\n\n対象メッセージ:\n" . $message;
    }
    $prompt .= "\n\n条件:\n- 短く、丁寧で、営業として自然な文面にする\n- 失礼な断定を避ける\n- 1通のメールやチャットとしてそのまま使える形にする\n- 箇条書きは必要な時だけ\n- 余計な注釈は入れない";

    $model = function_exists('chatOpenAIModelSales') ? chatOpenAIModelSales() : (defined('OPENAI_MODEL_SALES') ? OPENAI_MODEL_SALES : 'gpt-4o-mini');
    $apiKey = function_exists('chatOpenAIApiKeyForModel') ? chatOpenAIApiKeyForModel($model) : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: ''));

    $draft = '';
    if ($apiKey !== '') {
        $resp = callOpenAIChat([
            ['role' => 'system', 'content' => 'You write concise Japanese real-estate sales replies.'],
            ['role' => 'user', 'content' => $prompt],
        ], $apiKey, $model, [
            'db' => $db,
            'session_id' => $sessionId,
            'business_card_id' => (int)$session['business_card_id'],
            'purpose' => 'reply_draft',
        ]);
        if (empty($resp['error']) && !empty($resp['reply'])) {
            $draft = trim($resp['reply']);
        }
    }
    if ($draft === '') {
        $draft = "ご連絡ありがとうございます。\n\n" . ($leadSummary !== '' ? "現在のご希望は「{$leadSummary}」で把握しています。\n\n" : '') . "内容を確認のうえ、改めてご案内いたします。";
    }

    chatCrmUpsertCase($db, $sessionId, (int)$session['business_card_id'], [
        'deal_type' => $case['deal_type'],
        'customer_name' => $case['customer_name'],
        'ai_summary' => $case['ai_summary'],
        'conditions' => $case['conditions'],
        'progress' => $case['progress'],
        'properties' => $case['properties'],
        'schedules' => $case['schedules'],
        'contact' => array_merge($case['contact'], ['reply_draft' => $draft, 'updated_at' => chatCrmNowIso()]),
        'reply_draft' => [
            'source_message' => $message,
            'draft' => $draft,
            'mode' => $mode,
            'updated_at' => chatCrmNowIso(),
        ],
        'last_condition_reminder_at' => $case['last_condition_reminder_at'] ?? null,
    ]);

    sendSuccessResponse(['draft' => $draft], 'OK');
} catch (Exception $e) {
    error_log('chat reply draft error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

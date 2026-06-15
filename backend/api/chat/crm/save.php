<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../../includes/chat-rag-helper.php';
require_once __DIR__ . '/../../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../middleware/auth.php';

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
$feature = trim($input['feature'] ?? '');
$payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : [];

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($feature === '') {
    sendErrorResponse('feature is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id, bc.user_id
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $case = chatCrmLoadCase($db, $sessionId, (int)$session['business_card_id']) ?: chatCrmDefaultCase();
    $feature = strtolower($feature);

    if ($feature === 'conditions') {
        $case['deal_type'] = chatCrmNormalizeDealType($payload['deal_type'] ?? $case['deal_type']);
        $case['conditions'] = chatCrmDecodeJsonValue($payload['conditions'] ?? null, $case['conditions']);
        $case['ai_summary'] = trim((string)($payload['ai_summary'] ?? $case['ai_summary']));
        $case['customer_name'] = trim((string)($payload['customer_name'] ?? $case['customer_name']));
    } elseif ($feature === 'progress') {
        $case['progress'] = chatCrmDecodeJsonValue($payload['progress'] ?? null, $case['progress']);
        $case['deal_type'] = chatCrmNormalizeDealType($payload['deal_type'] ?? $case['deal_type']);
    } elseif ($feature === 'properties') {
        $case['properties'] = chatCrmDecodeJsonValue($payload['properties'] ?? null, $case['properties']);
        $case['deal_type'] = chatCrmNormalizeDealType($payload['deal_type'] ?? $case['deal_type']);
    } elseif ($feature === 'schedules') {
        $case['schedules'] = chatCrmDecodeJsonValue($payload['schedules'] ?? null, $case['schedules']);
        $case['deal_type'] = chatCrmNormalizeDealType($payload['deal_type'] ?? $case['deal_type']);
    } elseif ($feature === 'contact') {
        $case['contact'] = chatCrmDecodeJsonValue($payload['contact'] ?? null, $case['contact']);
        $case['reply_draft'] = chatCrmDecodeJsonValue($payload['reply_draft'] ?? null, $case['reply_draft']);
    } elseif ($feature === 'reply_draft') {
        $case['reply_draft'] = chatCrmDecodeJsonValue($payload['reply_draft'] ?? null, $case['reply_draft']);
    } elseif ($feature === 'sync') {
        $synced = chatCrmSyncFromChatSession($db, $sessionId, (int)$session['business_card_id']);
        if ($synced) {
            $case = $synced;
        }
    } else {
        sendErrorResponse('feature is invalid', 422);
    }

    if ($feature !== 'sync') {
        $case['progress']['deal_type'] = $case['deal_type'];
        $manualOverrides = $case['progress']['manual_overrides'] ?? [];
        if ($case['deal_type'] === 'sale') {
            $case['progress']['stages'] = chatCrmCalculateSaleStages($case['progress']['target_date'] ?? null, $manualOverrides);
        } else {
            $case['progress']['stages'] = chatCrmCalculatePurchaseStages($case['progress']['target_date'] ?? null, $manualOverrides);
        }
        $case['progress']['progress_percent'] = chatCrmProgressPercent($case['progress']['stages'], $case['progress']['target_date'] ?? null);
        $case['progress']['current_stage'] = $case['progress']['current_stage'] ?? '';
        $case['progress']['updated_at'] = chatCrmNowIso();
        $case['updated_at'] = chatCrmNowIso();
        chatCrmUpsertCase($db, $sessionId, (int)$session['business_card_id'], $case);
    }

    $fresh = chatCrmLoadCase($db, $sessionId, (int)$session['business_card_id']);
    $fresh['conditions_summary'] = chatCrmSummarizeConditions($fresh);
    $fresh['purchase_schedule'] = chatCrmCalculatePurchaseStages($fresh['progress']['target_date'] ?? null, $fresh['progress']['manual_overrides'] ?? []);
    $fresh['sale_schedule'] = chatCrmCalculateSaleStages($fresh['progress']['target_date'] ?? null, $fresh['progress']['manual_overrides'] ?? []);
    $fresh['tools'] = [];
    $stmt = $db->prepare("SELECT tool_type, tool_url, display_order, is_active FROM tech_tool_selections WHERE business_card_id = ? AND is_active = 1 ORDER BY display_order ASC");
    $stmt->execute([(int)$session['business_card_id']]);
    $fresh['tools'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    sendSuccessResponse(['case' => $fresh], 'OK');
} catch (Exception $e) {
    error_log('chat crm save error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


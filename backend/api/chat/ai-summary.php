<?php
/**
 * Generate (or return cached) structured sales summary for a chat session.
 * POST { session_id, refresh? }  — My Page (agent) use only.
 * The session's business_card must belong to the current user.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../includes/chat-sales-summary-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$forceRefresh = !empty($input['refresh']);

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT cs.id, cs.business_card_id
        FROM chat_sessions cs
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE cs.id = ? AND bc.user_id = ? LIMIT 1
    ");
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }

    $businessCardId = (int)$session['business_card_id'];

    $stmt = $db->prepare("SELECT structured_data FROM chat_leads WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $leadRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $structuredLead = [];
    if ($leadRow && !empty($leadRow['structured_data'])) {
        $decoded = json_decode($leadRow['structured_data'], true);
        if (is_array($decoded)) $structuredLead = $decoded;
    }

    $case = chatCrmLoadCase($db, $sessionId, $businessCardId) ?: chatCrmDefaultCase();

    $result = chatSalesSummaryResolve($db, $sessionId, $businessCardId, $case, $structuredLead, $forceRefresh);

    if ($result === null) {
        sendErrorResponse('要約を作成できませんでした。時間をおいて再度お試しください。', 502);
    }

    sendSuccessResponse([
        'summary' => $result['summary'],
        'model' => $result['model'],
        'cached' => $result['cached'],
    ], 'OK');
} catch (Exception $e) {
    error_log('chat ai summary error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

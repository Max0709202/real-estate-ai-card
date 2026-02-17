<?php
/**
 * Save lead / intake data (structured answers from hearing).
 * POST { "session_id", "structured_data": {...}, "consent": true }
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
$sessionId = trim($input['session_id'] ?? '');
$structuredData = $input['structured_data'] ?? [];
$consent = !empty($input['consent']);

if ($sessionId === '') {
    sendErrorResponse('session_id is required', 400);
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

    $json = json_encode($structuredData, JSON_UNESCAPED_UNICODE);
    $consentInt = $consent ? 1 : 0;

    $stmt = $db->prepare("
        INSERT INTO chat_leads (session_id, business_card_id, structured_data, consent_given)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE structured_data = VALUES(structured_data), consent_given = VALUES(consent_given), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$sessionId, $session['business_card_id'], $json, $consentInt]);

    sendSuccessResponse(['saved' => true], '保存しました');
} catch (Exception $e) {
    error_log('Chat lead save error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

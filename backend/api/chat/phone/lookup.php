<?php
/**
 * Check whether a chat phone is already registered before sending an SMS.
 * This endpoint intentionally returns no customer/session details because it
 * is called before SMS authentication.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
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

    $found = chatFindSessionByVerifiedPhone($db, (int)$card['id'], $phone);
    sendSuccessResponse([
        'registered' => $found && !empty($found['session_id']),
        'sms_required' => !($found && !empty($found['session_id'])),
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat phone lookup error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

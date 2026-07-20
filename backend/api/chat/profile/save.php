<?php
/**
 * Save the customer's name / email entered up-front during chat registration.
 * POST { session_id, card_slug, name?, last_name?, first_name?, email? }
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/chat-helpers.php';
require_once __DIR__ . '/../../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../../includes/customer-invitation-helper.php';

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
$cardSlug = trim($input['card_slug'] ?? '');
$name = trim($input['name'] ?? '');
$lastName = trim($input['last_name'] ?? '');
$firstName = trim($input['first_name'] ?? '');
$email = trim($input['email'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('セッションが正しくありません。', 400);
}
if ($cardSlug === '') {
    sendErrorResponse('card_slug is required', 400);
}
if ($name === '' && ($lastName !== '' || $firstName !== '')) {
    $name = trim($lastName . ' ' . $firstName);
}
if ($name === '' && $email === '') {
    sendErrorResponse('登録する情報がありません。', 400);
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
    $businessCardId = (int)$card['id'];

    $stmt = $db->prepare("SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1");
    $stmt->execute([$sessionId, $businessCardId]);
    if (!$stmt->fetchColumn()) {
        sendErrorResponse('セッションが見つかりません。', 404);
    }

    $data = chatIntakeLoad($db, $sessionId, $businessCardId);

    if ($name !== '') {
        [$ln, $fn] = chatIntakeParseNameParts($name);
        if ($ln === '' || $fn === '') {
            sendErrorResponse('姓と名の間にスペースを入れてご入力ください。（例：山田 太郎）', 422);
        }
        chatIntakeSetField($data, 'contact_name', $name, ['source' => 'typed']);
    }
    if ($email !== '') {
        if (chatIntakeExtractEmail($email) === null) {
            sendErrorResponse('メールアドレスの形式をご確認ください。（例：yamada@example.com）', 422);
        }
        chatIntakeSetField($data, 'contact_email', $email, ['source' => 'typed']);
    }

    $data['_current_field'] = chatIntakeNextField($data);
    chatIntakeSave($db, $sessionId, $businessCardId, $data);

    // 事前作成された顧客ページの場合、本人の登録が済んだ時点で招待を完了扱いにする。
    // 以降は招待の申告値ではなく、ここで登録された氏名・メールアドレスが正となる。
    if (chatIntakeProfileComplete($data) && function_exists('customerInviteMarkRegistered')) {
        customerInviteMarkRegistered($db, $sessionId);
    }

    sendSuccessResponse([
        'customer_name' => $data['customer_name'] ?? '',
        'has_name' => chatIntakeHasCustomerName($data),
        'has_email' => chatIntakeHasCustomerEmail($data),
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat profile save error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

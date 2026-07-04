<?php
/**
 * Web Push 購読の登録/更新（顧客側 card.php PWA から呼ばれる）。
 * POST(JSON) {
 *   session_id, visitor_id?,
 *   subscription: { endpoint, keys: { p256dh, auth } }
 * }
 * endpoint 一意で upsert する。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendErrorResponse('Method not allowed', 405); }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$sub = isset($input['subscription']) && is_array($input['subscription']) ? $input['subscription'] : [];
$endpoint = trim($sub['endpoint'] ?? '');
$keys = isset($sub['keys']) && is_array($sub['keys']) ? $sub['keys'] : [];
$p256dh = trim($keys['p256dh'] ?? '');
$auth = trim($keys['auth'] ?? '');

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($endpoint === '' || !preg_match('#^https://#', $endpoint) || strlen($endpoint) > 700) {
    sendErrorResponse('valid subscription endpoint is required', 400);
}
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

try {
    $db = (new Database())->getConnection();

    // セッション検証（customer/poll.php と同様。visitor登録済みなら突合を要求）。
    $stmt = $db->prepare("SELECT id, business_card_id, visitor_identifier FROM chat_sessions WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    // 同一電話番号でSMS認証済みの別端末も許可する（複数端末での購読登録）。
    if (!chatSessionVisitorAuthorized($db, $sessionId, $visitorId, $session['visitor_identifier'])) {
        sendErrorResponse('セッションを確認できません', 403);
    }

    $cardId = $session['business_card_id'] !== null ? (int)$session['business_card_id'] : null;
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $db->prepare(
        "INSERT INTO push_subscriptions
           (session_id, business_card_id, visitor_id, endpoint, p256dh, auth, ua)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           session_id = VALUES(session_id),
           business_card_id = VALUES(business_card_id),
           visitor_id = VALUES(visitor_id),
           p256dh = VALUES(p256dh),
           auth = VALUES(auth),
           ua = VALUES(ua)"
    );
    $stmt->execute([$sessionId, $cardId, ($visitorId !== '' ? $visitorId : null), $endpoint, ($p256dh !== '' ? $p256dh : null), ($auth !== '' ? $auth : null), $ua]);

    sendSuccessResponse(['subscribed' => true], 'OK');
} catch (Exception $e) {
    error_log('push subscribe error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

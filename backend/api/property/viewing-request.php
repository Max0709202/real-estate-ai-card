<?php
/**
 * 物件選定: 内見予約を依頼する（§17）。
 * 顧客が物件詳細から内見予約を依頼。担当連絡（channel='contact'）へメッセージを投稿し、
 * 物件ステータスを「内見希望」に更新する。日程連絡機能（担当連絡）と連動。
 * POST(JSON) { property_id, session_id, visitor_id, note? }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$sessionId = trim($input['session_id'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$note = trim((string)($input['note'] ?? ''));
if ($propertyId <= 0 || $sessionId === '' || $visitorId === '') sendErrorResponse('パラメータが不足しています', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    propertyVerifyCustomerSession($db, $sessionId, $visitorId);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? AND session_id = ? LIMIT 1");
    $stmt->execute([$propertyId, $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendErrorResponse('物件が見つかりません', 404);

    $name = trim((string)($row['building_name'] ?: $row['property_name'] ?: '物件'));
    $price = trim((string)($row['price_text'] ?? ''));
    $addr = trim((string)($row['address'] ?? ''));

    $msg = "【内見予約のご依頼】\n物件: " . $name;
    if ($price !== '') $msg .= "（" . $price . "）";
    if ($addr !== '') $msg .= "\n所在地: " . $addr;
    $msg .= "\nこちらの物件の内見を希望します。ご都合の良い日程をご連絡ください。";
    if ($note !== '') $msg .= "\n\n（ご希望・備考）\n" . mb_substr($note, 0, 500);

    // 担当連絡チャネルへ顧客発言として投稿
    $messageId = agentMsgInsertMessage($db, $sessionId, 'user', $msg, null, 'contact');
    $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$sessionId]);

    // 物件ステータスを内見希望に
    $db->prepare("UPDATE properties SET status = 'viewing_request' WHERE id = ?")->execute([$propertyId]);

    // 顧客の物件共有（内見依頼）→ 担当営業へメール通知。
    notifyEnqueue($db, $sessionId, 'property');

    sendSuccessResponse(['message_id' => $messageId], '内見予約を依頼しました。担当連絡をご確認ください。');
} catch (Exception $e) {
    error_log('property viewing-request error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

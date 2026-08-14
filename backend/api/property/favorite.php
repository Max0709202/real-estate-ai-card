<?php
/**
 * 物件選定: お気に入り（ハート）の切り替え。
 * 顧客が気になる物件のハートを押すと is_favorite を更新し、一覧の「お気に入り」で絞り込めるようにする。
 * POST(JSON) { property_id, session_id, visitor_id, favorite }
 *  - 顧客のみ利用（SMS認証前の閲覧トークンでは利用不可）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$sessionId = trim($input['session_id'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$favorite = !empty($input['favorite']) && $input['favorite'] !== 'false' ? 1 : 0;
if ($propertyId <= 0 || $sessionId === '' || $visitorId === '') sendErrorResponse('パラメータが不足しています', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    propertyVerifyCustomerSession($db, $sessionId, $visitorId);

    // 自分のセッションの物件だけ更新できる。
    $stmt = $db->prepare("SELECT id FROM properties WHERE id = ? AND session_id = ? LIMIT 1");
    $stmt->execute([$propertyId, $sessionId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) sendErrorResponse('物件が見つかりません', 404);

    $db->prepare("UPDATE properties SET is_favorite = ? WHERE id = ?")->execute([$favorite, $propertyId]);

    sendSuccessResponse(
        ['property_id' => $propertyId, 'is_favorite' => $favorite],
        $favorite ? 'お気に入りに追加しました' : 'お気に入りを解除しました'
    );
} catch (Exception $e) {
    error_log('property favorite error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

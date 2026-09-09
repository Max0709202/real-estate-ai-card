<?php
/**
 * 物件選定: 「写真・資料」の名前（建物外観などの分類ラベル）の変更（担当のみ）。
 * AIが自動で付けた名前を、担当者が自由に付け直せるようにする。
 * POST(JSON) { image_id, subcategory }   subcategory が空なら名前なし
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$imageId = isset($input['image_id']) ? (int)$input['image_id'] : 0;
if ($imageId <= 0) sendErrorResponse('image_id is required', 400);
$label = propertyNormalizePhotoLabel($input['subcategory'] ?? '');

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    // 所有検証（画像→物件→名刺→user）。名前を変更できるのは「写真・資料」のみ。
    $stmt = $db->prepare("
        SELECT pi.id FROM property_images pi
        JOIN business_cards bc ON bc.id = pi.business_card_id
        WHERE pi.id = ? AND bc.user_id = ? AND pi.category = 'photo' LIMIT 1
    ");
    $stmt->execute([$imageId, $userId]);
    if (!$stmt->fetchColumn()) sendErrorResponse('写真が見つかりません', 404);

    $db->prepare("UPDATE property_images SET subcategory = ? WHERE id = ?")->execute([$label, $imageId]);

    sendSuccessResponse(['image_id' => $imageId, 'subcategory' => $label], '名前を変更しました');
} catch (Exception $e) {
    error_log('property image-label error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

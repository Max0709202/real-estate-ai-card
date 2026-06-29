<?php
/**
 * 物件選定: 販売図面の顧客公開可否の切替（担当のみ）。
 * 編集・確認が完了した販売図面を顧客に公開（visible=1）、または公開停止（visible=0）する。
 * 公開にはマスク済PDFが生成済み（mask_status='masked'）であることが必要。
 * POST(JSON) { image_id, visible: 0|1 }
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
$visible = !empty($input['visible']) ? 1 : 0;
if ($imageId <= 0) sendErrorResponse('image_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);
    $img = propertyFlyerVerifyAgentImage($db, $imageId, $userId);

    if ($visible === 1) {
        if (($img['mask_status'] ?? 'none') !== 'masked' || empty($img['masked_path'])) {
            sendErrorResponse('先に「マスク編集」で顧客用PDFを作成してください', 400);
        }
    }

    $db->prepare("UPDATE property_images SET customer_visible = ? WHERE id = ?")->execute([$visible, $imageId]);

    sendSuccessResponse(['image_id' => $imageId, 'customer_visible' => $visible],
        $visible ? '顧客に公開しました' : '顧客への公開を停止しました');
} catch (Exception $e) {
    error_log('property flyer-visibility error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

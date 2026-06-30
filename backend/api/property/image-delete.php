<?php
/**
 * 物件選定: 物件画像の削除（エージェントのみ）。
 * POST(JSON) { image_id }
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

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    // 所有検証（画像→物件→名刺→user）
    $stmt = $db->prepare("
        SELECT pi.id, pi.property_id, pi.stored_path, pi.thumb_path,
               pi.preview_path, pi.masked_path, pi.masked_thumb_path
        FROM property_images pi
        JOIN properties p ON p.id = pi.property_id
        JOIN business_cards bc ON bc.id = pi.business_card_id
        WHERE pi.id = ? AND bc.user_id = ? LIMIT 1
    ");
    $stmt->execute([$imageId, $userId]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$img) sendErrorResponse('画像が見つかりません', 404);

    // 関連ファイル（原本・サムネ・プレビュー・顧客用マスクPDF・マスクサムネ）をすべて削除
    foreach (['stored_path', 'thumb_path', 'preview_path', 'masked_path', 'masked_thumb_path'] as $k) {
        if (!empty($img[$k])) {
            $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($img[$k], '/');
            if (is_file($abs)) @unlink($abs);
        }
    }
    $db->prepare("DELETE FROM property_images WHERE id = ?")->execute([$imageId]);

    // 一覧サムネイルに使われていた写真なら参照を解除する
    $db->prepare("UPDATE properties SET thumbnail_image_id = NULL WHERE id = ? AND thumbnail_image_id = ?")
       ->execute([(int)$img['property_id'], $imageId]);

    sendSuccessResponse([], '削除しました');
} catch (Exception $e) {
    error_log('property image-delete error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

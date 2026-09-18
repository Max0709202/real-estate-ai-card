<?php
/**
 * 物件選定: 物件削除（エージェントのみ）。
 * POST(JSON) { property_id }
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
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $row = propertyVerifyAgentProperty($db, $propertyId, $userId);

    // 画像ファイルを物理削除。
    // ただし体験版（デモ）名刺では、見本の物件を体験者ごとのセッションへ複製しており、
    // 複製した物件行は見本と同じ実ファイルを参照している。他の物件からも参照されている
    // ファイルを消すと見本側の販売図面・写真まで失われるため、参照が残っている間は消さない。
    $sharedCheck = $db->prepare("SELECT COUNT(*) FROM property_images
                                 WHERE property_id <> ?
                                   AND (stored_path = ? OR thumb_path = ? OR preview_path = ?
                                        OR masked_path = ? OR masked_thumb_path = ?)");
    foreach (propertyImagesFor($db, $propertyId) as $img) {
        $stmt = $db->prepare("SELECT stored_path, thumb_path FROM property_images WHERE id = ?");
        $stmt->execute([(int)$img['id']]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($f) {
            foreach (['stored_path', 'thumb_path'] as $k) {
                if (!empty($f[$k])) {
                    $rel = (string)$f[$k];
                    $sharedCheck->execute([$propertyId, $rel, $rel, $rel, $rel, $rel]);
                    if ((int)$sharedCheck->fetchColumn() > 0) continue;
                    $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($rel, '/');
                    if (is_file($abs)) @unlink($abs);
                }
            }
        }
    }

    $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$propertyId]);
    sendSuccessResponse([], '削除しました');
} catch (Exception $e) {
    error_log('property delete error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

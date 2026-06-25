<?php
/**
 * 物件選定: ハザード等情報の取得・保存（§12 / §13）。
 * 一度取得したら properties.hazard_json に保存し、次回以降は保存済みを返す。
 * force=1 で再取得。エージェントのみ取得を実行できる（顧客は保存済み参照のみ）。
 * POST(JSON) { property_id, force? }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/chat-public-data-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$force = !empty($input['force']);
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $row = propertyVerifyAgentProperty($db, $propertyId, $userId);

    // 保存済みがあり、再取得指定が無ければそれを返す（§13）
    if (!$force && !empty($row['hazard_json'])) {
        sendSuccessResponse([
            'hazard' => json_decode($row['hazard_json'], true),
            'fetched_at' => $row['hazard_fetched_at'],
            'cached' => 1,
        ], 'OK');
    }

    $address = trim((string)($row['address'] ?? ''));
    if ($address === '') sendErrorResponse('所在地が未登録のため取得できません', 400);

    $report = chatHazardAddressReport($db, $address);
    if (!is_array($report)) sendErrorResponse('ハザード情報を取得できませんでした', 502);

    $json = json_encode($report, JSON_UNESCAPED_UNICODE);
    $db->prepare("UPDATE properties SET hazard_json = ?, hazard_fetched_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$json, $propertyId]);

    $stmt = $db->prepare("SELECT hazard_fetched_at FROM properties WHERE id = ?");
    $stmt->execute([$propertyId]);
    $fetchedAt = $stmt->fetchColumn();

    sendSuccessResponse(['hazard' => $report, 'fetched_at' => $fetchedAt, 'cached' => 0], '取得しました');
} catch (Exception $e) {
    error_log('property hazard error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

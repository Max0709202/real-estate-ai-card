<?php
/**
 * 物件選定: 物件詳細（§9-§15）。
 * GET ?id=&session_id=&visitor_id=
 *  - 顧客: 自分のセッションの物件のみ（売主情報非表示）
 *  - 担当: 自分の名刺の物件（全情報）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$propertyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
if ($propertyId <= 0) sendErrorResponse('id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendErrorResponse('物件が見つかりません', 404);

    $forAgent = false;
    if ($visitorId !== '') {
        propertyVerifyCustomerSession($db, $row['session_id'], $visitorId);
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentProperty($db, $propertyId, $userId);
        $forAgent = true;
    }

    sendSuccessResponse(['property' => propertySerialize($db, $row, $forAgent, true)], 'OK');
} catch (Exception $e) {
    error_log('property get error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

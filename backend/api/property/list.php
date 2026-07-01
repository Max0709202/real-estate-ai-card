<?php
/**
 * 物件選定: 提案物件一覧（§1）。
 * GET ?session_id=&visitor_id=
 *  - 顧客（visitor_id あり）: 自分のセッションの物件一覧（売主情報は非表示）
 *  - 担当（ログイン）: 当該セッションの物件一覧（全情報）
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';
require_once __DIR__ . '/../../includes/customer-notification-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
if ($sessionId === '') sendErrorResponse('session_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $forAgent = false;
    if ($visitorId !== '') {
        propertyVerifyCustomerSession($db, $sessionId, $visitorId);
        // 顧客が物件選定画面を開いた → 顧客向けメール通知の未読解除（送信前ならキャンセル）。
        customerNotifyMarkRead($db, $sessionId, 'property');
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentSession($db, $sessionId, $userId);
        $forAgent = true;
        // 担当営業が物件選定画面を開いた → メール通知の未読解除。
        notifyMarkRead($db, $sessionId, 'property');
    }

    $stmt = $db->prepare("SELECT * FROM properties WHERE session_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$sessionId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = propertySerialize($db, $row, $forAgent, false);
    }
    sendSuccessResponse(['properties' => $items, 'is_agent' => $forAgent ? 1 : 0], 'OK');
} catch (Exception $e) {
    error_log('property list error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

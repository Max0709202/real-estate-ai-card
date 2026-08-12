<?php
/**
 * 物件選定: 物件詳細（§9-§15）。
 * GET ?id=&session_id=&visitor_id=  /  GET ?id=&view_token=
 *  - 顧客: 自分のセッションの物件のみ（売主情報非表示）
 *  - 顧客（view_token）: 物件提案メールのリンクから来た未認証の閲覧（読み取り専用・売主情報非表示）
 *  - 担当: 自分の名刺の物件（全情報）
 * 顧客が詳細を開いたときは閲覧回数を記録し（担当の一覧に表示する。顧客には返さない）、
 * 担当エージェントへ閲覧をメール通知する（前回通知から3時間以内は再通知しない）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/property-view-notify-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$propertyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
// 物件提案メールのリンク（card.php?...&open=property&pv=<token>）から来た未認証の閲覧。
$viewToken = trim($_GET['view_token'] ?? '');
if ($propertyId <= 0) sendErrorResponse('id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendErrorResponse('物件が見つかりません', 404);

    $forAgent = false;
    if ($viewToken !== '') {
        // トークンは「そのトークンを発行した顧客に提案された物件」だけを開ける。
        if (propertyViewTokenSession($db, $viewToken) !== (string)$row['session_id']) {
            sendErrorResponse('アクセス権がありません', 403);
        }
    } elseif ($visitorId !== '') {
        propertyVerifyCustomerSession($db, $row['session_id'], $visitorId);
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentProperty($db, $propertyId, $userId);
        $forAgent = true;
    }

    // 顧客が物件詳細を表示した → 閲覧回数を1回加算（同一顧客・同一物件の短時間の再閲覧は1回）。
    if (!$forAgent) {
        propertyViewRecord($db, $propertyId, (string)$row['session_id']);
        // 担当エージェントへ閲覧を即時メール通知する（前回通知から3時間以内は送らない）。
        // メール送信で顧客の画面表示を待たせないよう、レスポンスを返してから実行する。
        register_shutdown_function(function () use ($db, $row) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            propertyViewNotifyOnView($db, $row);
        });
    }

    sendSuccessResponse(['property' => propertySerialize($db, $row, $forAgent, true)], 'OK');
} catch (Exception $e) {
    error_log('property get error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

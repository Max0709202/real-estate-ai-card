<?php
/**
 * 内見日程調整: 案件の状態を取得する（§4〜§8）。
 * GET ?property_id=&session_id=&visitor_id=  /  ?property_id=&view_token=  /  ?property_id=（担当ログイン）
 *
 * 内見依頼がない物件には内見カレンダーを表示しないため、案件が無い場合 viewing は null を返す。
 */
require_once __DIR__ . '/viewing-common.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$propertyId = isset($_GET['property_id']) ? (int)$_GET['property_id'] : 0;
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    $auth = viewingApiAuthorize($db, $propertyId, $_GET);
    $property = $auth['property'];
    $case = viewingFindByPropertySession($db, $propertyId, (string)$property['session_id']);
    sendSuccessResponse(viewingApiCasePayload($db, $case, $property, $auth['role']));
} catch (Exception $e) {
    error_log('viewing-get error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

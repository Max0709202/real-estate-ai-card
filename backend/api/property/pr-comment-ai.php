<?php
/**
 * 物件選定: PRコメントのAI生成（担当者のみ）。
 * 物件資料・登録情報をもとに、その物件ならではの特徴を3〜5個選んだ250〜350字程度の紹介文を作る。
 * 生成した文章はここでは保存せず、画面の入力欄に表示して担当者が加筆・修正してから
 * pr-comment.php で保存する（「再生成」は previous を渡して別の切り口で作り直す）。
 * POST(JSON) { property_id, current? }
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
// 入力欄に既に文章がある状態で押された「再生成」。前回分を避けた文章を作る。
$current = trim((string)($input['current'] ?? ''));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $row = propertyVerifyAgentProperty($db, $propertyId, $userId);

    $result = propertyGeneratePrComment($db, $row, ['previous' => $current]);
    if (empty($result['comment'])) {
        sendErrorResponse($result['error'] ?: 'PRコメントを生成できませんでした', 422);
    }

    sendSuccessResponse([
        'pr_comment' => $result['comment'],
        'length' => mb_strlen($result['comment']),
        'regenerated' => $current !== '' ? 1 : 0,
    ], 'PRコメントを生成しました');
} catch (Exception $e) {
    error_log('property pr-comment-ai error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

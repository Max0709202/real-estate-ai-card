<?php
/**
 * 物件選定: PRコメントのAI生成（担当者のみ）。
 * 物件資料・登録情報をもとに、①住戸固有の魅力 ②マンション全体の魅力 ③立地・周辺環境 を
 * AI自身が分析し、訴求力の高いポイントを3〜5個選んで営業コメントに仕立てる。
 * 何を一番に伝えるか・どの順番か・どう書き出すか・どの軸で語るかまでAIが判断するため、
 * 物件ごとに文章の構成そのものが変わる。ほかの物件のコメントと似すぎる場合は自動で書き直す。
 * 生成した文章はここでは保存せず、画面の入力欄に表示して担当者が加筆・修正してから
 * pr-comment.php で保存する（「再生成」は current を渡して別の切り口で作り直す）。
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

// 分析→執筆→点検（似すぎ・定型表現なら書き直し）と複数回APIを呼ぶため、実行時間に余裕を持たせる。
@set_time_limit(180);

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

    // AIが選んだ訴求ポイント（担当者が根拠を確認できるよう画面に表示する）。
    $points = [];
    foreach ((array)($result['plan']['selected'] ?? []) as $sel) {
        if (!is_array($sel) || trim((string)($sel['point'] ?? '')) === '') continue;
        $cat = (string)($sel['category'] ?? '');
        $points[] = [
            'point' => trim((string)$sel['point']),
            'category' => in_array($cat, ['unit', 'building', 'location'], true) ? $cat : '',
        ];
    }

    sendSuccessResponse([
        'pr_comment' => $result['comment'],
        'length' => mb_strlen($result['comment']),
        'regenerated' => $current !== '' ? 1 : 0,
        'focus' => (string)($result['plan']['focus'] ?? ''),
        'points' => $points,
    ], 'PRコメントを生成しました');
} catch (Exception $e) {
    error_log('property pr-comment-ai error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

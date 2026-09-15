<?php
/**
 * 物件選定: PRコメントのブラッシュアップ（担当者のみ）。
 * 担当者が入力欄に書いた文章を、内容はそのままに誤字・言い回しだけ整えて返す。
 * （「AIでPRコメントを生成」が文章を作るのに対し、こちらは書いた文章を推敲する）
 * 結果はここでは保存せず、画面でプレビューして担当者が採用したものだけ
 * pr-comment.php で保存する。
 * POST(JSON) { property_id, text }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

@set_time_limit(120);

startSessionIfNotStarted();
$userId = requireAuth();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$text = trim((string)($input['text'] ?? ''));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);
if ($text === '') sendErrorResponse('ブラッシュアップする文章を入力してください', 400);

$max = propertyPrCommentMaxLength();
if (mb_strlen($text) > $max) sendErrorResponse('PRコメントは' . $max . '字以内で入力してください', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $row = propertyVerifyAgentProperty($db, $propertyId, $userId);

    $result = propertyPolishPrComment($db, $row, $text);
    if (empty($result['comment'])) {
        sendErrorResponse($result['error'] ?: 'ブラッシュアップできませんでした', 422);
    }

    sendSuccessResponse([
        'pr_comment' => $result['comment'],
        'length' => mb_strlen($result['comment']),
        'changed' => $result['comment'] === $text ? 0 : 1,
    ], 'ブラッシュアップしました');
} catch (Exception $e) {
    error_log('property pr-comment-polish error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

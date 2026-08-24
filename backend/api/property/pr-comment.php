<?php
/**
 * 物件選定: PRコメントの保存・削除（担当者のみ）。
 * AI生成・手入力のどちらでも、最終的に担当者が確認・編集した文章をここで保存する。
 * 保存した文章はお客様の物件詳細に表示される（追加・編集・削除がいつでも可能）。
 * POST(JSON) { property_id, pr_comment, source? }   … 追加・編集
 * POST(JSON) { property_id, action: 'delete' }      … 削除
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
$action = trim((string)($input['action'] ?? 'save'));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    propertyVerifyAgentProperty($db, $propertyId, $userId);

    if ($action === 'delete') {
        $db->prepare("UPDATE properties SET pr_comment = NULL, pr_comment_source = NULL, pr_comment_updated_at = NULL WHERE id = ?")
           ->execute([$propertyId]);
        sendSuccessResponse([
            'pr_comment' => null,
            'pr_comment_source' => null,
            'pr_comment_updated_at' => null,
        ], 'PRコメントを削除しました');
    }

    $comment = trim((string)($input['pr_comment'] ?? ''));
    if ($comment === '') sendErrorResponse('PRコメントを入力してください', 400);
    $max = propertyPrCommentMaxLength();
    if (mb_strlen($comment) > $max) sendErrorResponse('PRコメントは' . $max . '字以内で入力してください', 400);

    // 入力方法（担当画面の表示用）。想定外の値は手入力として扱う。
    $source = (string)($input['source'] ?? 'manual');
    if (!in_array($source, ['manual', 'ai', 'ai_edited'], true)) $source = 'manual';

    $now = date('Y-m-d H:i:s');
    $db->prepare("UPDATE properties SET pr_comment = ?, pr_comment_source = ?, pr_comment_updated_at = ? WHERE id = ?")
       ->execute([$comment, $source, $now, $propertyId]);

    sendSuccessResponse([
        'pr_comment' => $comment,
        'pr_comment_source' => $source,
        'pr_comment_updated_at' => $now,
        'length' => mb_strlen($comment),
    ], 'PRコメントを保存しました');
} catch (Exception $e) {
    error_log('property pr-comment error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

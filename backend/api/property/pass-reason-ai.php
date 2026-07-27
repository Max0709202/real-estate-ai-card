<?php
/**
 * 物件選定: 見送り理由のAI所見を取得・生成（担当者のみ・管理画面に掲出）。
 * 顧客が選んだ見送り理由（複数）＋自由入力＋物件情報をAIに読ませ、
 * 担当者向けの所見（NG理由の要約と次回提案のヒント）を生成して properties.pass_reason_ai に保存する。
 * 一度生成したら保存済みを返し、理由が変わると status.php 側で NULL クリアされ再生成される。
 * POST(JSON) { property_id, force? }
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
$force = !empty($input['force']);
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $row = propertyVerifyAgentProperty($db, $propertyId, $userId);

    // 見送り以外・理由なしのときは所見なし。
    $hasReason = (trim((string)($row['pass_reason'] ?? '')) !== '') || (trim((string)($row['pass_reason_text'] ?? '')) !== '');
    if (($row['status'] ?? '') !== 'passed' || !$hasReason) {
        sendSuccessResponse(['pass_reason_ai' => null], 'OK');
    }

    // 生成済みがあり、再生成指定が無ければそれを返す（キャッシュ）。
    if (!$force && !empty($row['pass_reason_ai'])) {
        sendSuccessResponse(['pass_reason_ai' => $row['pass_reason_ai'], 'cached' => 1], 'OK');
    }

    $insight = propertyGeneratePassReasonInsight($db, $row);
    if ($insight === null || $insight === '') {
        // APIキー未設定・通信失敗など。エラーにはせず所見なしで返す（理由自体は画面に表示される）。
        sendSuccessResponse(['pass_reason_ai' => null, 'generated' => 0], 'OK');
    }

    $db->prepare("UPDATE properties SET pass_reason_ai = ? WHERE id = ?")->execute([$insight, $propertyId]);
    sendSuccessResponse(['pass_reason_ai' => $insight, 'generated' => 1], '所見を生成しました');
} catch (Exception $e) {
    error_log('property pass-reason-ai error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

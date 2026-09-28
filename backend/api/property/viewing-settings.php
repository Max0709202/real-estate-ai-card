<?php
/**
 * 内見日程調整: 担当エージェントの定休日を保存する。担当ログイン必須。
 * POST(JSON) { property_id, closed_weekdays: [0-6, ...] }（0=日〜6=土）
 *
 * 定休日は担当エージェント単位の設定で、すべての物件・お客様の内見カレンダーで選択不可になる。
 * 物件画面の内見日程タブから設定するため、property_id で担当者本人かを確認する。
 */
require_once __DIR__ . '/viewing-common.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = viewingApiInput();
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    $auth = viewingApiAuthorize($db, $propertyId, $input);
    if ($auth['role'] !== 'agent') sendErrorResponse('担当者としてログインしてください', 403);

    $raw = is_array($input['closed_weekdays'] ?? null) ? $input['closed_weekdays'] : [];
    $weekdays = viewingAgentSaveClosedWeekdays($db, (int)$auth['property']['business_card_id'], $raw);
    sendSuccessResponse(['closed_weekdays' => $weekdays], '定休日を保存しました。');
} catch (Exception $e) {
    error_log('viewing-settings error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

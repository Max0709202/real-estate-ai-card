<?php
/**
 * 配下の顧客に提案されている物件1件の詳細（マイページ「組織・配下顧客」用）。閲覧専用。
 *
 * GET ?property_id=<properties.id>
 *
 * 担当者の物件選定（property/get.php）と同じ内容を、上長が閲覧するためだけに返す。
 * 販売図面・写真も含めて担当者と同じ情報を返す（上長は社内の方なのでマスク前の原本でよい）。
 *
 * 顧客が開いたときの閲覧回数の加算・担当者への閲覧通知は行わない。
 * 上長が見たことを「お客様が見た」と数えてしまわないようにするため。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$viewerId = (int) requireAuth();

$propertyId = isset($_GET['property_id']) ? (int) $_GET['property_id'] : 0;
if ($propertyId <= 0) {
    sendErrorResponse('property_id is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    propertyEnsureTables($db);

    // 物件 → チャットセッション → 名刺 の順にたどり、担当している方を特定する。
    $stmt = $db->prepare("
        SELECT p.*, bc.user_id AS member_user_id
        FROM properties p
        JOIN chat_sessions cs ON cs.id = p.session_id
        JOIN business_cards bc ON bc.id = cs.business_card_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        sendErrorResponse('物件が見つかりません', 404);
    }

    // 閲覧範囲の確認（org/customer-detail.php と同じ判定）。
    if (!orgCanViewMemberCustomers($db, $viewerId, (int)$row['member_user_id'])) {
        sendErrorResponse('この物件は閲覧できません', 403);
    }
    unset($row['member_user_id']);

    sendSuccessResponse([
        'property' => propertySerialize($db, $row, true, true),
    ], 'OK');
} catch (Exception $e) {
    error_log('org customer property error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

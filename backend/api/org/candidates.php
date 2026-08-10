<?php
/**
 * 配下として登録できる候補の一覧（マイページ「組織・配下顧客」用）。
 *
 * 返すのは「自分と同じ会社」かつ「まだどの上長にも紐付いていない」ユーザーのみ。
 * 他社のユーザーは会社名の正規化キーが一致しないため、ここに現れない。
 * 店長（マネージャー）には、その配下に置ける営業（担当者）だけを返す。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$userId = (int) requireAuth();

try {
    $database = new Database();
    $db = $database->getConnection();

    $viewer = orgLoadViewer($db, $userId);
    if (!orgCanViewTeam($viewer['org_role'])) {
        sendErrorResponse('組織を設定する権限がありません', 403);
    }

    $company = orgCompanyForUser($db, $userId);
    $candidates = orgFetchAssignCandidates($db, $userId, $viewer['org_role']);

    sendSuccessResponse([
        'company_name' => $company['name'],
        // 会社名が未登録だと同じ会社かどうか判定できず、候補を出せない。
        'company_resolved' => $company['key'] !== '',
        'candidates' => $candidates,
    ], 'OK');
} catch (Exception $e) {
    error_log('org candidates error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

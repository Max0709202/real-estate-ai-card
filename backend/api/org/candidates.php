<?php
/**
 * 配下として登録できる候補の一覧（マイページ「組織・配下顧客」用）。
 *
 * 返すのは「自分と同じ免許番号」かつ「入金済み・OPEN」のユーザーのみ。
 * 他社のユーザーは免許番号キーが一致しないため、ここに現れない。
 * すでに他の店長の配下にいる方も選べる（店舗異動をこの画面で行うため）。
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

    $license = orgLicenseForUser($db, $userId);
    $candidates = orgFetchAssignCandidates($db, $userId, $viewer['org_role']);

    sendSuccessResponse([
        'license_text' => $license['text'],
        // 免許番号が未登録だと同じ会社かどうか判定できず、候補を出せない。
        'license_resolved' => $license['key'] !== '',
        'candidates' => $candidates,
    ], 'OK');
} catch (Exception $e) {
    error_log('org candidates error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

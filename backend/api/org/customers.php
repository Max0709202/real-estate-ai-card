<?php
/**
 * 配下担当者が抱える顧客の一覧（マイページ「組織・配下顧客」用）。閲覧専用。
 *
 * GET ?member_id=  … 指定した配下担当者だけに絞る（省略時は配下全員）
 * GET ?scope=team  … member_id を「その方の店舗ぶん（本人＋その配下）」として扱う。
 *                    統括が店長を選んだときに、店舗まるごとの顧客を見るために使う。
 *
 * 顧客とのチャット本文や編集・削除はここでは提供しない。
 * 上長に見せるのは「誰が・どの顧客を・いつ対応したか」までに留める。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/customer-invitation-helper.php';
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
        sendErrorResponse('配下の顧客を閲覧する権限がありません', 403);
    }

    ensureChatLeadContactTable($db);
    customerInviteEnsureTable($db);

    $scope = orgVisibleMemberScope($db, $viewer);
    $memberIds = array_map(function ($item) { return (int)$item['id']; }, $scope);
    if (empty($memberIds)) {
        sendSuccessResponse(['customers' => [], 'member_id' => null], 'OK');
    }

    $requestedMemberId = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;
    $onlyUserId = $requestedMemberId > 0 ? $requestedMemberId : null;
    $isTeamScope = ($_GET['scope'] ?? '') === 'team';
    // 配下以外のIDが指定された場合は orgFetchCustomers 側で空になる。
    if ($onlyUserId !== null && !in_array($onlyUserId, $memberIds, true)) {
        sendErrorResponse('指定された担当者は配下ではありません', 403);
    }

    if ($onlyUserId !== null && $isTeamScope) {
        // 店舗ぶん（店長本人＋その配下）。許可済みの $memberIds との積を取る。
        $customers = orgFetchCustomers($db, orgTeamScopeIds($db, $onlyUserId, $memberIds));
    } else {
        $customers = orgFetchCustomers($db, $memberIds, $onlyUserId);
    }

    sendSuccessResponse([
        'customers' => $customers,
        'member_id' => $onlyUserId,
        'scope' => ($onlyUserId !== null && $isTeamScope) ? 'team' : 'member',
    ], 'OK');
} catch (Exception $e) {
    error_log('org customers error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

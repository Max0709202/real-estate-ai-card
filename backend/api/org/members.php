<?php
/**
 * 配下担当者の一覧（マイページ「組織・配下顧客」用）。閲覧専用。
 *
 * 統括（全閲覧）は同じ免許番号のメンバー全員を、
 * マネージャー（店長）は自分の配下の担当者だけを取得できる。
 * 担当者（営業）は閲覧権限が無いため 403 を返す。
 * 一覧に出るのは「入金済み（CR / 振込済 / ST送金）かつ OPEN」の方のみ。
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
        sendErrorResponse('配下の担当者を閲覧する権限がありません', 403);
    }

    // 階層分けは法人プランの機能。運営が ON にした会社（免許番号）でのみ使える。
    if (!orgHierarchyEnabledForUser($db, $userId)) {
        sendErrorResponse('組織階層の機能は法人プランのみのご提供です', 403);
    }

    // 一覧条件が参照する表が無いDBでも落ちないように用意しておく（chat/sessions.php と同じ）。
    ensureChatLeadContactTable($db);
    customerInviteEnsureTable($db);

    $members = orgFetchMembers($db, orgVisibleMemberScope($db, $viewer));

    $totalCustomers = 0;
    $totalUnread = 0;
    foreach ($members as $member) {
        $totalCustomers += $member['customer_count'];
        $totalUnread += $member['unread_count'];
    }

    sendSuccessResponse([
        'viewer' => [
            'user_id' => $viewer['id'],
            'org_role' => $viewer['org_role'],
            'org_role_label' => orgRoleLabel($viewer['org_role']),
            // 自社の判定に使っている免許番号。画面に出して取り違えに気付けるようにする。
            'license_text' => orgLicenseForUser($db, $userId)['text'],
        ],
        'members' => $members,
        'summary' => [
            'member_count' => count($members),
            'customer_count' => $totalCustomers,
            'unread_count' => $totalUnread,
        ],
    ], 'OK');
} catch (Exception $e) {
    error_log('org members error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

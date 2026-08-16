<?php
/**
 * 配下メンバーを外す（＝対象ユーザーの上長を空にする）。
 *
 * POST { user_id: int }
 *
 * 統括（全閲覧）は自社（同じ免許番号）のメンバーを外せる。
 * マネージャー（店長）は自分の直属の配下のみ外せる。
 * 外れた方は「未所属」に戻るだけで、アカウントや顧客情報は一切消えない。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

startSessionIfNotStarted();
$actorId = (int) requireAuth();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $database = new Database();
    $db = $database->getConnection();

    $viewer = orgLoadViewer($db, $actorId);
    if (!orgCanViewTeam($viewer['org_role'])) {
        sendErrorResponse('組織を設定する権限がありません', 403);
    }

    // 階層分けは法人プランの機能。運営が ON にした会社（免許番号）でのみ使える。
    if (!orgHierarchyEnabledForUser($db, $actorId)) {
        sendErrorResponse('組織階層の機能は法人プランのみのご提供です', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    if ($targetId <= 0) {
        sendErrorResponse('対象のユーザーを指定してください', 400);
    }

    if (!orgCanManageMember($db, $viewer, $targetId)) {
        sendErrorResponse('この方の所属は変更できません', 403);
    }

    $stmt = $db->prepare('UPDATE users SET parent_user_id = NULL WHERE id = ?');
    $stmt->execute([$targetId]);

    sendSuccessResponse(['user_id' => $targetId], '配下から外しました');
} catch (Exception $e) {
    error_log('org unassign error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

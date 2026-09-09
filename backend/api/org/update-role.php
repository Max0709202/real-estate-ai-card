<?php
/**
 * 配下メンバーの権限を変更する（担当者 ⇄ マネージャー ⇄ 統括）。
 *
 * POST { user_id: int, org_role: 'staff'|'manager'|'admin' }
 *
 * 実行できるのは統括（全閲覧）のみ。統括が自社の方を店長・統括に指名する想定。
 * 統括（全閲覧）を複数人にできる（既定は登録1人目のみ統括）。
 * ただし既に統括の方をここで降格・解除することはできない
 * （統括の解除は運営側の管理画面 admin/dashboard.php の☑で行う）。
 *
 * 店長に指名すると、3段（統括 → 店長 → 営業）を保つために上長を統括本人へ付け替える。
 * 統括に指名すると上長を外し、運営へのメール登録を待たずに全閲覧を使えるようにする。
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
    if ($viewer['org_role'] !== 'admin') {
        sendErrorResponse('権限を変更できるのは統括（全閲覧）のみです', 403);
    }

    // 階層分けは法人プランの機能。運営が ON にした会社（免許番号）でのみ使える。
    if (!orgHierarchyEnabledForUser($db, $actorId)) {
        sendErrorResponse('組織階層の機能は法人プランのみのご提供です', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $newRole = isset($input['org_role']) ? (string)$input['org_role'] : '';

    if ($targetId <= 0) {
        sendErrorResponse('対象のユーザーを指定してください', 400);
    }
    if (!in_array($newRole, ['staff', 'manager', 'admin'], true)) {
        sendErrorResponse('指定できる権限は担当者・マネージャー・統括です', 400);
    }
    // 自社（同じ免許番号）のメンバーのみ。既に統括の方には触れない（解除は運営の管理画面）。
    if (!orgCanManageMember($db, $viewer, $targetId)) {
        sendErrorResponse('この方の権限は変更できません（自社のメンバーではないか、すでに統括の方です）', 403);
    }

    // 配下を持ったまま担当者へ戻すと、その配下が宙に浮くため先に整理してもらう。
    if ($newRole === 'staff') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE parent_user_id = ?');
        $stmt->execute([$targetId]);
        if ((int)$stmt->fetchColumn() > 0) {
            sendErrorResponse('この方には配下がいます。先に配下を他の店長へ付け替えるか、外してください', 400);
        }
    }

    if ($newRole === 'admin') {
        // 統括（全閲覧）は自社の全員が閲覧範囲のため、上長は持たせない。
        $stmt = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetId]);
        $targetEmail = (string)($stmt->fetchColumn() ?: '');

        $stmt = $db->prepare("UPDATE users SET org_role = 'admin', parent_user_id = NULL WHERE id = ?");
        $stmt->execute([$targetId]);

        // 店長指名と同じ考え方で、指名された統括は運営へのメール登録を待たずに使えるようにする
        // （判定は orgHierarchyEnabledForUser()）。降格時に残っても権限が無ければ無効。
        $license = orgLicenseForUser($db, $actorId);
        if ($license['key'] !== '' && $targetEmail !== '') {
            orgAllowAdminEmailForKey($db, $license['key'], $targetEmail);
        }
    } elseif ($newRole === 'manager') {
        // 3段（統括 → 店長 → 営業）を保つため、店長は指名した統括の直下へ移す。
        if (!orgIsAssignableParent($db, $targetId, $actorId)) {
            sendErrorResponse('その方は店長にできません（階層が循環します）', 400);
        }
        $stmt = $db->prepare('UPDATE users SET org_role = ?, parent_user_id = ? WHERE id = ?');
        $stmt->execute([$newRole, $actorId, $targetId]);
    } else {
        $stmt = $db->prepare('UPDATE users SET org_role = ? WHERE id = ?');
        $stmt->execute([$newRole, $targetId]);
    }

    sendSuccessResponse([
        'user_id' => $targetId,
        'org_role' => $newRole,
        'org_role_label' => orgRoleLabel($newRole),
    ], '権限を変更しました');
} catch (Exception $e) {
    error_log('org update role error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

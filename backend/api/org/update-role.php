<?php
/**
 * 配下メンバーの権限を変更する（担当者 ⇄ マネージャー）。
 *
 * POST { user_id: int, org_role: 'staff'|'manager' }
 *
 * 実行できるのは統括（全閲覧）のみ。統括が自社の方を店長に指名する想定。
 * 統括（全閲覧）そのものを増やす・外すことはここではできない
 * （統括の指名は運営側の管理画面 admin/dashboard.php の☑で行う）。
 *
 * 店長に指名すると、3段（統括 → 店長 → 営業）を保つために上長を統括本人へ付け替える。
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

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $newRole = isset($input['org_role']) ? (string)$input['org_role'] : '';

    if ($targetId <= 0) {
        sendErrorResponse('対象のユーザーを指定してください', 400);
    }
    if (!in_array($newRole, ['staff', 'manager'], true)) {
        sendErrorResponse('指定できる権限は担当者またはマネージャーです', 400);
    }
    // 自社（同じ免許番号）のメンバーのみ。他の統括には触れない。
    if (!orgCanManageMember($db, $viewer, $targetId)) {
        sendErrorResponse('この方の権限は変更できません（自社のメンバーではないか、統括の方です）', 403);
    }

    // 配下を持ったまま担当者へ戻すと、その配下が宙に浮くため先に整理してもらう。
    if ($newRole === 'staff') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE parent_user_id = ?');
        $stmt->execute([$targetId]);
        if ((int)$stmt->fetchColumn() > 0) {
            sendErrorResponse('この方には配下がいます。先に配下を他の店長へ付け替えるか、外してください', 400);
        }
    }

    if ($newRole === 'manager') {
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

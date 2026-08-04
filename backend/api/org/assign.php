<?php
/**
 * 配下メンバーを登録する（＝対象ユーザーの上長を設定する）。
 *
 * POST { user_id: int, parent_user_id?: int }
 *   parent_user_id 省略時は「自分」を上長にする。
 *   統括（管理者）だけは、自分の直属の店長（マネージャー）を上長に指定できる
 *   （本部統括が各店舗へ営業を割り当てるケース）。
 *
 * 守る条件:
 *   ・実行者はマネージャーか管理者
 *   ・対象は自分と同じ会社
 *   ・対象は未所属、または自分の配下（＝店舗間の異動）
 *   ・階層は最大3段（統括 → 店長 → 営業）を超えない
 *   ・循環を作らない
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

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    if ($targetId <= 0) {
        sendErrorResponse('対象のユーザーを指定してください', 400);
    }
    if ($targetId === $actorId) {
        sendErrorResponse('自分自身を配下にはできません', 400);
    }

    // 上長を誰にするか。既定は自分。
    $parentId = $actorId;
    if (!empty($input['parent_user_id']) && (int)$input['parent_user_id'] !== $actorId) {
        if ($viewer['org_role'] !== 'admin') {
            sendErrorResponse('他の方の配下へ登録できるのは管理者（統括）のみです', 403);
        }
        $parentId = (int)$input['parent_user_id'];
        // 指定できるのは自分の直属の店長だけ（3段を超えないようにする）。
        $stmt = $db->prepare('SELECT org_role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$parentId]);
        $parentRole = orgNormalizeRole($stmt->fetchColumn() ?: 'staff');
        if (!orgIsDirectChild($db, $actorId, $parentId) || $parentRole !== 'manager') {
            sendErrorResponse('上長には、自分の直属のマネージャー（店長）のみ指定できます', 400);
        }
    }

    $stmt = $db->prepare('SELECT id, org_role, parent_user_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        sendErrorResponse('対象のユーザーが見つかりません', 404);
    }

    $targetRole = orgNormalizeRole($target['org_role'] ?? 'staff');
    if ($targetRole === 'admin') {
        sendErrorResponse('管理者（統括）を配下にはできません', 400);
    }

    // 他社のユーザーを取り込めないようにする、最も重要な確認。
    if (!orgIsSameCompany($db, $actorId, $targetId)) {
        sendErrorResponse('同じ会社の方のみ配下に登録できます（会社プロフィールの会社名をご確認ください）', 400);
    }

    // 未所属か、自分の配下（＝異動）のときだけ受け付ける。
    $targetParentId = $target['parent_user_id'] !== null ? (int)$target['parent_user_id'] : null;
    if ($targetParentId !== null && !orgIsInSubtree($db, $actorId, $targetId)) {
        sendErrorResponse('この方はすでに他の上長に登録されています', 400);
    }

    // 階層は3段まで。店長の下に置けるのは営業だけ。
    $parentRoleForTier = ($parentId === $actorId)
        ? $viewer['org_role']
        : 'manager';
    if ($parentRoleForTier === 'manager' && $targetRole !== 'staff') {
        sendErrorResponse('マネージャー（店長）の配下には担当者（営業）のみ登録できます', 400);
    }

    if (!orgIsAssignableParent($db, $targetId, $parentId)) {
        sendErrorResponse('その組み合わせでは階層が循環するため登録できません', 400);
    }

    $stmt = $db->prepare('UPDATE users SET parent_user_id = ? WHERE id = ?');
    $stmt->execute([$parentId, $targetId]);

    sendSuccessResponse([
        'user_id' => $targetId,
        'parent_user_id' => $parentId,
    ], '配下に登録しました');
} catch (Exception $e) {
    error_log('org assign error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

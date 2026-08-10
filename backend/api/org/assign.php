<?php
/**
 * 配下メンバーを登録する（＝対象ユーザーの上長を設定する）。
 *
 * POST { user_id: int, parent_user_id?: int }
 *   parent_user_id 省略時は「自分」を上長にする。
 *   統括（全閲覧）だけは、自社の店長（マネージャー）を上長に指定できる
 *   （本部統括が各店舗へ営業を割り当てるケース）。
 *
 * 守る条件:
 *   ・実行者は統括（全閲覧）かマネージャー（店長）
 *   ・対象は自分と同じ免許番号（会社名ではなく免許番号で判定する）
 *   ・対象は入金済み（CR / 振込済 / ST送金）かつ OPEN
 *   ・階層は最大3段（統括 → 店長 → 営業）を超えない
 *   ・循環を作らない
 *
 * すでに他の店長の配下にいる方も登録できる（＝店舗異動）。
 * 元の上長からは自動的に外れ、顧客データは移動しない。
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
            sendErrorResponse('他の方の配下へ登録できるのは統括（全閲覧）のみです', 403);
        }
        $parentId = (int)$input['parent_user_id'];
        // 指定できるのは自社の店長だけ（3段を超えないようにする）。
        $stmt = $db->prepare('SELECT org_role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$parentId]);
        $parentRole = orgNormalizeRole($stmt->fetchColumn() ?: 'staff');
        if ($parentRole !== 'manager' || !orgIsSameLicense($db, $actorId, $parentId)) {
            sendErrorResponse('上長には、自社のマネージャー（店長）のみ指定できます', 400);
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
        sendErrorResponse('統括（全閲覧）を配下にはできません', 400);
    }

    // 他社のユーザーを取り込めないようにする、最も重要な確認。会社名ではなく免許番号で判定する。
    if (!orgIsSameLicense($db, $actorId, $targetId)) {
        sendErrorResponse('免許番号が同じ方のみ配下に登録できます（会社プロフィールの宅建業者番号をご確認ください）', 400);
    }

    // 一覧に出す条件と揃える。未入金・非OPENの方は配下に登録できない。
    if (empty(orgFilterActiveMemberIds($db, [$targetId]))) {
        sendErrorResponse('入金済みかつOPENの方のみ配下に登録できます', 400);
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

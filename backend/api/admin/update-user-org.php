<?php
/**
 * ユーザーの組織権限（担当者／マネージャー／管理者）と上長を更新する。
 *
 * 階層の設定は運用管理者だけが行う。ユーザー自身は変更できない。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $currentAdminId = requireFullAdminAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    if ($targetUserId <= 0) {
        sendErrorResponse('ユーザーIDは必須です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();
    orgEnsureUserColumns($db);

    $stmt = $db->prepare('SELECT id, email, org_role, parent_user_id FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetUserId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        sendErrorResponse('対象ユーザーが見つかりません', 404);
    }

    // 送られてきた項目だけを更新する（権限だけ／上長だけの変更を許す）。
    $newRole = array_key_exists('org_role', $input)
        ? orgNormalizeRole($input['org_role'])
        : orgNormalizeRole($target['org_role'] ?? 'staff');
    if (array_key_exists('org_role', $input) && !in_array((string)$input['org_role'], ORG_ROLES, true)) {
        sendErrorResponse('無効な権限です', 400);
    }

    $newParentId = $target['parent_user_id'] !== null ? (int)$target['parent_user_id'] : null;
    if (array_key_exists('parent_user_id', $input)) {
        $rawParent = $input['parent_user_id'];
        $newParentId = ($rawParent === null || $rawParent === '' || (int)$rawParent === 0) ? null : (int)$rawParent;
    }

    if ($newParentId !== null) {
        $stmt = $db->prepare('SELECT id, email, org_role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$newParentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) {
            sendErrorResponse('指定された上長が見つかりません', 404);
        }
        // 担当者（営業）は上長になれない。上長はマネージャーか管理者だけ。
        if (!orgCanViewTeam($parent['org_role'] ?? 'staff')) {
            sendErrorResponse('上長にはマネージャーまたは管理者のみ指定できます', 400);
        }
        // 自分自身・自分の配下を上長にすると階層が循環する。
        if (!orgIsAssignableParent($db, $targetUserId, $newParentId)) {
            sendErrorResponse('その相手は上長に指定できません（階層が循環します）', 400);
        }
    }

    $stmt = $db->prepare('UPDATE users SET org_role = ?, parent_user_id = ? WHERE id = ?');
    $stmt->execute([$newRole, $newParentId, $targetUserId]);

    logAdminChange(
        $db,
        $currentAdminId,
        $_SESSION['admin_email'] ?? '',
        'other',
        'user',
        $targetUserId,
        '組織設定を変更: 権限=' . $newRole . ' / 上長ID=' . ($newParentId ?? 'なし')
    );

    sendSuccessResponse([
        'user_id' => $targetUserId,
        'org_role' => $newRole,
        'org_role_label' => orgRoleLabel($newRole),
        'parent_user_id' => $newParentId,
    ], '組織設定を更新しました');
} catch (Exception $e) {
    error_log('Update User Org Error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

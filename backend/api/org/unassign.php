<?php
/**
 * 配下メンバーを外す（＝対象ユーザーの上長を空にする）。
 *
 * POST { user_id: int }
 *
 * 管理者（統括）は自分の配下の誰でも外せる。
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

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    if ($targetId <= 0) {
        sendErrorResponse('対象のユーザーを指定してください', 400);
    }

    $allowed = ($viewer['org_role'] === 'admin')
        ? orgIsInSubtree($db, $actorId, $targetId)
        : orgIsDirectChild($db, $actorId, $targetId);
    if (!$allowed) {
        sendErrorResponse('この方はあなたの配下ではありません', 403);
    }

    $stmt = $db->prepare('UPDATE users SET parent_user_id = NULL WHERE id = ?');
    $stmt->execute([$targetId]);

    sendSuccessResponse(['user_id' => $targetId], '配下から外しました');
} catch (Exception $e) {
    error_log('org unassign error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

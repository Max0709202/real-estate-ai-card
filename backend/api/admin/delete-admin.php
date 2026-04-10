<?php
/**
 * Delete an admin account (admin role only; safeguards for initial admin and last admin).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $currentAdminId = requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $targetAdminId = isset($input['admin_id']) ? (int) $input['admin_id'] : 0;

    if ($targetAdminId <= 0) {
        sendErrorResponse('管理者IDが必要です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare('SELECT id, email, role FROM admins WHERE id = ?');
    $stmt->execute([$currentAdminId]);
    $currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentAdmin) {
        sendErrorResponse('管理者情報が見つかりません', 403);
    }

    $canManageRoles = ((int) $currentAdmin['id'] === 1 || $currentAdmin['role'] === 'admin');
    if (!$canManageRoles) {
        sendErrorResponse('管理者を削除する権限がありません', 403);
    }

    if ($targetAdminId === 1) {
        sendErrorResponse('初期管理者は削除できません', 403);
    }

    if ($targetAdminId === (int) $currentAdminId) {
        sendErrorResponse('ログイン中の自分自身は削除できません', 403);
    }

    $stmt = $db->prepare('SELECT id, email, role FROM admins WHERE id = ?');
    $stmt->execute([$targetAdminId]);
    $targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetAdmin) {
        sendErrorResponse('対象管理者が見つかりません', 404);
    }

    if ($targetAdmin['role'] === 'admin') {
        $cntStmt = $db->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'");
        $adminRoleCount = (int) $cntStmt->fetchColumn();
        if ($adminRoleCount <= 1) {
            sendErrorResponse('最後の管理者アカウントは削除できません', 403);
        }
    }

    $targetEmail = $targetAdmin['email'] ?? '';

    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM admins WHERE id = ?');
        $del->execute([$targetAdminId]);
        if ($del->rowCount() === 0) {
            $db->rollBack();
            sendErrorResponse('削除に失敗しました', 500);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    logAdminChange(
        $db,
        $currentAdminId,
        $_SESSION['admin_email'] ?? '',
        'other',
        'other',
        $targetAdminId,
        '管理者アカウント削除 (ID ' . $targetAdminId . '): ' . $targetEmail
    );

    sendSuccessResponse(null, '管理者を削除しました');
} catch (Exception $e) {
    error_log('Delete Admin Error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

<?php
/**
 * Update admin role
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
    $targetAdminId = isset($input['admin_id']) ? (int)$input['admin_id'] : 0;
    $newRole = $input['role'] ?? '';

    if ($targetAdminId <= 0 || empty($newRole)) {
        sendErrorResponse('管理者IDとロールは必須です', 400);
    }

    if (!in_array($newRole, ['admin', 'client'], true)) {
        sendErrorResponse('無効なロールです', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Current admin must be initial admin or admin role.
    $stmt = $db->prepare("SELECT id, email, role FROM admins WHERE id = ?");
    $stmt->execute([$currentAdminId]);
    $currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentAdmin) {
        sendErrorResponse('管理者情報が見つかりません', 403);
    }

    $canManageRoles = ((int)$currentAdmin['id'] === 1 || $currentAdmin['role'] === 'admin');
    if (!$canManageRoles) {
        sendErrorResponse('ロールを変更する権限がありません', 403);
    }

    // Prevent non-initial admins from changing initial admin role.
    if ((int)$currentAdmin['id'] !== 1 && $targetAdminId === 1) {
        sendErrorResponse('初期管理者のロールは変更できません', 403);
    }

    // Ensure target admin exists.
    $stmt = $db->prepare("SELECT id, role FROM admins WHERE id = ?");
    $stmt->execute([$targetAdminId]);
    $targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetAdmin) {
        sendErrorResponse('対象管理者が見つかりません', 404);
    }

    if ($targetAdmin['role'] === $newRole) {
        sendSuccessResponse(null, 'ロールはすでに同じです');
    }

    $stmt = $db->prepare("UPDATE admins SET role = ? WHERE id = ?");
    $stmt->execute([$newRole, $targetAdminId]);

    if ($stmt->rowCount() === 0) {
        sendErrorResponse('ロール更新に失敗しました', 500);
    }

    logAdminChange(
        $db,
        $currentAdminId,
        $_SESSION['admin_email'] ?? '',
        'update',
        'admins',
        $targetAdminId,
        "管理者ロールを{$newRole}に変更"
    );

    sendSuccessResponse(null, 'ロールを更新しました');
} catch (Exception $e) {
    error_log("Update Admin Role Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

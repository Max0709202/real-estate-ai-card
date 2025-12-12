<?php
/**
 * Update Admin Role API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    // Check admin authentication
    if (empty($_SESSION['admin_id'])) {
        sendErrorResponse('管理者権限が必要です', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    if (empty($input['admin_id']) || empty($input['role'])) {
        sendErrorResponse('管理者IDとロールが必要です', 400);
    }

    if (!in_array($input['role'], ['admin', 'client'])) {
        sendErrorResponse('無効なロールです', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Get current admin info
    $stmt = $db->prepare("SELECT id, role FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentAdmin) {
        sendErrorResponse('現在の管理者情報が見つかりません', 404);
    }

    // Check permission: Only initial admin (ID=1) or admin role can change roles
    $canManageRoles = ($currentAdmin['id'] == 1 || $currentAdmin['role'] === 'admin');
    
    if (!$canManageRoles) {
        sendErrorResponse('ロールを変更する権限がありません', 403);
    }

    // Get target admin info
    $stmt = $db->prepare("SELECT id, role FROM admins WHERE id = ?");
    $stmt->execute([$input['admin_id']]);
    $targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetAdmin) {
        sendErrorResponse('対象の管理者が見つかりません', 404);
    }

    // Prevent changing initial admin's role (ID=1) unless current user is initial admin
    if ($targetAdmin['id'] == 1 && $currentAdmin['id'] != 1) {
        sendErrorResponse('初期管理者のロールは変更できません', 403);
    }

    // Update role
    $stmt = $db->prepare("UPDATE admins SET role = ? WHERE id = ?");
    $stmt->execute([$input['role'], $input['admin_id']]);

    // Update session if updating own role
    if ($input['admin_id'] == $_SESSION['admin_id']) {
        $_SESSION['admin_role'] = $input['role'];
    }

    sendSuccessResponse([
        'admin_id' => $input['admin_id'],
        'role' => $input['role']
    ], 'ロールを更新しました');

} catch (Exception $e) {
    error_log("Update Role Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


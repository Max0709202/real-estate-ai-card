<?php
/**
 * Update Role Type for Email Invitation
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? null;
    $roleType = $input['role_type'] ?? null;
    
    if (empty($id) || empty($roleType)) {
        sendErrorResponse('IDとロールタイプは必須です', 400);
    }
    
    if (!in_array($roleType, ['new', 'existing', 'free'])) {
        sendErrorResponse('無効なロールタイプです', 400);
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("UPDATE email_invitations SET role_type = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$roleType, $id]);
    
    if ($stmt->rowCount() === 0) {
        sendErrorResponse('更新に失敗しました', 404);
    }
    
    // Log admin change
    logAdminChange($db, $_SESSION['admin_id'], $_SESSION['admin_email'] ?? '', 'update', 'email_invitations', $id, "ロールタイプを{$roleType}に変更");
    
    sendSuccessResponse(null, 'ロールタイプを更新しました');
    
} catch (Exception $e) {
    error_log("Update Role Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

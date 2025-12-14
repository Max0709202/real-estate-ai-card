<?php
/**
 * Admin Email Verification API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'GET' && $method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $token = $_GET['token'] ?? ($_POST['token'] ?? '');

    if (empty($token)) {
        sendErrorResponse('認証トークンが必要です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Verify token
    $stmt = $db->prepare("SELECT id, email, verification_token_expires_at FROM admins WHERE verification_token = ? AND email_verified = 0");
    $stmt->execute([$token]);
    $admin = $stmt->fetch();

    if (!$admin) {
        sendErrorResponse('無効な認証トークンです', 400);
    }

    // Check token expiration
    $now = date('Y-m-d H:i:s');
    if ($admin['verification_token_expires_at'] && $admin['verification_token_expires_at'] < $now) {
        $stmt = $db->prepare("UPDATE admins SET verification_token = NULL, verification_token_expires_at = NULL WHERE id = ?");
        $stmt->execute([$admin['id']]);
        sendErrorResponse('認証トークンの有効期限が切れています。メール認証を再度リクエストしてください。', 400);
    }

    // Complete email verification
    $stmt = $db->prepare("UPDATE admins SET email_verified = 1, verification_token = NULL, verification_token_expires_at = NULL WHERE id = ?");
    $stmt->execute([$admin['id']]);

    sendSuccessResponse([
        'admin_id' => $admin['id'],
        'email' => $admin['email']
    ], 'メール認証が完了しました。ログインページからログインしてください。');

} catch (Exception $e) {
    error_log("Admin email verification error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}





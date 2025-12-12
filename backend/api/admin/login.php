<?php
/**
 * Admin Login API
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

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    if (empty($input['email']) || empty($input['password'])) {
        sendErrorResponse('メールアドレスとパスワードを入力してください', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$input['email']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        sendErrorResponse('メールアドレスまたはパスワードが正しくありません', 401);
    }

    // Check if email is verified (skip for admin@rchukai.jp)
    if ($admin['email'] !== 'admin@rchukai.jp' && isset($admin['email_verified']) && !$admin['email_verified']) {
        sendErrorResponse('メール認証が完了していません。登録時に送信されたメールから認証を完了してください。', 403);
    }

    // パスワード検証
    $storedHash = trim($admin['password_hash']);
    $inputPassword = trim($input['password']);
    $passwordValid = verifyPassword($inputPassword, $storedHash);
    
    // プレーンテキストのパスワードが検出された場合、自動的に再ハッシュ化
    if (!$passwordValid && $inputPassword === $storedHash) {
        // プレーンテキストが検出されたので、適切にハッシュ化して保存
        $newHash = hashPassword($inputPassword);
        $updateStmt = $db->prepare("UPDATE admins SET password_hash = ?, last_password_change = NOW() WHERE id = ?");
        $updateStmt->execute([$newHash, $admin['id']]);
        
        error_log("SECURITY: Plain text password detected and rehashed for admin ID: " . $admin['id']);
        $passwordValid = true; // 再ハッシュ化後、認証を許可
    }
    
    if (!$passwordValid) {
        // Debug logging (remove in production)
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            error_log("Admin Login Failed - Email: " . $input['email']);
            error_log("Admin Login Failed - Hash in DB: " . substr($storedHash, 0, 20) . "...");
            error_log("Admin Login Failed - Hash length: " . strlen($storedHash));
            error_log("Admin Login Failed - Is bcrypt: " . (preg_match('/^\$2[ayb]\$\d{2}\$/', $storedHash) ? 'Yes' : 'No'));
        }
        sendErrorResponse('メールアドレスまたはパスワードが正しくありません', 401);
    }

    // 最終ログイン時刻更新
    $stmt = $db->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?");
    $stmt->execute([$admin['id']]);

    // セッション設定
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_role'] = $admin['role'];

    sendSuccessResponse([
        'admin_id' => $admin['id'],
        'email' => $admin['email'],
        'role' => $admin['role']
    ], 'ログインに成功しました');

} catch (Exception $e) {
    error_log("Admin Login Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


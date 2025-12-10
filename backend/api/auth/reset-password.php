<?php
/**
 * Reset Password API - Actually reset the password
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

    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';
    $passwordConfirm = $input['password_confirm'] ?? '';

    if (empty($token)) {
        sendErrorResponse('パスワードリセットトークンが必要です', 400);
    }

    if (empty($password) || strlen($password) < 8) {
        sendErrorResponse('パスワードは8文字以上で入力してください', 400);
    }

    if ($password !== $passwordConfirm) {
        sendErrorResponse('パスワードが一致しません', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // トークン検証（有効期限もチェック）
    try {
        $stmt = $db->prepare("SELECT id, email, password_reset_token_expires_at FROM users WHERE password_reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database Error in reset-password: " . $e->getMessage());
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
        } else {
            sendErrorResponse('データベースエラーが発生しました', 500);
        }
    }

    if (!$user) {
        sendErrorResponse('無効なパスワードリセットトークンです', 400);
    }

    // トークンの有効期限チェック
    $now = date('Y-m-d H:i:s');
    if ($user['password_reset_token_expires_at'] && $user['password_reset_token_expires_at'] < $now) {
        // トークンが期限切れの場合、トークンをクリア
        $stmt = $db->prepare("UPDATE users SET password_reset_token = NULL, password_reset_token_expires_at = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        sendErrorResponse('パスワードリセットリンクの有効期限が切れています。再度パスワードリセットをリクエストしてください。', 400);
    }

    // パスワードをハッシュ化
    $passwordHash = hashPassword($password);

    // パスワードを更新し、トークンをクリア
    try {
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_token_expires_at = NULL WHERE id = ?");
        $stmt->execute([$passwordHash, $user['id']]);
    } catch (PDOException $e) {
        error_log("Database Error updating password: " . $e->getMessage());
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
        } else {
            sendErrorResponse('データベースエラーが発生しました', 500);
        }
    }

    sendSuccessResponse([
        'user_id' => $user['id'],
        'email' => $user['email']
    ], 'パスワードが正常にリセットされました');

} catch (PDOException $e) {
    error_log("Reset Password Database Error: " . $e->getMessage());
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
    } else {
        sendErrorResponse('データベースエラーが発生しました', 500);
    }
} catch (Exception $e) {
    error_log("Reset Password Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


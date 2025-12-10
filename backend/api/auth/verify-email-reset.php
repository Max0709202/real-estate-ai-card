<?php
/**
 * Verify Email Reset API
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
        sendErrorResponse('メールアドレス変更トークンが必要です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // トークン検証（有効期限もチェック）
    try {
        $stmt = $db->prepare("SELECT id, email, email_reset_new_email, email_reset_token_expires_at FROM users WHERE email_reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database Error in verify-email-reset: " . $e->getMessage());
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
        } else {
            sendErrorResponse('データベースエラーが発生しました', 500);
        }
    }

    if (!$user) {
        sendErrorResponse('無効なメールアドレス変更トークンです', 400);
    }

    // トークンの有効期限チェック
    $now = date('Y-m-d H:i:s');
    if ($user['email_reset_token_expires_at'] && $user['email_reset_token_expires_at'] < $now) {
        // トークンが期限切れの場合、トークンをクリア
        $stmt = $db->prepare("UPDATE users SET email_reset_token = NULL, email_reset_token_expires_at = NULL, email_reset_new_email = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        sendErrorResponse('メールアドレス変更リンクの有効期限が切れています。再度メールアドレス変更をリクエストしてください。', 400);
    }

    // 新しいメールアドレスが既に使用されていないか再チェック
    $newEmail = $user['email_reset_new_email'];
    $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $checkStmt->execute([$newEmail, $user['id']]);
    if ($checkStmt->fetch()) {
        sendErrorResponse('このメールアドレスは既に使用されています', 400);
    }

    // メールアドレスを更新し、トークンをクリア
    try {
        $stmt = $db->prepare("UPDATE users SET email = ?, email_reset_token = NULL, email_reset_token_expires_at = NULL, email_reset_new_email = NULL WHERE id = ?");
        $stmt->execute([$newEmail, $user['id']]);
    } catch (PDOException $e) {
        error_log("Database Error updating email: " . $e->getMessage());
        sendErrorResponse('データベースエラーが発生しました', 500);
    }

    sendSuccessResponse([
        'user_id' => $user['id'],
        'old_email' => $user['email'],
        'new_email' => $newEmail
    ], 'メールアドレスが正常に変更されました');

} catch (PDOException $e) {
    error_log("Verify Email Reset Database Error: " . $e->getMessage());
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
    } else {
        sendErrorResponse('データベースエラーが発生しました', 500);
    }
} catch (Exception $e) {
    error_log("Verify Email Reset Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


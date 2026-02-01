<?php
/**
 * Forgot Password API - Send password reset email
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

    $email = $input['email'] ?? '';

    if (empty($email) || !validateEmail($email)) {
        sendErrorResponse('有効なメールアドレスを入力してください', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // ユーザーを検索
    $stmt = $db->prepare("SELECT id, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // セキュリティのため、ユーザーが存在しない場合でも成功メッセージを返す
        sendSuccessResponse([], 'メールアドレスが登録されている場合、パスワードリセット用のリンクを送信しました');
        exit;
    }

    // パスワードリセットトークンを生成
    $resetToken = generateToken(32);
    
    // トークンの有効期限を1時間後に設定
    $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // トークンをデータベースに保存
    try {
        $stmt = $db->prepare("UPDATE users SET password_reset_token = ?, password_reset_token_expires_at = ? WHERE id = ?");
        $stmt->execute([$resetToken, $tokenExpiresAt, $user['id']]);
    } catch (PDOException $e) {
        error_log("Database Error in forgot-password: " . $e->getMessage());
        // カラムが存在しない可能性がある場合のエラーメッセージ
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            error_log("Password reset columns may not exist in database. Please run migration.");
            sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
        }
        throw $e;
    }

    // パスワードリセットリンク
    $resetLink = BASE_URL . "/auth/reset-password.php?token=" . urlencode($resetToken);
    
    // メール本文の作成
    $emailSubject = '【不動産AI名刺】パスワードリセットのお願い';
    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto;}
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { background: #ffffff; padding: 15px; display: inline-block; margin: 0 auto; }
            .header img { max-width: 200px; height: auto; display: block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .button { display: inline-block; padding: 12px 30px; background: #0066cc; color: #fff; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 200px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>パスワードリセットのリクエストを受け付けました。</p>
                <p>パスワードをリセットするため、以下のリンクをクリックしてください。</p>
                <p style='text-align: center;'>
                    <a href='{$resetLink}' style='color: #fff;' class='button'>パスワードをリセットする</a>
                </p>
                <p>もし上記のボタンがクリックできない場合は、以下のURLをコピーしてブラウザのアドレスバーに貼り付けてください。</p>
                <p style='word-break: break-all; background: #fff; padding: 10px; border-radius: 4px; font-size: 12px;'>{$resetLink}</p>
                <p><strong>※このリンクは1時間有効です。期限を過ぎた場合は、再度パスワードリセットをリクエストしてください。</strong></p>
                <p>このメールに心当たりがない場合は、このメールを無視してください。パスワードは変更されません。</p>
                <div class='footer'>
                    <p>このメールは自動送信されています。返信はできません。</p>
                    <p>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // プレーンテキスト版
    $emailBodyText = 
        "パスワードリセットのリクエストを受け付けました。\n\n" .
        "パスワードをリセットするため、以下のリンクをクリックしてください（1時間有効）：\n" .
        "$resetLink\n\n" .
        "期限を過ぎた場合は、再度パスワードリセットをリクエストしてください。\n\n" .
        "このメールに心当たりがない場合は、このメールを無視してください。パスワードは変更されません。\n";
    
    // メール送信
    $emailSent = sendEmail($email, $emailSubject, $emailBody, $emailBodyText, 'password_reset', $user['id'], $user['id']);
    
    if ($emailSent) {
        sendSuccessResponse([], 'パスワードリセット用のリンクをメールで送信しました。メールボックスをご確認ください');
    } else {
        error_log("Failed to send password reset email to: " . $email);
        sendErrorResponse('メール送信に失敗しました。しばらくしてから再度お試しください', 500);
    }

} catch (PDOException $e) {
    error_log("Forgot Password Database Error: " . $e->getMessage());
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
    } else {
        sendErrorResponse('データベースエラーが発生しました', 500);
    }
} catch (Exception $e) {
    error_log("Forgot Password Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


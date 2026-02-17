<?php
/**
 * Resend Verification Email API
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
    $stmt = $db->prepare("SELECT id, email, email_verified, verification_token FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // セキュリティのため、ユーザーが存在しない場合でも成功メッセージを返す
        sendSuccessResponse([], 'メールアドレスが登録されている場合、認証メールを送信しました');
        exit;
    }

    // 既に認証済みの場合
    if ($user['email_verified'] == 1) {
        sendErrorResponse('このメールアドレスは既に認証済みです', 400);
    }

    // 新しいトークンを生成（既存のトークンがある場合は更新）
    $verificationToken = $user['verification_token'];
    if (empty($verificationToken)) {
        $verificationToken = generateToken(32);
    }
    
    // トークンの有効期限を15分後に設定（再送信時も新しい期限を設定）
    $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $stmt = $db->prepare("UPDATE users SET verification_token = ?, verification_token_expires_at = ? WHERE id = ?");
    $stmt->execute([$verificationToken, $tokenExpiresAt, $user['id']]);

    // メール認証の送信
    $verificationLink = BASE_URL . "/auth/verify.php?token=" . $verificationToken;
    
    // メール本文の作成
    $emailSubject = '【不動産AI名刺】メール認証のお願い（再送信）';
    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto; }
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { background: #ffffff; padding: 15px; display: inline-block; margin: 0 auto; }
            .header img { max-width: 100px; height: auto; display: block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .button { display: inline-block; padding: 12px 30px; background: #0066cc; color: #fff; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 100px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>メール認証の再送信リクエストを受け付けました。</p>
                <p>メール認証を完了するため、以下のリンクをクリックしてください。</p>
                <p style='text-align: center;'>
                    <a href='{$verificationLink}' style='color: #fff;' class='button'>メール認証を完了する</a>
                </p>
                <p>もし上記のボタンがクリックできない場合は、以下のURLをコピーしてブラウザのアドレスバーに貼り付けてください。</p>
                <p style='word-break: break-all; background: #fff; padding: 10px; border-radius: 4px; font-size: 12px;'>{$verificationLink}</p>
                <p><strong>※このリンクは15分間有効です。期限を過ぎた場合は、再度メール認証をリクエストしてください。</strong></p>
                <p>このメールに心当たりがない場合は、このメールを無視してください。</p>
                <div class='footer'>
                    <p>このメールは自動送信されています。返信はできません。</p>
                    <p>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // メール送信
    $emailSent = sendEmail($email, $emailSubject, $emailBody, '', 'verification_resend', $user['id'], $user['id']);
    
    if ($emailSent) {
        sendSuccessResponse([], '認証メールを再送信しました。メールボックスをご確認ください');
    } else {
        error_log("Failed to resend verification email to: " . $email);
        sendErrorResponse('メール送信に失敗しました。しばらくしてから再度お試しください', 500);
    }

} catch (Exception $e) {
    error_log("Resend Verification Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


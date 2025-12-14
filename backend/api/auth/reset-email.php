<?php
/**
 * Reset Email Address API - Send email verification for new email
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

    // ログインチェック
    if (empty($_SESSION['user_id'])) {
        sendErrorResponse('ログインが必要です', 401);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $newEmail = $input['new_email'] ?? '';

    if (empty($newEmail) || !validateEmail($newEmail)) {
        sendErrorResponse('有効なメールアドレスを入力してください', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // 現在のユーザー情報を取得
    $stmt = $db->prepare("SELECT id, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendErrorResponse('ユーザーが見つかりません', 404);
    }

    // 新しいメールアドレスが既に使用されていないかチェック
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$newEmail, $user['id']]);
    if ($stmt->fetch()) {
        sendErrorResponse('このメールアドレスは既に使用されています', 400);
    }

    // メールアドレスリセットトークンを生成
    $resetToken = generateToken(32);
    
    // トークンの有効期限を15分後に設定
    $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    // トークンと新しいメールアドレスをデータベースに保存
    try {
        $stmt = $db->prepare("UPDATE users SET email_reset_token = ?, email_reset_token_expires_at = ?, email_reset_new_email = ? WHERE id = ?");
        $stmt->execute([$resetToken, $tokenExpiresAt, $newEmail, $user['id']]);
    } catch (PDOException $e) {
        error_log("Database Error in reset-email: " . $e->getMessage());
        if (strpos($e->getMessage(), 'Unknown column') !== false) {
            sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
        } else {
            sendErrorResponse('データベースエラーが発生しました', 500);
        }
    }

    // メールアドレス確認リンク
    $verificationLink = BASE_URL . "/frontend/auth/verify-email-reset.php?token=" . urlencode($resetToken);
    
    // メール本文の作成
    $emailSubject = '【不動産AI名刺】メールアドレス変更の確認';
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
                    <img src='" . BASE_URL . "/frontend/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 200px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>メールアドレス変更のリクエストを受け付けました。</p>
                <p>新しいメールアドレス: <strong>{$newEmail}</strong></p>
                <p>メールアドレス変更を完了するため、以下のリンクをクリックしてください。</p>
                <p style='text-align: center;'>
                    <a href='{$verificationLink}' style='color: #fff;' class='button'>メールアドレスを変更する</a>
                </p>
                <p>もし上記のボタンがクリックできない場合は、以下のURLをコピーしてブラウザのアドレスバーに貼り付けてください。</p>
                <p style='word-break: break-all; background: #fff; padding: 10px; border-radius: 4px; font-size: 12px;'>{$verificationLink}</p>
                <p><strong>※このリンクは15分間有効です。期限を過ぎた場合は、再度メールアドレス変更をリクエストしてください。</strong></p>
                <p>このメールに心当たりがない場合は、このメールを無視してください。メールアドレスは変更されません。</p>
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
        "メールアドレス変更のリクエストを受け付けました。\n\n" .
        "新しいメールアドレス: {$newEmail}\n\n" .
        "メールアドレス変更を完了するため、以下のリンクをクリックしてください（15分間有効）：\n" .
        "$verificationLink\n\n" .
        "期限を過ぎた場合は、再度メールアドレス変更をリクエストしてください。\n\n" .
        "このメールに心当たりがない場合は、このメールを無視してください。メールアドレスは変更されません。\n";
    
    // メール送信
    $emailSent = sendEmail($newEmail, $emailSubject, $emailBody, $emailBodyText, 'email_reset', $user['id'], $user['id']);
    
    if ($emailSent) {
        sendSuccessResponse([], '確認メールを新しいメールアドレスに送信しました。メールボックスをご確認ください');
    } else {
        error_log("Failed to send email reset verification email to: " . $newEmail);
        sendErrorResponse('メール送信に失敗しました。しばらくしてから再度お試しください', 500);
    }

} catch (PDOException $e) {
    error_log("Reset Email Database Error: " . $e->getMessage());
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        sendErrorResponse('データベースの設定が不完全です。管理者にお問い合わせください。', 500);
    } else {
        sendErrorResponse('データベースエラーが発生しました', 500);
    }
} catch (Exception $e) {
    error_log("Reset Email Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


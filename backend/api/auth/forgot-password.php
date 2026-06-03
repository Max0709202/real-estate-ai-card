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
    $logoUrl = rtrim(BASE_URL, '/') . '/assets/images/logo.png';
    $escapedResetLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
    $emailBody = "
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>パスワードリセットのお願い</title>
        </head>
        <body style='margin:0; padding:0; background-color:#f0f0f0;'>
            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f0f0f0;'>
                <tr>
                    <td align='center' style='padding:24px 12px;'>
                        <table width='600' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; width:100%; background-color:#ffffff; border:3px solid #a3a3a3; font-family:Hiragino Sans, Hiragino Kaku Gothic ProN, Meiryo, sans-serif; color:#333;'>
                            <tr>
                                <td align='center' style='padding:30px 20px 10px;'>
                                    <img src='{$logoUrl}' alt='不動産AI名刺' style='max-width:120px; height:auto; display:block;'>
                                </td>
                            </tr>
                            <tr>
                                <td style='background-color:#f9f9f9; padding:30px;'>
                                    <p style='margin:0 0 16px 0; line-height:1.8;'>パスワードリセットのリクエストを受け付けました。</p>
                                    <p style='margin:0 0 16px 0; line-height:1.8;'>以下のボタンをクリックして、パスワードを再設定してください。<br>このリンクは1時間有効です。</p>
                                    <div style='text-align:center; margin:28px 0;'>
                                        <a href='{$escapedResetLink}' target='_blank' rel='noopener noreferrer' style='display:inline-block; background:#0066cc; color:#ffffff; text-decoration:none; font-weight:bold; padding:12px 24px; border-radius:6px;'>パスワードをリセットする</a>
                                    </div>
                                    <p style='margin:0 0 10px 0; line-height:1.8;'>ボタンが開けない場合は、以下のURLをブラウザにコピーしてご利用ください。</p>
                                    <p style='margin:0 0 16px 0; word-break:break-all;'><a href='{$escapedResetLink}' target='_blank' rel='noopener noreferrer' style='color:#0066cc;'>{$escapedResetLink}</a></p>
                                    <p style='margin:0; line-height:1.8;'>このメールに心当たりがない場合は、このメールを無視してください。パスワードは変更されません。</p>
                                </td>
                            </tr>
                            <tr>
                                <td style='padding:20px 30px; border-top:1px solid #ddd; font-size:12px; color:#666;'>
                                    <p style='margin:0 0 5px 0;'>このメールは自動送信されています。返信はできません。</p>
                                    <p style='margin:0;'>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
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

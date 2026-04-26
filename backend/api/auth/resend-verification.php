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
    
    // トークンの有効期限を2時間後に設定（再送信時も新しい期限を設定）
    $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $stmt = $db->prepare("UPDATE users SET verification_token = ?, verification_token_expires_at = ? WHERE id = ?");
    $stmt->execute([$verificationToken, $tokenExpiresAt, $user['id']]);

    // メール認証の送信
    $verificationLink = BASE_URL . "/auth/verify.php?token=" . $verificationToken;
    
    // メール本文の作成
    $emailSubject = '【不動産AI名刺】メール認証のお願い（再送信）';
    $logoUrl = rtrim(BASE_URL, '/') . '/assets/images/logo.png';
    $emailBody = "
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>メール認証のお願い（再送信）</title>
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
                                    <p style='margin:0 0 16px 0; line-height:1.8;'>メール認証URLを再送信しました。</p>
                                    <p style='margin:0 0 16px 0; line-height:1.8;'>以下のボタンをクリックして、メール認証を完了してください。<br>このリンクは2時間有効です。</p>
                                    <div style='text-align:center; margin:28px 0;'>
                                        <a href='{$verificationLink}' target='_blank' rel='noopener noreferrer' style='display:inline-block; background:#0066cc; color:#ffffff; text-decoration:none; font-weight:bold; padding:12px 24px; border-radius:6px;'>メール認証を完了する</a>
                                    </div>
                                    <p style='margin:0 0 10px 0; line-height:1.8;'>ボタンが開けない場合は、以下のURLをブラウザにコピーしてご利用ください。</p>
                                    <p style='margin:0 0 16px 0; word-break:break-all;'><a href='{$verificationLink}' target='_blank' rel='noopener noreferrer' style='color:#0066cc;'>{$verificationLink}</a></p>
                                    <p style='margin:0; line-height:1.8;'>このメールに覚えがない場合は、破棄してください。</p>
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


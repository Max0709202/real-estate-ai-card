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
            </head>
            <body style='margin:0; padding:0; background-color:#f0f0f0;'>

            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f0f0f0;'>
            <tr>
                <td align='center'>

                <!-- Container -->
                <table width='600' cellpadding='0' cellspacing='0' border='0' style='background-color:#ffffff; border:3px solid #a3a3a3; font-family:Hiragino Sans, Hiragino Kaku Gothic ProN, Meiryo, sans-serif; color:#333;'>

                    <!-- Header -->
                    <tr>
                    <td align='center' style='padding:30px 20px;'>
                        <div style='background:#ffffff; padding:15px; display:inline-block;'>
                        <img src='' . BASE_URL . '/assets/images/logo.png' alt='不動産AI名刺' style='max-width:100px; height:auto; display:block;'>
                        </div>
                    </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                    <td style='background:#f9f9f9; padding:30px;'>

                        <p style='margin:0 0 15px 0;'>
                        サブスクリプションがキャンセルされました。（{$initiatedBy}による操作）
                        </p>

                        <!-- Info Table -->
                        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='border-collapse:collapse; background:#ffffff; margin:20px 0;'>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold; width:30%;'>ユーザーID</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$userId}</td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>メールアドレス</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$userEmail}</td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>サブスクリプションID</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$subscriptionId}</td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>ビジネスカードID</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$businessCardId}</td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>URLスラッグ</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <span style='background:#fff3cd; padding:2px 6px;'>{$urlSlug}</span>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>キャンセル種別</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$cancellationType}</td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>操作者</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$initiatedBy}</td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>キャンセル日時</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>{$cancellationDate}</td>
                        </tr>

                        <!-- Optional Row -->
                        ' . ($cardFullUrl ? '
                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>名刺URL</td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <a href='{$cardFullUrl}' target='_blank' style='color:#0066cc; word-break:break-all;'>
                                {$cardFullUrl}
                            </a>
                            </td>
                        </tr>
                        ' : '') . '

                        </table>

                        <!-- Footer -->
                        <div style='margin-top:30px; padding-top:20px; border-top:1px solid #ddd; font-size:12px; color:#666;'>
                        <p style='margin:0 0 5px 0;'>このメールは自動送信されています。返信はできません。</p>
                        <p style='margin:0;'>© ' . date('Y') . ' 不動産AI名刺 All rights reserved.</p>
                        </div>

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


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
            <!DOCTYPE html>
            <html>
            <head>
            <meta charset='UTF-8'>
            </head>
            <body style='margin:0; padding:0; background-color:#f0f0f0;'>

            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f0f0f0;'>
            <tr>
                <td align='center'>

                <!-- Container -->
                <table width='600' cellpadding='0' cellspacing='0' border='0'
                        style='background-color:#ffffff; border:3px solid #a3a3a3; font-family:Hiragino Sans, Hiragino Kaku Gothic ProN, Meiryo, sans-serif; color:#333; margin:20px auto;'>

                    <!-- Header -->
                    <tr>
                    <td align='center' style='padding:30px 20px;'>
                        <table cellpadding='0' cellspacing='0' border='0'>
                        <tr>
                            <td style='background:#ffffff; padding:15px;'>
                            <img src='<?php echo BASE_URL; ?>/assets/images/logo.png'
                                alt='不動産AI名刺'
                                style='display:block; max-width:100px; height:auto;'>
                            </td>
                        </tr>
                        </table>
                    </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                    <td style='background:#f9f9f9; padding:30px;'>

                        <p style='margin:0 0 15px 0;'>
                        サブスクリプションがキャンセルされました。（<?php echo $initiatedBy; ?>による操作）
                        </p>

                        <!-- Info Table -->
                        <table width='100%' cellpadding='0' cellspacing='0' border='0'
                            style='border-collapse:collapse; background:#ffffff; margin:20px 0;'>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold; width:30%;'>
                            ユーザーID
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $userId; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            メールアドレス
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $userEmail; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            サブスクリプションID
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $subscriptionId; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            ビジネスカードID
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $businessCardId; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            URLスラッグ
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <span style='background:#fff3cd; padding:2px 6px; border-radius:3px;'>
                                <?php echo $urlSlug; ?>
                            </span>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            キャンセル種別
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $cancellationType; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            操作者
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $initiatedBy; ?>
                            </td>
                        </tr>

                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            キャンセル日時
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6;'>
                            <?php echo $cancellationDate; ?>
                            </td>
                        </tr>

                        <?php if (!empty($cardFullUrl)): ?>
                        <tr>
                            <td style='background:#e9ecef; padding:12px; border:1px solid #dee2e6; font-weight:bold;'>
                            名刺URL
                            </td>
                            <td style='padding:12px; border:1px solid #dee2e6; word-break:break-all;'>
                            <a href='<?php echo $cardFullUrl; ?>' target='_blank' style='color:#0066cc;'>
                                <?php echo $cardFullUrl; ?>
                            </a>
                            </td>
                        </tr>
                        <?php endif; ?>

                        </table>

                        <!-- Footer -->
                        <p style='margin-top:30px; padding-top:20px; border-top:1px solid #ddd; font-size:12px; color:#666;'>
                        このメールは自動送信されています。返信はできません。<br>
                        © <?php echo date('Y'); ?> 不動産AI名刺 All rights reserved.
                        </p>

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


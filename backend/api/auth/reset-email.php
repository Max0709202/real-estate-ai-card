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
    $verificationLink = BASE_URL . "/auth/verify-email-reset.php?token=" . urlencode($resetToken);
    
    // メール本文の作成
    $emailSubject = '【不動産AI名刺】メールアドレス変更の確認';
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


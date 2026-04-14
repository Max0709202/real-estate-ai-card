<?php
/**
 * Admin Registration API
 * Allows existing admins to register new admin users
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

    // Check if there's an admin session (for permission check)
    // If no session, allow registration but with limited permissions
    $currentAdminId = $_SESSION['admin_id'] ?? null;
    $currentAdminRole = $_SESSION['admin_role'] ?? null;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // Validation
    $errors = [];

    if (empty($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = '有効なメールアドレスを入力してください';
    }

    if (empty($input['password']) || strlen($input['password']) < 8) {
        $errors['password'] = 'パスワードは8文字以上で入力してください';
    }

    // Password confirmation validation
    if (isset($input['password_confirm'])) {
        if ($input['password'] !== $input['password_confirm']) {
            $errors['password_confirm'] = 'パスワードが一致しません';
        }
    }

    if (empty($input['role']) || !in_array($input['role'], ['admin', 'client'])) {
        $errors['role'] = '有効なロールを選択してください';
    }

    // If no current admin session, default to 'client' role
    if ($currentAdminId === null) {
        $input['role'] = 'client';
    } else {
        // Only admins can create other admins
        if ($input['role'] === 'admin' && $currentAdminRole !== 'admin') {
            $errors['role'] = '管理者ロールを付与する権限がありません';
        }
    }

    if (!empty($errors)) {
        sendErrorResponse('入力内容に誤りがあります', 400, $errors);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Start transaction
    $db->beginTransaction();

    try {
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            $db->rollBack();
            sendErrorResponse('このメールアドレスは既に登録されています', 400);
        }

        // 一般ユーザー（users）と同じメールでも管理者（admins）を登録可能（社員が名刺ユーザー兼管理者になるケース）

        // Hash password
        $passwordHash = hashPassword($input['password']);

        // Skip email verification for admin@rchukai.jp
        $skipVerification = ($input['email'] === 'admin@rchukai.jp');
        
        if ($skipVerification) {
            // Insert admin without verification token, already verified
            $stmt = $db->prepare("
                INSERT INTO admins (email, password_hash, role, email_verified, last_password_changed_by)
                VALUES (?, ?, ?, 1, ?)
            ");
            
            $stmt->execute([
                $input['email'],
                $passwordHash,
                $input['role'],
                $currentAdminId
            ]);
        } else {
            // Generate verification token
            $verificationToken = generateToken(32);
            
            // Token expires in 24 hours (different from regular users which is 15 minutes)
            $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // Insert admin
            $stmt = $db->prepare("
                INSERT INTO admins (email, password_hash, role, verification_token, verification_token_expires_at, email_verified, last_password_changed_by)
                VALUES (?, ?, ?, ?, ?, 0, ?)
            ");
            
            $stmt->execute([
                $input['email'],
                $passwordHash,
                $input['role'],
                $verificationToken,
                $tokenExpiresAt,
                $currentAdminId
            ]);
        }

        $adminId = $db->lastInsertId();
        
        // Commit transaction before sending email
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
            error_log("[Admin Registration] Duplicate email registration attempted: " . $input['email']);
            sendErrorResponse('このメールアドレスは既に登録されています', 400);
        }
        error_log("[Admin Registration Error] " . $e->getMessage());
        sendErrorResponse('管理者登録中にエラーが発生しました', 500);
    }

    // Skip email sending for admin@rchukai.jp
    if (!$skipVerification) {
        // Create verification link (different URL from regular users)
        $verificationLink = BASE_URL . "/admin/verify.php?token=" . urlencode($verificationToken);

        // Email subject (different from regular users)
        $emailSubject = '【管理者登録】メール認証のお願い - 不動産AI名刺管理システム';

        // HTML email body (different format from regular users)
        $emailBody = "
                <html>
                <head>
                <meta charset='UTF-8'>
                <title>不動産AI名刺</title>
                </head>
                <body style='margin:0; padding:0; background-color:#f5f5f5;'>

                <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f5f5f5;'>
                <tr>
                <td align='center'>

                <!-- Container -->
                <table width='600' cellpadding='0' cellspacing='0' border='0' style='background-color:#ffffff; border:3px solid #a3a3a3; font-family:Hiragino Sans, Hiragino Kaku Gothic ProN, Meiryo, sans-serif; color:#333;'>

                    <!-- Header -->
                    <tr>
                        <td align='center' style='padding:30px 20px;'>
                            <img src='' . BASE_URL . '/assets/images/logo.png' alt='不動産AI名刺' style='max-width:100px; height:auto;'>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style='padding:30px; background-color:#f9f9f9;'>

                            <p style='margin:0 0 20px 0;'>
                                サブスクリプションがキャンセルされました。（{$initiatedBy}による操作）
                            </p>

                            <!-- Info Table -->
                            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='border-collapse:collapse; background-color:#ffffff; margin:20px 0;'>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold; width:30%;'>ユーザーID</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$userId}</td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>メールアドレス</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$userEmail}</td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>サブスクリプションID</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$subscriptionId}</td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>ビジネスカードID</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$businessCardId}</td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>URLスラッグ</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>
                                        <span style='background:#fff3cd; padding:2px 6px;'>{$urlSlug}</span>
                                    </td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>キャンセル種別</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$cancellationType}</td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>操作者</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$initiatedBy}</td>
                                </tr>

                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>キャンセル日時</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>{$cancellationDate}</td>
                                </tr>

                                ' . ($cardFullUrl ? '
                                <tr>
                                    <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>名刺URL</td>
                                    <td style='padding:12px; border:1px solid #dee2e6;'>
                                        <a href='{$cardFullUrl}' target='_blank' style='color:#4461a5; word-break:break-all;'>
                                            {$cardFullUrl}
                                        </a>
                                    </td>
                                </tr>
                                ' : '') . '

                            </table>

                            <!-- Footer -->
                            <p style='margin-top:30px; font-size:12px; color:#666; text-align:center;'>
                                このメールは自動送信されています。返信はできません。
                            </p>

                            <p style='font-size:12px; color:#666; text-align:center;'>
                                © ' . date('Y') . ' 不動産AI名刺 All rights reserved.
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

        // Plain text version
        $emailBodyText = 
            "管理者アカウント登録\n\n" .
            "管理者アカウントの登録申請を受け付けました。\n\n" .
            "登録情報：\n" .
            "メールアドレス: {$input['email']}\n" .
            "ロール: " . ($input['role'] === 'admin' ? '管理者' : 'クライアント') . "\n" .
            "登録日時: " . date('Y年m月d日 H:i') . "\n\n" .
            "以下のリンクをクリックしてメール認証を完了してください（24時間有効）：\n" .
            "$verificationLink\n\n" .
            "期限を過ぎた場合は、管理者に連絡して再発行を依頼してください。\n\n" .
            "このメールに覚えがない場合は、すぐにシステム管理者に連絡してください。\n";

        // Send email
        $emailSent = sendEmail($input['email'], $emailSubject, $emailBody, $emailBodyText, 'admin_verification', $adminId, $adminId);

        if (!$emailSent) {
            error_log("[Email Error] Admin verification email send failed: " . $input['email']);
        }
    }

    $message = $skipVerification 
        ? '管理者登録が完了しました。メール認証は不要です。'
        : '管理者登録が完了しました。メール認証を行ってください。';

    sendSuccessResponse([
        'admin_id' => $adminId,
        'email' => $input['email'],
        'role' => $input['role'],
        'message' => $message
    ], '管理者登録が完了しました');

} catch (Exception $e) {
    error_log("Admin Registration Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

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

    // If no current admin session, default to 'client' role.
    // The public registration form does not expose a role selector.
    if ($currentAdminId === null) {
        $input['role'] = 'client';
    } else {
        if (empty($input['role']) || !in_array($input['role'], ['admin', 'client'])) {
            $errors['role'] = '有効なロールを選択してください';
        }

        // Only admins can create other admins
        if (($input['role'] ?? '') === 'admin' && $currentAdminRole !== 'admin') {
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

        $logoUrl = rtrim(BASE_URL, '/') . '/assets/images/logo.png';
        $roleLabel = $input['role'] === 'admin' ? '管理者' : 'クライアント';
        $registrationDate = date('Y年m月d日 H:i');
        $escapedEmail = htmlspecialchars($input['email'], ENT_QUOTES, 'UTF-8');
        $escapedRoleLabel = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
        $escapedRegistrationDate = htmlspecialchars($registrationDate, ENT_QUOTES, 'UTF-8');
        $escapedVerificationLink = htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8');

        // HTML email body (admin verification)
        $emailBody = "
            <html>
            <head>
                <meta charset='UTF-8'>
                <title>管理者登録 メール認証</title>
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
                                        <p style='margin:0 0 16px 0; line-height:1.8;'>不動産AI名刺 管理システムの管理者アカウント登録を受け付けました。</p>
                                        <p style='margin:0 0 16px 0; line-height:1.8;'>以下の内容をご確認のうえ、ボタンをクリックしてメール認証を完了してください。<br>このリンクは24時間有効です。</p>

                                        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='border-collapse:collapse; background-color:#ffffff; margin:20px 0;'>
                                            <tr>
                                                <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold; width:30%;'>メールアドレス</td>
                                                <td style='padding:12px; border:1px solid #dee2e6;'>{$escapedEmail}</td>
                                            </tr>
                                            <tr>
                                                <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>ロール</td>
                                                <td style='padding:12px; border:1px solid #dee2e6;'>{$escapedRoleLabel}</td>
                                            </tr>
                                            <tr>
                                                <td style='padding:12px; border:1px solid #dee2e6; background:#e9ecef; font-weight:bold;'>登録日時</td>
                                                <td style='padding:12px; border:1px solid #dee2e6;'>{$escapedRegistrationDate}</td>
                                            </tr>
                                        </table>

                                        <div style='text-align:center; margin:28px 0;'>
                                            <a href='{$escapedVerificationLink}' target='_blank' rel='noopener noreferrer' style='display:inline-block; background:#0066cc; color:#ffffff; text-decoration:none; font-weight:bold; padding:12px 24px; border-radius:6px;'>メール認証を完了する</a>
                                        </div>
                                        <p style='margin:0 0 10px 0; line-height:1.8;'>ボタンが開けない場合は、以下のURLをブラウザにコピーしてご利用ください。</p>
                                        <p style='margin:0 0 16px 0; word-break:break-all;'><a href='{$escapedVerificationLink}' target='_blank' rel='noopener noreferrer' style='color:#0066cc;'>{$escapedVerificationLink}</a></p>
                                        <p style='margin:0; line-height:1.8;'>このメールに覚えがない場合は、すぐにシステム管理者に連絡してください。</p>
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

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

        // Check if email exists in users table
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            $db->rollBack();
            sendErrorResponse('このメールアドレスは既にユーザーとして登録されています', 400);
        }

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
        $verificationLink = BASE_URL . "/frontend/admin/verify.php?token=" . urlencode($verificationToken);

        // Email subject (different from regular users)
        $emailSubject = '【管理者登録】メール認証のお願い - 不動産AI名刺管理システム';

        // HTML email body (different format from regular users)
        $emailBody = "
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.8; color: #333; background-color: #f5f5f5; }
                .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 650px; margin: 30px auto; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { color: #fff; padding: 30px 20px; text-align: center; }
                .header .logo-container {padding: 15px;  border-radius: 8px; display: inline-block; margin: 0 auto; }
                .header img { max-width: 200px; height: auto; display: block; margin: 0 auto; }
                .content { padding: 40px 30px; }
                .info-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .button-container { text-align: center; margin: 30px 0; }
                .button { display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .button:hover { opacity: 0.9; }
                .url-box { background: #fff; border: 2px dashed #ddd; padding: 15px; border-radius: 4px; margin: 20px 0; word-break: break-all; font-size: 12px; color: #666; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef; }
                .warning { color: #dc3545; font-weight: bold; }
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
                    <p>管理者アカウントの登録申請を受け付けました。</p>
                    <div class='info-box'>
                        <strong>登録情報：</strong><br>
                        メールアドレス: {$input['email']}<br>
                        ロール: " . ($input['role'] === 'admin' ? '管理者' : 'クライアント') . "<br>
                        登録日時: " . date('Y年m月d日 H:i') . "
                    </div>
                    <p>メール認証を完了するため、以下のリンクをクリックしてください。</p>
                    <div class='button-container'>
                        <a href='{$verificationLink}' class='button'>メール認証を完了する</a>
                    </div>
                    <p>もし上記のボタンがクリックできない場合は、以下のURLをコピーしてブラウザのアドレスバーに貼り付けてください。</p>
                    <div class='url-box'>{$verificationLink}</div>
                    <p class='warning'>※このリンクは24時間有効です。期限を過ぎた場合は、管理者に連絡して再発行を依頼してください。</p>
                    <p>このメールに心当たりがない場合は、すぐにシステム管理者に連絡してください。</p>
                </div>
                <div class='footer'>
                    <p>このメールは自動送信されています。返信はできません。</p>
                    <p>© " . date('Y') . " 不動産AI名刺管理システム All rights reserved.</p>
                </div>
            </div>
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

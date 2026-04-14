<?php
/**
 * Create Admin/Client Account API
 * For initial setup - requires super admin access
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // Validation
    if (empty($input['email']) || empty($input['password']) || empty($input['role'])) {
        sendErrorResponse('メールアドレス、パスワード、権限を入力してください', 400);
    }

    if (!in_array($input['role'], ['admin', 'client'])) {
        sendErrorResponse('権限は admin または client である必要があります', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$input['email']]);
    if ($stmt->fetch()) {
        sendErrorResponse('このメールアドレスは既に登録されています', 400);
    }

    // Hash password
    $passwordHash = hashPassword($input['password']);

    // Generate verification token
    $verificationToken = generateToken(32);
    $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    // Create admin account
    $stmt = $db->prepare("
        INSERT INTO admins (email, password_hash, role, verification_token, verification_token_expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $input['email'],
        $passwordHash,
        $input['role'],
        $verificationToken,
        $tokenExpiresAt
    ]);

    $adminId = $db->lastInsertId();

    // Send verification email
    $verificationLink = BASE_URL . "/admin/verify.php?token=" . urlencode($verificationToken);
    
    $emailSubject = '【不動産AI名刺】管理者アカウント登録';
    $emailBody = "
            <html>
            <head>
                <meta charset='UTF-8'>
            </head>
            <body style='margin:0;padding:0;background:#f4f6fb;font-family:'Hiragino Sans','Yu Gothic',Meiryo,sans-serif;color:#1f2937;'>
                <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background:#f4f6fb;padding:24px 12px;'>
                    <tr>
                        <td align='center'>
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='max-width:560px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                                <tr>
                                    <td style='background:#2c5282;color:#ffffff;padding:18px 24px;font-size:20px;font-weight:700;'>
                                        不動産AI名刺 管理者
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:24px;'>
                                        <p style='margin:0 0 14px;font-size:15px;line-height:1.8;'>管理者アカウントが作成されました。</p>
                                        <p style='margin:0 0 20px;font-size:15px;line-height:1.8;'>以下のボタンをクリックしてメール認証を完了してください。</p>

                                        <p style='margin:0 0 22px;text-align:center;'>
                                            <a href='{$verificationLink}' style='display:inline-block;background:#2c5282;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:8px;'>
                                                メール認証を完了する
                                            </a>
                                        </p>

                                        <p style='margin:0 0 16px;font-size:13px;line-height:1.8;color:#6b7280;'>このリンクは24時間有効です。</p>

                                        <div style='margin-top:18px;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;'>
                                            <p style='margin:0 0 6px;font-size:12px;color:#6b7280;word-break:break-all;'>ボタンが押せない場合は、下記URLをコピーしてブラウザで開いてください。</p>
                                            <p style='margin:0;font-size:12px;color:#2563eb;word-break:break-all;'>{$verificationLink}</p>
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

    sendEmail($input['email'], $emailSubject, $emailBody, strip_tags($emailBody));

    sendSuccessResponse([
        'admin_id' => $adminId,
        'email' => $input['email'],
        'role' => $input['role']
    ], '管理者アカウントを作成しました。メール認証を完了してください。');

} catch (Exception $e) {
    error_log("Create admin error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}







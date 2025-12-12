<?php
/**
 * Admin/Client Registration API (with email verification)
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

    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        sendErrorResponse('有効なメールアドレスを入力してください', 400);
    }

    if (strlen($input['password']) < 8) {
        sendErrorResponse('パスワードは8文字以上で入力してください', 400);
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
        INSERT INTO admins (email, password_hash, role, verification_token, verification_token_expires_at, email_verified)
        VALUES (?, ?, ?, ?, ?, 0)
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
    $verificationLink = BASE_URL . "/frontend/admin/verify.php?token=" . urlencode($verificationToken);
    
    $emailSubject = '【不動産AI名刺】管理者アカウント登録';
    $emailBody = "
        <html>
        <head><meta charset='UTF-8'></head>
        <body>
            <p>管理者アカウントが作成されました。</p>
            <p>以下のリンクをクリックしてメール認証を完了してください：</p>
            <p><a href='{$verificationLink}'>メール認証を完了する</a></p>
            <p>このリンクは24時間有効です。</p>
            <p>認証後、ログインページからログインできます。</p>
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
    error_log("Admin registration error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}




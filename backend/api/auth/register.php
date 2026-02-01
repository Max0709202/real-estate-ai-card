<?php
/**
 * User Registration API
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

    // Validate invitation token if provided
    $invitationToken = $input['invitation_token'] ?? '';
    $tokenData = null;
    if (!empty($invitationToken)) {
        $database = new Database();
        $db = $database->getConnection();

        $stmt = $db->prepare("
            SELECT id, email, role_type
            FROM email_invitations
            WHERE invitation_token = ?
        ");
        $stmt->execute([$invitationToken]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenData) {
            sendErrorResponse('無効な招待トークンです', 400);
        }

        // For token-based registration, always use the token's role_type (existing or free)
        // This ensures the database user_type matches the invitation type
        if (in_array($tokenData['role_type'], ['existing', 'free'])) {
            $input['user_type'] = $tokenData['role_type'];
        }

        // Verify email matches token email
        if (!empty($input['email']) && $input['email'] !== $tokenData['email']) {
            sendErrorResponse('メールアドレスが招待トークンと一致しません', 400);
        }
    }

    // バリデーション
    $errors = [];

    if (empty($input['email']) || !validateEmail($input['email'])) {
        $errors['email'] = '有効なメールアドレスを入力してください。';
    }

    if (empty($input['password']) || strlen($input['password']) < 8) {
        $errors['password'] = 'パスワードは8文字以上で入力してください。';
    }

    if (empty($input['phone_number']) || !validatePhoneNumber($input['phone_number'])) {
        $errors['phone_number'] = '携帯電話番号の入力内容にエラーがあります。';
    }

    if (empty($input['user_type']) || !in_array($input['user_type'], ['new', 'existing', 'free'])) {
        $errors['user_type'] = 'ユーザータイプを選択してください。';
    }

    if (!empty($errors)) {
        sendErrorResponse('入力内容に誤りがあります。', 400, $errors);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Start transaction to prevent duplicate registrations
    $db->beginTransaction();

    try {
        // メールアドレスの重複チェック
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            $db->rollBack();
            sendErrorResponse('このメールアドレスは既に登録されています', 400);
        }

        // トークン生成
        $verificationToken = generateToken(32);

        // トークンの有効期限を15分後に設定
        $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // パスワードハッシュ
        $passwordHash = hashPassword($input['password']);

        // ユーザー登録
        // Include invitation_token if provided
        $stmt = $db->prepare("
        INSERT INTO users (email, password_hash, phone_number, user_type, verification_token, verification_token_expires_at, invitation_token, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([
            $input['email'],
            $passwordHash,
            $input['phone_number'],
            $input['user_type'],
            $verificationToken,
            $tokenExpiresAt,
            !empty($invitationToken) ? $invitationToken : null
        ]);

        $userId = $db->lastInsertId();

        // Commit transaction before sending email
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        // Check if it's a duplicate key error (SQLSTATE 23000 = Integrity constraint violation)
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
            error_log("[Registration] Duplicate email registration attempted: " . $input['email']);
            sendErrorResponse('このメールアドレスは既に登録されています', 400);
        }
        error_log("[Registration Error] " . $e->getMessage());
        sendErrorResponse('ユーザー登録中にエラーが発生しました', 500);
    }

    // 🔹 URLは必ずドメイン + HTTPS（IP NG）
    // Include user_type in verification link to distinguish user type during verification
    $userTypeParam = !empty($input['user_type']) ? '&type=' . urlencode($input['user_type']) : '';
    $verificationLink = "http://103.179.45.108/php/auth/verify.php?token=" . urlencode($verificationToken) . $userTypeParam;

    // 件名
    $emailSubject = '【不動産AI名刺】メール認証のお願い';

    // HTML本文
    $emailBodyHtml = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto; }
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { background: #ffffff; padding: 15px; display: inline-block; margin: 0 auto; }
            .header img { max-width: 200px; height: auto; display: block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .button { display: inline-block; padding: 12px 30px; background: #0066cc; color: #fff; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 200px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>不動産AI名刺へのご登録ありがとうございます。</p>
                <p>メール認証を完了するため、以下のリンクをクリックしてください。</p>
                <p style='text-align: center;'>
                    <a href='{$verificationLink}' style='color: #fff;' class='button'>メール認証を完了する</a>
                </p>
                <p>もし上記のボタンがクリックできない場合は、以下のURLをコピーしてブラウザのアドレスバーに貼り付けてください。</p>
                <p style='word-break: break-all; background: #fff; padding: 10px; border-radius: 4px; font-size: 12px;'>{$verificationLink}</p>
                <p><strong>※このリンクは15分間有効です。期限を過ぎた場合は、再度メール認証をリクエストしてください。</strong></p>
                <p>このメールに心当たりがない場合は、このメールを無視してください。</p>
                <div class='footer'>
                    <p>このメールは自動送信されています。返信はできません。</p>
                    <p>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    // プレーンテキスト（必須）
    $emailBodyText =
        "不動産AI名刺へのご登録ありがとうございます。\n\n" .
        "以下のリンクをクリックしてメール認証を完了してください（15分間有効）：\n" .
        "$verificationLink\n\n" .
        "期限を過ぎた場合は、再度メール認証をリクエストしてください。\n\n" .
        "このメールに覚えがない場合は破棄してください。\n";

    // 送信
    $emailSent = sendEmail($input['email'], $emailSubject, $emailBodyHtml, $emailBodyText, 'verification', $userId, $userId);

    if (!$emailSent) {
        error_log("[Email Error] Verification email send failed: " . $input['email']);
    }



    // 既存ユーザーの場合、ビジネスカードのURLスラッグを設定
    $urlSlug = null;
    $existingUrl = $input['existing_url'] ?? null;
    if ($input['user_type'] === 'existing' && $existingUrl) {
        // 既存URLからスラッグを抽出
        $urlParts = explode('/', trim($existingUrl, '/'));
        $urlSlug = end($urlParts);
    } elseif ($input['user_type'] === 'new') {
        // 新規ユーザー: 6桁の連続数字を発番
        $stmt = $db->prepare("SELECT current_number FROM tech_tool_url_counter LIMIT 1");
        $stmt->execute();
        $counter = $stmt->fetch();
        $urlSlug = str_pad($counter['current_number'], 6, '0', STR_PAD_LEFT);

        // カウンターをインクリメント
        $stmt = $db->prepare("UPDATE tech_tool_url_counter SET current_number = current_number + 1");
        $stmt->execute();
    } else {
        // 無料版も同様に連番を発番
        $stmt = $db->prepare("SELECT current_number FROM tech_tool_url_counter LIMIT 1");
        $stmt->execute();
        $counter = $stmt->fetch();
        $urlSlug = str_pad($counter['current_number'], 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("UPDATE tech_tool_url_counter SET current_number = current_number + 1");
        $stmt->execute();
    }

    // ビジネスカードの初期レコード作成
    $stmt = $db->prepare("
        INSERT INTO business_cards (user_id, url_slug, name, mobile_phone)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $urlSlug,
        !empty($input['name']) ? $input['name'] : '',
        $input['phone_number']
    ]);

    // セッションを設定してユーザーをログイン状態にする
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $input['email'];
    $_SESSION['user_type'] = $input['user_type'];

    // 管理者に新規登録通知メールを送信
    $adminNotificationSent = sendAdminNotificationEmail($input['email'], $input['user_type'], $userId, $urlSlug);
    if (!$adminNotificationSent) {
        error_log("[Email Error] Admin notification email send failed for user ID: " . $userId);
    }

    sendSuccessResponse([
        'user_id' => $userId,
        'email' => $input['email'],
        'user_type' => $input['user_type'],
        'url_slug' => $urlSlug,
        'message' => '登録が完了しました。メール認証を行ってください。'
    ], 'ユーザー登録が完了しました');

} catch (Exception $e) {
    error_log("Registration Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


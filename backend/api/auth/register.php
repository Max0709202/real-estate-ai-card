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

        // For token-based registration, always use the token's role_type when it is 'existing'
        // This ensures the database user_type matches the invitation type
        if (($tokenData['role_type'] ?? null) === 'existing') {
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

    if (empty($input['user_type']) || !in_array($input['user_type'], ['new', 'existing'])) {
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
            if (($input['user_type'] ?? '') === 'existing' && !empty($invitationToken)) {
                sendJsonResponse([
                    'success' => false,
                    'message' => 'このメールアドレスは既に登録されています。ログインしてお進みください。',
                    'redirect_login' => true,
                ], 400);
            }
            sendErrorResponse('このメールアドレスは既に登録されています', 400);
        }

        // トークン生成
        $verificationToken = generateToken(32);

        // トークンの有効期限を2時間後に設定
        $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

        // パスワードハッシュ
        $passwordHash = hashPassword($input['password']);

        // ERA会員フラグ: 既存ユーザーはフォームの is_era_member、または旧フローのセッション
        $isEraMember = 0;
        if (!empty($_SESSION['pending_era_membership']) &&
            !empty($_SESSION['pending_invitation_email']) &&
            $_SESSION['pending_invitation_email'] === $input['email']) {
            $isEraMember = $_SESSION['pending_era_membership'] ? 1 : 0;
            unset($_SESSION['pending_era_membership']);
            unset($_SESSION['pending_invitation_email']);
        } elseif (($input['user_type'] ?? '') === 'existing') {
            if (!array_key_exists('is_era_member', $input)) {
                $db->rollBack();
                sendErrorResponse('会員情報（ERA会員）の選択が必要です。', 400);
            }
            $v = $input['is_era_member'];
            $isEraMember = ($v === true || $v === 1 || $v === '1') ? 1 : 0;
        }

        // ユーザー登録
        // Include invitation_token and ERA membership if provided
        $stmt = $db->prepare("
        INSERT INTO users (email, password_hash, phone_number, user_type, verification_token, verification_token_expires_at, invitation_token, is_era_member, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        $stmt->execute([
            $input['email'],
            $passwordHash,
            $input['phone_number'],
            $input['user_type'],
            $verificationToken,
            $tokenExpiresAt,
            !empty($invitationToken) ? $invitationToken : null,
            $isEraMember
        ]);

        $userId = $db->lastInsertId();

        if (!empty($invitationToken) && $tokenData) {
            $invUpdate = $db->prepare('UPDATE email_invitations SET is_era_member = ? WHERE id = ?');
            $invUpdate->execute([$isEraMember, $tokenData['id']]);
            // LP の再利用と verification_token との論理衝突を防ぐため、招待一覧側のトークンはここで失効
            consumeEmailInvitationToken($db, $invitationToken);
        }

        // Commit transaction before sending email
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        // Check if it's a duplicate key error (SQLSTATE 23000 = Integrity constraint violation)
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
            error_log("[Registration] Duplicate email registration attempted: " . $input['email']);
            if (($input['user_type'] ?? '') === 'existing' && !empty($invitationToken)) {
                sendJsonResponse([
                    'success' => false,
                    'message' => 'このメールアドレスは既に登録されています。ログインしてお進みください。',
                    'redirect_login' => true,
                ], 400);
            }
            sendErrorResponse('このメールアドレスは既に登録されています', 400);
        }
        error_log("[Registration Error] " . $e->getMessage());
        sendErrorResponse('ユーザー登録中にエラーが発生しました', 500);
    }

    // 🔹 URLは必ずドメイン + HTTPS（IP NG）
    // Include user_type in verification link to distinguish user type during verification
    $userTypeParam = !empty($input['user_type']) ? '&type=' . urlencode($input['user_type']) : '';
    $verificationLink = BASE_URL . "/auth/verify.php?token=" . urlencode($verificationToken) . $userTypeParam;

    // 件名
    $emailSubject = '【不動産AI名刺】メール認証のお願い';

    // HTML本文
    $logoUrl = rtrim(BASE_URL, '/') . '/assets/images/logo.png';
    $emailBodyHtml = "
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>メール認証のお願い</title>
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
                                    <p style='margin:0 0 16px 0; line-height:1.8;'>不動産AI名刺へのご登録ありがとうございます。</p>
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

    // プレーンテキスト（必須）
    $emailBodyText =
        "不動産AI名刺へのご登録ありがとうございます。\n\n" .
        "以下のリンクをクリックしてメール認証を完了してください（2時間有効）：\n" .
        "$verificationLink\n\n" .
        "期限を過ぎた場合は、再度メール認証をリクエストしてください。\n\n" .
        "このメールに覚えがない場合は破棄してください。\n";

    // 送信
    $emailSent = sendEmail($input['email'], $emailSubject, $emailBodyHtml, $emailBodyText, 'verification', $userId, $userId);

    if (!$emailSent) {
        error_log("[Email Error] Verification email send failed: " . $input['email']);
    }



    // 名刺URL用: 全ユーザーで一意の url_slug（英数字12文字・乱数）。企業URL用: 既存の場合は existing_url から company_slug を設定。
    $urlSlug = generateUniqueBusinessCardUrlSlug($db);
    $companySlug = null;
    $existingUrl = $input['existing_url'] ?? null;

    if ($input['user_type'] === 'existing' && $existingUrl) {
        $urlParts = explode('/', trim($existingUrl, '/'));
        $extracted = end($urlParts);
        if ($extracted !== '' && preg_match('/^[a-zA-Z0-9\-]+$/', $extracted)) {
            $companySlug = strtolower($extracted);
        }
    }

    // ビジネスカードの初期レコード作成（url_slug=名刺用・一意、company_slug=ツール用・任意）
    $stmt = $db->prepare("
        INSERT INTO business_cards (user_id, url_slug, company_slug, name, mobile_phone)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $urlSlug,
        $companySlug,
        !empty($input['name']) ? $input['name'] : '',
        $input['phone_number']
    ]);

    // セッションを設定してユーザーをログイン状態にする
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $input['email'];
    $_SESSION['user_type'] = $input['user_type'];

    if (($input['user_type'] ?? '') !== 'existing') {
        unset($_SESSION['existing_invite_token']);
    }

    // 既存招待経由: update-era-membership.php と同様に IP を記録
    if (($input['user_type'] ?? '') === 'existing' && !empty($invitationToken)) {
        $clientIp  = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($clientIp !== '') {
            $ipStmt = $db->prepare('
                INSERT INTO existing_user_ips (user_id, ip_address, user_agent)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    user_agent = VALUES(user_agent),
                    created_at = created_at
            ');
            $ipStmt->execute([(int) $userId, $clientIp, $userAgent]);
        }
    }

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


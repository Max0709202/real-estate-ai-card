<?php
/**
 * Add a single invitation (by name + email + role) and send the invitation email immediately.
 * Used for "1件送信" (single send) without CSV.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireFullAdminAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? '');
    $roleType = isset($input['role_type']) && in_array($input['role_type'], ['new', 'existing'], true)
        ? $input['role_type'] : 'new';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendErrorResponse('有効なメールアドレスを入力してください', 400);
    }

    $database = new Database();
    $db = $database->getConnection();
    $adminId = $_SESSION['admin_id'];

    // Find or create invitation
    $stmt = $db->prepare("SELECT id, username, email, role_type, invitation_token FROM email_invitations WHERE email = ?");
    $stmt->execute([$email]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($invitation) {
        // Update username and role_type
        $stmt = $db->prepare("UPDATE email_invitations SET username = ?, role_type = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$username ?: null, $roleType, $invitation['id']]);
        $id = (int) $invitation['id'];
        $invitation['username'] = $username ?: $invitation['username'];
        $invitation['role_type'] = $roleType;
    } else {
        // Insert new record (no invitation_token yet; will be set when sending if role_type === 'existing')
        $stmt = $db->prepare("
            INSERT INTO email_invitations (username, email, imported_by, role_type, email_sent)
            VALUES (?, ?, ?, ?, 0)
        ");
        $stmt->execute([$username ?: null, $email, $adminId, $roleType]);
        $id = (int) $db->lastInsertId();
        $invitation = [
            'id' => $id,
            'username' => $username,
            'email' => $email,
            'role_type' => $roleType,
            'invitation_token' => null,
        ];
    }

    // Generate token for existing users
    $token = $invitation['invitation_token'];
    if ($roleType === 'existing') {
        do {
            $token = bin2hex(random_bytes(32));
            $checkStmt = $db->prepare("SELECT id FROM email_invitations WHERE invitation_token = ? AND id != ?");
            $checkStmt->execute([$token, $id]);
        } while ($checkStmt->fetch());

        $updateStmt = $db->prepare("UPDATE email_invitations SET invitation_token = ?, invitation_token_expires_at = NULL WHERE id = ?");
        $updateStmt->execute([$token, $id]);
    }

    // Determine landing page URL
    $baseUrl = rtrim(BASE_URL, '/');
    switch ($roleType) {
        case 'existing':
            $landingPage = $baseUrl . "/index.php?type=existing&token=" . urlencode($token);
            break;
        default:
            $landingPage = $baseUrl . "/index.php";
    }

    // Prepare email content (same as send-invitation-email.php)
    $displayName = $invitation['username'] ?: 'ご担当者様';
    $roleTypeJa = ['new' => '新規ユーザー', 'existing' => '既存ユーザー'];
    $roleLabel = $roleTypeJa[$roleType] ?? '既存ユーザー';

    $subject = "【不動産AI名刺】サービスへのご招待";

    $htmlBody = "
        <html>
        <head>
        <meta charset='UTF-8'>
        <title>不動産AI名刺</title>
        </head>
        <body style='margin:0; padding:0; background-color:#f0f0f0;'>

        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f0f0f0;'>
        <tr>
            <td align='center'>

            <!-- Container -->
            <table width='600' cellpadding='0' cellspacing='0' border='0' style='background-color:#ffffff; margin:20px auto; font-family:Hiragino Sans, Hiragino Kaku Gothic ProN, Meiryo, sans-serif; color:#333;'>

                <!-- Header -->
                <tr>
                <td style='background-color:#2c5282; color:#ffffff; padding:20px; text-align:center;'>
                    <h1 style='margin:0; font-size:24px;'>不動産AI名刺へようこそ</h1>
                </td>
                </tr>

                <!-- Content -->
                <tr>
                <td style='padding:30px; background-color:#f7fafc;'>

                    <p style='margin:0 0 15px 0;'>{$displayName} 様</p>

                    <p style='margin:0 0 15px 0;'>
                    不動産AI名刺サービスへのご招待です。
                    </p>

                    <p style='margin:0 0 15px 0;'>
                    下記のリンクからアクセスして、サービスをご利用ください。
                    </p>

                    <p style='margin:0 0 20px 0;'>
                    <strong>ユーザータイプ:</strong> {$roleLabel}
                    </p>

                    <!-- Button -->
                    <table cellpadding='0' cellspacing='0' border='0' align='center' style='margin:20px auto;'>
                    <tr>
                        <td align='center' bgcolor='#4461a5' style='border-radius:5px;'>
                        <a href='{$landingPage}' target='_blank'
                            style='display:inline-block; padding:12px 30px; color:#ffffff; text-decoration:none; font-weight:bold;'>
                            サービスにアクセス
                        </a>
                        </td>
                    </tr>
                    </table>

                    <!-- Link fallback -->
                    <p style='font-size:14px; color:#666; word-break:break-all;'>
                    リンク:
                    <a href='{$landingPage}' target='_blank' style='color:#4461a5;'>
                        {$landingPage}
                    </a>
                    </p>

                    <p style='margin-top:20px;'>
                    ご不明な点がございましたら、お気軽にお問い合わせください。
                    </p>

                </td>
                </tr>

                <!-- Footer -->
                <tr>
                <td style='text-align:center; padding:20px; font-size:12px; color:#718096;'>
                    このメールは不動産AI名刺から自動送信されています。
                </td>
                </tr>

            </table>

            </td>
        </tr>
        </table>

        </body>
        </html>
    ";

    $plainBody = "{$displayName} 様\n\n";
    $plainBody .= "不動産AI名刺サービスへのご招待です。\n\n";
    $plainBody .= "下記のリンクからアクセスして、サービスをご利用ください。\n\n";
    $plainBody .= "ユーザータイプ: {$roleLabel}\n\n";
    $plainBody .= "アクセスURL: {$landingPage}\n\n";
    $plainBody .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n\n";
    $plainBody .= "---\n";
    $plainBody .= "このメールは不動産AI名刺から自動送信されています。";

    $emailSent = sendEmail($invitation['email'], $subject, $htmlBody, $plainBody);

    if (!$emailSent) {
        sendErrorResponse('メールの送信に失敗しました', 500);
    }

    $stmt = $db->prepare("UPDATE email_invitations SET email_sent = 1, sent_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);

    logAdminChange($db, $adminId, $_SESSION['admin_email'] ?? '', 'other', 'email_invitations', $id, "1件招待メール送信: {$invitation['email']}");

    sendSuccessResponse(['id' => $id], '1件の招待メールを送信しました');

} catch (Exception $e) {
    error_log("Add and send single invitation error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

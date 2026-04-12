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
    $roleLabel = $roleTypeJa[$roleType] ?? '新規ユーザー';

    $subject = "【不動産AI名刺】サービスへのご招待";

    $htmlBody = "
    <html>
    <head>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2c5282; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f7fafc; padding: 30px; border: 1px solid #e2e8f0; }
            .button { display: inline-block; padding: 12px 30px; background:rgb(68 97 165); color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
            .footer { text-align: center; padding: 20px; color: #718096; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>不動産AI名刺へようこそ</h1>
            </div>
            <div class='content'>
                <p>{$displayName} 様</p>
                <p>不動産AI名刺サービスへのご招待です。</p>
                <p>下記のリンクからアクセスして、サービスをご利用ください。</p>
                <p><strong>ユーザータイプ:</strong> {$roleLabel}</p>
                <p style='text-align: center;'>
                    <a href='{$landingPage}' class='button' target='_blank'>サービスにアクセス</a>
                </p>
                <p style='font-size: 14px; color: #666;'>
                    リンク: <a href='{$landingPage}' style='word-wrap: break-word;' target='_blank'>{$landingPage}</a>
                </p>
                <p>ご不明な点がございましたら、お気軽にお問い合わせください。</p>
            </div>
            <div class='footer'>
                <p>このメールは不動産AI名刺から自動送信されています。</p>
            </div>
        </div>
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

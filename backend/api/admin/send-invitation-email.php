<?php
/**
 * Send Invitation Emails
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $ids = $input['ids'] ?? [];

    if (empty($ids) || !is_array($ids)) {
        sendErrorResponse('送信するIDを選択してください', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    $success = 0;
    $failed = 0;
    $errors = [];

    foreach ($ids as $id) {
        // Get invitation details
        $stmt = $db->prepare("SELECT username, email, role_type, invitation_token FROM email_invitations WHERE id = ?");
        $stmt->execute([$id]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invitation) {
            $errors[] = "ID {$id}: 招待情報が見つかりません";
            $failed++;
            continue;
        }

        // Generate token for existing users if not exists
        $token = $invitation['invitation_token'];
        if ($invitation['role_type'] === 'existing' && empty($token)) {
            // Generate unique token (64 characters)
            do {
                $token = bin2hex(random_bytes(32)); // 64 character hex string
                $checkStmt = $db->prepare("SELECT id FROM email_invitations WHERE invitation_token = ?");
                $checkStmt->execute([$token]);
            } while ($checkStmt->fetch());

            // Store token in database
            $updateStmt = $db->prepare("UPDATE email_invitations SET invitation_token = ? WHERE id = ?");
            $updateStmt->execute([$token, $id]);
        }

        // Determine landing page URL based on role type
        $baseUrl = BASE_URL;
        switch ($invitation['role_type']) {
            case 'new':
                $landingPage = "{$baseUrl}/register.php";
                break;
            case 'existing':
                // Use token-based URL format for existing users with type parameter
                $landingPage = BASE_URL . "/index.php?type=existing&token=" . urlencode($token);
                break;
            default:
                $landingPage = "{$baseUrl}/register.php";
        }

        // Prepare email content
        $username = $invitation['username'] ?: 'ご担当者様';
        $roleTypeJa = [
            'new' => '新規ユーザー',
            'existing' => '既存ユーザー',
        ];
        $roleLabel = $roleTypeJa[$invitation['role_type']] ?? '新規ユーザー';

        $subject = "【不動産AI名刺】サービスへのご招待";

        $htmlBody = "
        <html>
        <head>
            <style>
                body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2c5282; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f7fafc; padding: 30px; border: 1px solid #e2e8f0; }
                .button { display: inline-block; padding: 12px 30px; background: #3182ce; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #718096; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>不動産AI名刺へようこそ</h1>
                </div>
                <div class='content'>
                    <p>{$username} 様</p>
                    <p>不動産AI名刺サービスへのご招待です。</p>
                    <p>下記のリンクからアクセスして、サービスをご利用ください。</p>
                    <p><strong>ユーザータイプ:</strong> {$roleLabel}</p>
                    <p style='text-align: center;'>
                        <a href='{$landingPage}' class='button'>サービスにアクセス</a>
                    </p>
                    <p style='font-size: 14px; color: #666;'>
                        リンク: <a href='{$landingPage}'>{$landingPage}</a>
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

        $plainBody = "{$username} 様\n\n";
        $plainBody .= "不動産AI名刺サービスへのご招待です。\n\n";
        $plainBody .= "下記のリンクからアクセスして、サービスをご利用ください。\n\n";
        $plainBody .= "ユーザータイプ: {$roleLabel}\n\n";
        $plainBody .= "アクセスURL: {$landingPage}\n\n";
        $plainBody .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n\n";
        $plainBody .= "---\n";
        $plainBody .= "このメールは不動産AI名刺から自動送信されています。";

        // Send email
        $emailSent = sendEmail($invitation['email'], $subject, $htmlBody, $plainBody);

        if ($emailSent) {
            // Update database
            $stmt = $db->prepare("UPDATE email_invitations SET email_sent = 1, sent_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $success++;

            // Log admin change
            logAdminChange($db, $_SESSION['admin_id'], $_SESSION['admin_email'] ?? '', 'other', 'email_invitations', $id, "招待メール送信: {$invitation['email']}");
        } else {
            $errors[] = "ID {$id} ({$invitation['email']}): メール送信に失敗しました";
            $failed++;
        }
    }

    $message = "{$success}件のメールを送信しました";
    if ($failed > 0) {
        $message .= "、{$failed}件が失敗しました";
    }

    sendSuccessResponse([
        'success' => $success,
        'failed' => $failed,
        'errors' => $errors
    ], $message);

} catch (Exception $e) {
    error_log("Send Invitation Email Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


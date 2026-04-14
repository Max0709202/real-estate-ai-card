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
    requireFullAdminAccess();

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

        // Generate token for existing users (always regenerate for re-invite)
        $token = $invitation['invitation_token'];
        if ($invitation['role_type'] === 'existing') {
            // Generate unique token (64 characters)
            do {
                $token = bin2hex(random_bytes(32)); // 64 character hex string
                $checkStmt = $db->prepare("SELECT id FROM email_invitations WHERE invitation_token = ? AND id != ?");
                $checkStmt->execute([$token, $id]);
            } while ($checkStmt->fetch());

            // Store token without expiration (永続有効)
            $updateStmt = $db->prepare("UPDATE email_invitations SET invitation_token = ?, invitation_token_expires_at = NULL WHERE id = ?");
            $updateStmt->execute([$token, $id]);
        }

        // Determine landing page URL based on role type
        $baseUrl = BASE_URL;
        switch ($invitation['role_type']) {
            case 'new':
                $landingPage = "{$baseUrl}/index.php";
                break;
            case 'existing':
                // Use token-based URL format for existing users - dedicated verification page
                $landingPage = BASE_URL . "/index.php?type=existing&token=" . urlencode($token);
                break;
            default:
                $landingPage = "{$baseUrl}/index.php";
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


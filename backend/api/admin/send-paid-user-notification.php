<?php
/**
 * Send a bulk notification to paid users.
 * POST JSON: { dry_run?: bool, subject?: string, message?: string }
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
    if (!is_array($input)) {
        $input = $_POST;
    }

    $dryRun = !empty($input['dry_run']);
    $subject = trim((string)($input['subject'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));

    if (!$dryRun) {
        if ($subject === '') {
            sendErrorResponse('件名を入力してください', 400);
        }
        if (mb_strlen($subject) > 120) {
            sendErrorResponse('件名は120文字以内で入力してください', 400);
        }
        if ($message === '') {
            sendErrorResponse('本文を入力してください', 400);
        }
        if (mb_strlen($message) > 10000) {
            sendErrorResponse('本文は10000文字以内で入力してください', 400);
        }
    }

    $database = new Database();
    $db = $database->getConnection();

    $sql = "
        SELECT
            u.id AS user_id,
            u.email,
            MAX(NULLIF(bc.company_name, '')) AS company_name,
            MAX(NULLIF(bc.name, '')) AS recipient_name,
            COUNT(DISTINCT bc.id) AS business_card_count
        FROM users u
        JOIN business_cards bc ON bc.user_id = u.id
        WHERE u.email IS NOT NULL
          AND u.email <> ''
          AND u.status = 'active'
          AND COALESCE(bc.payment_status, 'UNUSED') IN ('CR', 'BANK_PAID', 'ST')
          AND COALESCE(bc.card_status, 'active') <> 'canceled'
        GROUP BY u.id, u.email
        ORDER BY u.id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($dryRun) {
        sendSuccessResponse([
            'recipient_count' => count($recipients),
        ], '対象件数を取得しました');
    }

    if (count($recipients) === 0) {
        sendErrorResponse('送信対象の有料ユーザーが見つかりません', 400);
    }

    $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
    $logoUrl = htmlspecialchars($baseUrl . '/assets/images/logo.png', ENT_QUOTES, 'UTF-8');
    $mypageUrl = htmlspecialchars($baseUrl . '/edit.php', ENT_QUOTES, 'UTF-8');
    $subjectHtml = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $messageHtml = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false);
    $sentAt = date('Y年m月d日 H:i');
    $sentAtHtml = htmlspecialchars($sentAt, ENT_QUOTES, 'UTF-8');
    $yearHtml = htmlspecialchars(date('Y'), ENT_QUOTES, 'UTF-8');

    $htmlBody = <<<HTML
        <html>
        <head>
            <meta charset="UTF-8">
            <title>{$subjectHtml}</title>
        </head>
        <body style="margin:0; padding:0; background-color:#f0f0f0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f0;">
                <tr>
                    <td align="center" style="padding:24px 12px;">
                        <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#ffffff; border:3px solid #a3a3a3; font-family:Hiragino Sans, Hiragino Kaku Gothic ProN, Meiryo, sans-serif; color:#333;">
                            <tr>
                                <td align="center" style="padding:30px 20px 10px;">
                                    <img src="{$logoUrl}" alt="不動産AI名刺" style="max-width:120px; height:auto; display:block;">
                                </td>
                            </tr>
                            <tr>
                                <td style="background-color:#f9f9f9; padding:30px;">
                                    <h1 style="margin:0 0 20px 0; font-size:20px; line-height:1.5; color:#2c5282;">{$subjectHtml}</h1>
                                    <div style="font-size:15px; line-height:1.9; color:#333;">{$messageHtml}</div>
                                    <div style="text-align:center; margin:28px 0;">
                                        <a href="{$mypageUrl}" target="_blank" rel="noopener noreferrer" style="display:inline-block; background:#0066cc; color:#ffffff; text-decoration:none; font-weight:bold; padding:12px 24px; border-radius:6px;">マイページを開く</a>
                                    </div>
                                    <p style="margin:20px 0 0 0; color:#666; font-size:13px;">送信日時: {$sentAtHtml}</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:20px 30px; border-top:1px solid #ddd; font-size:12px; color:#666;">
                                    <p style="margin:0 0 5px 0;">このメールは不動産AI名刺から送信されています。返信はできません。</p>
                                    <p style="margin:0;">© {$yearHtml} 不動産AI名刺 All rights reserved.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
HTML;

    $plainBody = $subject . "\n\n" . $message . "\n\n" .
        "マイページ: " . $baseUrl . "/edit.php\n" .
        "送信日時: " . $sentAt . "\n";

    $campaignId = time();
    $sent = 0;
    $failed = 0;
    $failedEmails = [];

    foreach ($recipients as $recipient) {
        $email = trim((string)$recipient['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $failed++;
            $failedEmails[] = $email !== '' ? $email : '(empty)';
            logEmail(
                $email !== '' ? $email : '(empty)',
                $subject,
                'paid_user_notification',
                'failed',
                0,
                null,
                'Invalid recipient email',
                (int)$recipient['user_id'],
                $campaignId
            );
            continue;
        }

        $ok = sendEmail(
            $email,
            $subject,
            $htmlBody,
            $plainBody,
            'paid_user_notification',
            (int)$recipient['user_id'],
            $campaignId
        );

        if ($ok) {
            $sent++;
        } else {
            $failed++;
            if (count($failedEmails) < 20) {
                $failedEmails[] = $email;
            }
        }
    }

    logAdminChange(
        $db,
        (int)$_SESSION['admin_id'],
        $_SESSION['admin_email'] ?? '',
        'other',
        'other',
        null,
        "有料ユーザー一斉通知送信: 件名={$subject}, 対象=" . count($recipients) . "件, 送信成功={$sent}件, 失敗={$failed}件"
    );

    sendSuccessResponse([
        'recipient_count' => count($recipients),
        'sent' => $sent,
        'failed' => $failed,
        'failed_emails' => $failedEmails,
    ], "有料ユーザーへの通知を送信しました（成功 {$sent}件 / 失敗 {$failed}件）");
} catch (Exception $e) {
    error_log('Paid user notification send error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

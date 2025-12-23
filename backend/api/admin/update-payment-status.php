<?php
/**
 * API: Update Payment Status (Admin Only)
 * Allows admin to toggle BANK_PENDING to BANK_PAID
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../../includes/qr-helper.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    startSessionIfNotStarted();
    requireAdmin(); // Only admins can update payment status

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $businessCardId = $input['business_card_id'] ?? null;
    $newStatus = $input['payment_status'] ?? null;

    if (empty($businessCardId)) {
        sendErrorResponse('ビジネスカードIDが必要です', 400);
    }

    // Validate new status: Only BANK_PENDING or BANK_PAID are allowed
    if (!in_array($newStatus, ['BANK_PENDING', 'BANK_PAID'])) {
        sendErrorResponse('無効なステータスです。管理者は「振込予定」と「振込済」の間での変更のみ可能です。', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Get current payment status
    $stmt = $db->prepare("
        SELECT bc.id, bc.payment_status, bc.user_id, bc.url_slug, bc.qr_code_issued,
               u.email as user_email, u.user_type
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.id = ?
    ");
    $stmt->execute([$businessCardId]);
    $businessCard = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // Validate transition: Only BANK_PENDING <-> BANK_PAID transitions are allowed
    $currentStatus = $businessCard['payment_status'];
    if (!in_array($currentStatus, ['BANK_PENDING', 'BANK_PAID'])) {
        sendErrorResponse('このステータスからは変更できません。振込予定または振込済の状態でのみ変更できます。', 400);
    }
    
    // Prevent same status
    if ($currentStatus === $newStatus) {
        sendErrorResponse('すでに' . ($newStatus === 'BANK_PAID' ? '振込済' : '振込予定') . 'の状態です。', 400);
    }

    // Start transaction
    $db->beginTransaction();

    try {
        // Update payment_status
        // Note: We do NOT auto-open (is_published) when payment_status becomes allowed.
        // Admin must manually enable OPEN. The enforceOpenPaymentStatusRule will handle disallowed transitions.
        $stmt = $db->prepare("
            UPDATE business_cards
            SET payment_status = ?
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $businessCardId]);
        
        // Enforce rule: if changing to disallowed status, force close (extra safety)
        enforceOpenPaymentStatusRule($db, $businessCardId, $newStatus);

        // Update payment record based on new status
        if ($newStatus === 'BANK_PAID') {
            // When changing to BANK_PAID, update payment record
            $stmt = $db->prepare("
                UPDATE payments
                SET payment_status = 'completed', paid_at = NOW()
                WHERE business_card_id = ? AND payment_method = 'bank_transfer' AND payment_status = 'pending'
            ");
            $stmt->execute([$businessCardId]);

            // Generate QR code if not already issued (only when changing to BANK_PAID)
            if (!$businessCard['qr_code_issued']) {
                $qrResult = generateBusinessCardQRCode($businessCardId, $db);
                if ($qrResult['success']) {
                    error_log("QR code generated for business_card_id: {$businessCardId} after bank transfer confirmation");
                    
                    // Get user name for email
                    $stmt = $db->prepare("SELECT name FROM business_cards WHERE id = ?");
                    $stmt->execute([$businessCardId]);
                    $bcInfo = $stmt->fetch();
                    $userName = $bcInfo['name'] ?? $businessCard['url_slug'];
                    
                    // Send QR code email to user
                    sendQRCodeIssuedEmailToUser(
                        $businessCard['user_email'],
                        $userName,
                        $qrResult['business_card_url'] ?? (QR_CODE_BASE_URL . $businessCard['url_slug']),
                        $qrResult['qr_code_url'] ?? '',
                        $businessCard['url_slug']
                    );
                } else {
                    error_log("Failed to generate QR code for business_card_id: {$businessCardId} - " . ($qrResult['message'] ?? 'Unknown error'));
                }
            }
        } else {
            // When changing back to BANK_PENDING, update payment record to pending (but don't delete it)
            $stmt = $db->prepare("
                UPDATE payments
                SET payment_status = 'pending', paid_at = NULL
                WHERE business_card_id = ? AND payment_method = 'bank_transfer'
            ");
            $stmt->execute([$businessCardId]);
        }

        // Log the change
        $adminId = $_SESSION['admin_id'];
        $adminEmail = $_SESSION['admin_email'] ?? 'System';
        $statusText = [
            'BANK_PENDING' => '振込予定',
            'BANK_PAID' => '振込済'
        ];
        $currentStatusText = $statusText[$currentStatus] ?? $currentStatus;
        $newStatusText = $statusText[$newStatus] ?? $newStatus;
        
        logAdminChange(
            $db,
            $adminId,
            $adminEmail,
            'payment_status_updated',
            'business_cards',
            $businessCardId,
            "入金状況変更: {$currentStatusText} → {$newStatusText} (ユーザー: {$businessCard['user_email']}, URL: {$businessCard['url_slug']})"
        );

        $db->commit();

        // Success message based on direction
        $message = $newStatus === 'BANK_PAID' 
            ? '入金状況を「振込済」に更新しました。QRコードが発行され、ユーザーにメールが送信されました。'
            : '入金状況を「振込予定」に戻しました。';

        sendSuccessResponse([
            'business_card_id' => $businessCardId,
            'payment_status' => $newStatus
        ], $message);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Update Payment Status Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


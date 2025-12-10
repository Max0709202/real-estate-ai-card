<?php
/**
 * QR Code Generation API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/qr-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    $database = new Database();
    $db = $database->getConnection();

    // ビジネスカード取得
    $stmt = $db->prepare("
        SELECT bc.id, bc.url_slug, bc.qr_code_issued, p.payment_status
        FROM business_cards bc
        LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
        WHERE bc.user_id = ?
    ");
    $stmt->execute([$userId]);
    $businessCard = $stmt->fetch();

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // 決済確認（新規・既存ユーザーの場合）
    if ($businessCard['payment_status'] !== 'completed' && !empty($businessCard['payment_status'])) {
        sendErrorResponse('決済が完了していません', 400);
    }

    // Generate QR code using helper function
    $result = generateBusinessCardQRCode($businessCard['id'], $db);
    
    if (!$result['success']) {
        sendErrorResponse($result['message'] ?? 'QRコードの生成に失敗しました', 500);
    }

    // 通知メール送信
    $stmt = $db->prepare("SELECT email, user_type FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user) {
        // 通知メール送信
        $notificationEmail = NOTIFICATION_EMAIL;
        $subject = '新規登録者がありました - 不動産AI名刺';
        $message = "
新しいユーザーが登録されました。

メールアドレス: {$user['email']}
ユーザータイプ: {$user['user_type']}
URLスラッグ: {$businessCard['url_slug']}
QRコード発行日時: " . date('Y-m-d H:i:s') . "
        ";
        
        // sendEmail($notificationEmail, $subject, $message);
    }

    sendSuccessResponse([
        'qr_code_url' => $result['qr_code_url'],
        'qr_code_path' => $result['qr_code_path'],
        'business_card_url' => $result['business_card_url'],
        'url_slug' => $result['url_slug']
    ], 'QRコードを発行しました');

} catch (Exception $e) {
    error_log("QR Code Generation Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


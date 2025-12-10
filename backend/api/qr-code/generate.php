<?php
/**
 * QR Code Generation API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

// QR Codeライブラリ読み込み（PHP QR Code等を想定）
// require_once __DIR__ . '/../../vendor/phpqrcode/qrlib.php';

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

    // QRコードURL生成
    $qrUrl = QR_CODE_BASE_URL . $businessCard['url_slug'];
    
    // QRコードディレクトリ作成
    if (!is_dir(QR_CODE_DIR)) {
        mkdir(QR_CODE_DIR, 0755, true);
    }

    // QRコード生成（実際の実装ではQR Codeライブラリを使用）
    $qrCodeFileName = 'qr_' . $businessCard['url_slug'] . '.png';
    $qrCodePath = QR_CODE_DIR . $qrCodeFileName;
    $qrCodeRelativePath = 'uploads/qr_codes/' . $qrCodeFileName;

    // QRコード生成（簡易版 - 実際にはライブラリを使用）
    // QRcode::png($qrUrl, $qrCodePath, QR_ECLEVEL_H, 10, 2);

    // ビジネスカードにQRコード情報を保存
    $stmt = $db->prepare("
        UPDATE business_cards 
        SET qr_code = ?, qr_code_issued = 1, qr_code_issued_at = NOW(), is_published = 1
        WHERE id = ?
    ");
    $stmt->execute([$qrCodeRelativePath, $businessCard['id']]);

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
        'qr_code_url' => BASE_URL . '/' . $qrCodeRelativePath,
        'qr_code_path' => $qrCodeRelativePath,
        'business_card_url' => $qrUrl,
        'url_slug' => $businessCard['url_slug']
    ], 'QRコードを発行しました');

} catch (Exception $e) {
    error_log("QR Code Generation Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


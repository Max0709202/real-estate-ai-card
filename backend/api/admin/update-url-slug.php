<?php
/**
 * Update URL Slug API
 * Updates business card URL slug from admin dashboard
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();
header('Content-Type: application/json; charset=UTF-8');

try {
    // Check admin authentication
    if (empty($_SESSION['admin_id'])) {
        sendErrorResponse('管理者認証が必要です', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $userId = $input['user_id'] ?? null;
    $bcId = $input['business_card_id'] ?? null;
    $urlSlug = $input['url_slug'] ?? '';

    if (empty($bcId) || empty($urlSlug)) {
        sendErrorResponse('ビジネスカードIDとURLスラッグが必要です', 400);
    }

    // Validate slug format (alphanumeric and hyphens only)
    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $urlSlug)) {
        sendErrorResponse('スラッグは英数字とハイフンのみ使用できます', 400);
    }

    // Trim and lowercase
    $urlSlug = strtolower(trim($urlSlug));

    $database = new Database();
    $db = $database->getConnection();

    // Get current business card info
    $stmt = $db->prepare("
        SELECT bc.url_slug, bc.user_id, u.email 
        FROM business_cards bc 
        JOIN users u ON bc.user_id = u.id 
        WHERE bc.id = ?
    ");
    $stmt->execute([$bcId]);
    $bcInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bcInfo) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    $oldSlug = $bcInfo['url_slug'];

    // Check if slug already exists (for another user)
    if ($urlSlug !== $oldSlug) {
        $stmt = $db->prepare("SELECT id FROM business_cards WHERE url_slug = ? AND id != ?");
        $stmt->execute([$urlSlug, $bcId]);
        if ($stmt->fetch()) {
            sendErrorResponse('このスラッグは既に使用されています', 409);
        }
    }

    // Update the slug
    $stmt = $db->prepare("UPDATE business_cards SET url_slug = ? WHERE id = ?");
    $stmt->execute([$urlSlug, $bcId]);

    // Log the change
    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? '';
    
    logAdminChange(
        $db, 
        $adminId, 
        $adminEmail, 
        'url_slug_changed', 
        'business_card', 
        $bcId,
        "URLスラッグ変更: {$oldSlug} → {$urlSlug} (ユーザー: {$bcInfo['email']})"
    );

    sendSuccessResponse([
        'business_card_id' => $bcId,
        'url_slug' => $urlSlug,
        'old_slug' => $oldSlug
    ], 'URLスラッグを更新しました');

} catch (Exception $e) {
    error_log("Update URL Slug Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

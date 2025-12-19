<?php
/**
 * Update Admin Notes for Business Card
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

    if (empty($input['business_card_id'])) {
        sendErrorResponse('ビジネスカードIDが必要です', 400);
    }

    $businessCardId = (int)$input['business_card_id'];
    $adminNotes = $input['admin_notes'] ?? '';

    $database = new Database();
    $db = $database->getConnection();

    // Check if business card exists
    $stmt = $db->prepare("SELECT id FROM business_cards WHERE id = ?");
    $stmt->execute([$businessCardId]);
    $businessCard = $stmt->fetch();

    if (!$businessCard) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    // Update admin notes
    $stmt = $db->prepare("UPDATE business_cards SET admin_notes = ? WHERE id = ?");
    $stmt->execute([$adminNotes, $businessCardId]);

    // Log the change
    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? '';

    $stmt = $db->prepare("
        INSERT INTO admin_change_logs (admin_id, admin_email, change_type, target_type, target_id, description, changed_at)
        VALUES (?, ?, 'other', 'business_card', ?, ?, NOW())
    ");
    $description = '備考を更新しました';
    $stmt->execute([$adminId, $adminEmail, $businessCardId, $description]);

    sendSuccessResponse(['business_card_id' => $businessCardId], '備考を保存しました');

} catch (Exception $e) {
    error_log("Update Admin Notes Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


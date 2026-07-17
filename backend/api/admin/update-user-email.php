<?php
/**
 * Update User Email API (ダッシュボードのメールアドレス変更)
 * Updates users.email directly, with no confirmation round-trip.
 * Unlike the sibling update-* endpoints this is intentionally open to client-role
 * dashboard logins: cards are handed over pre-made with staff addresses, and the
 * recipient frequently never migrates the address themselves.
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
    $userId = (int)($input['user_id'] ?? 0);
    $newEmail = trim((string)($input['email'] ?? ''));

    if ($userId <= 0) {
        sendErrorResponse('ユーザーIDが必要です', 400);
    }

    if ($newEmail === '') {
        sendErrorResponse('メールアドレスを入力してください', 400);
    }

    if (mb_strlen($newEmail) > 255) {
        sendErrorResponse('メールアドレスは255文字以内で入力してください', 400);
    }

    if (!validateEmail($newEmail)) {
        sendErrorResponse('メールアドレスの形式が正しくありません', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT id, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendErrorResponse('ユーザーが見つかりません', 404);
    }

    $oldEmail = (string)$user['email'];

    if ($oldEmail === $newEmail) {
        sendSuccessResponse([
            'user_id' => $userId,
            'email' => $newEmail,
            'old_email' => $oldEmail
        ], 'メールアドレスは変更されていません');
    }

    // users.email is UNIQUE; check first so a collision reads as a message rather than a 500.
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$newEmail, $userId]);
    if ($stmt->fetch()) {
        sendErrorResponse('このメールアドレスは既に使用されています', 409);
    }

    // email_verified is deliberately left untouched: login rejects email_verified = 0,
    // so clearing it would lock the user out of the account being handed over.
    // Any staged self-service reset is dropped so its token cannot later revert this change.
    $stmt = $db->prepare("
        UPDATE users
        SET email = ?,
            email_reset_token = NULL,
            email_reset_token_expires_at = NULL,
            email_reset_new_email = NULL
        WHERE id = ?
    ");
    $stmt->execute([$newEmail, $userId]);

    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? '';
    logAdminChange(
        $db,
        $adminId,
        $adminEmail,
        'other',
        'user',
        $userId,
        "メールアドレス変更: {$oldEmail} → {$newEmail}"
    );

    sendSuccessResponse([
        'user_id' => $userId,
        'email' => $newEmail,
        'old_email' => $oldEmail
    ], 'メールアドレスを更新しました');

} catch (Exception $e) {
    error_log("Update User Email Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

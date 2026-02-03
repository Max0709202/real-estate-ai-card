<?php
/**
 * Update ERA Membership Status
 * Sets is_era_member flag for a user based on invitation token
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();
header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $token = $input['token'] ?? '';
    $isEraMember = isset($input['is_era_member']) ? (bool)$input['is_era_member'] : false;
    
    if (empty($token)) {
        sendErrorResponse('トークンが必要です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Get invitation details and verify token is valid
    $stmt = $db->prepare("
        SELECT id, email, role_type, invitation_token_expires_at
        FROM email_invitations
        WHERE invitation_token = ?
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invitation) {
        sendErrorResponse('無効なトークンです', 404);
    }

    // Check if token has expired
    if (!empty($invitation['invitation_token_expires_at'])) {
        $expiresAt = strtotime($invitation['invitation_token_expires_at']);
        if (time() > $expiresAt) {
            sendErrorResponse('招待リンクの有効期限が切れています。管理者に再送信を依頼してください。', 410);
        }
    }

    // Check if user exists with this email
    $userStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $userStmt->execute([$invitation['email']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Update existing user's ERA membership status
        $updateStmt = $db->prepare("UPDATE users SET is_era_member = ? WHERE id = ?");
        $updateStmt->execute([$isEraMember ? 1 : 0, $user['id']]);
    }
    
    // Always store ERA membership in the invitation record for guest access
    $updateInvStmt = $db->prepare("UPDATE email_invitations SET is_era_member = ? WHERE id = ?");
    $updateInvStmt->execute([$isEraMember ? 1 : 0, $invitation['id']]);
    
    // Also store in session for the current visit
    $_SESSION['guest_era_membership'] = $isEraMember;
    $_SESSION['guest_invitation_email'] = $invitation['email'];

    sendSuccessResponse([
        'is_era_member' => $isEraMember,
        'email' => $invitation['email'],
        'user_exists' => $user !== false
    ], 'ERA会員情報を更新しました');

} catch (Exception $e) {
    error_log("Update ERA Membership Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

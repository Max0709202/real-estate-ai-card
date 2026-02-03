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

    $userCreated = false;
    
    if ($user) {
        // Update existing user's ERA membership status
        $updateStmt = $db->prepare("UPDATE users SET is_era_member = ? WHERE id = ?");
        $updateStmt->execute([$isEraMember ? 1 : 0, $user['id']]);
    } else {
        // Create new account for existing user with default password
        $defaultPassword = 'Renewal4329';
        $passwordHash = hashPassword($defaultPassword);
        
        $insertStmt = $db->prepare("
            INSERT INTO users (email, password_hash, phone_number, user_type, status, email_verified, is_era_member, invitation_token, created_at)
            VALUES (?, ?, '', 'existing', 'active', 1, ?, ?, NOW())
        ");
        $insertStmt->execute([
            $invitation['email'],
            $passwordHash,
            $isEraMember ? 1 : 0,
            $token
        ]);
        
        $userId = $db->lastInsertId();
        $userCreated = true;
        
        // Create business_cards record for the new user (with empty url_slug for admin to fill)
        // Generate a temporary url_slug based on user ID
        $tempSlug = 'user-' . $userId . '-' . bin2hex(random_bytes(4));
        
        $bcStmt = $db->prepare("
            INSERT INTO business_cards (user_id, url_slug, payment_status, is_published, created_at)
            VALUES (?, ?, 'UNUSED', 0, NOW())
        ");
        $bcStmt->execute([$userId, $tempSlug]);
        
        // Get the newly created user
        $userStmt->execute([$invitation['email']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Always store ERA membership in the invitation record
    $updateInvStmt = $db->prepare("UPDATE email_invitations SET is_era_member = ? WHERE id = ?");
    $updateInvStmt->execute([$isEraMember ? 1 : 0, $invitation['id']]);
    
    // Auto-login: Set session variables for the user
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $invitation['email'];
    $_SESSION['user_type'] = 'existing';
    
    // Update last login time
    $updateLoginStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
    $updateLoginStmt->execute([$user['id']]);

    sendSuccessResponse([
        'is_era_member' => $isEraMember,
        'email' => $invitation['email'],
        'user_id' => $user['id'],
        'user_exists' => true,
        'user_created' => $userCreated,
        'default_password' => $userCreated ? 'Renewal4329' : null,
        'auto_logged_in' => true
    ], $userCreated ? 'アカウントが作成されました。' : 'ログインしました。');

} catch (Exception $e) {
    error_log("Update ERA Membership Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

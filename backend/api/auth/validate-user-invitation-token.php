<?php
/**
 * Validate User Invitation Token (Security Enhanced)
 * Validates that the invitation token belongs to the logged-in user
 * This prevents token misuse by ensuring tokens can only be used by the user they belong to
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    startSessionIfNotStarted();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }
    
    $token = $_GET['token'] ?? '';
    $userTypeParam = $_GET['type'] ?? null;
    
    if (empty($token)) {
        sendErrorResponse('トークンが提供されていません', 400);
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Security: Check if user is logged in and token belongs to them
    $userId = $_SESSION['user_id'] ?? null;
    
    if ($userId) {
        // If user is logged in, verify token belongs to their account
        $stmt = $db->prepare("
            SELECT id, email, user_type, invitation_token, email_verified
            FROM users
            WHERE id = ? AND invitation_token = ?
        ");
        $stmt->execute([$userId, $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            sendErrorResponse('このトークンはあなたのアカウントに紐づいていません', 403);
        }
        
        // Security: Verify user_type matches if provided
        if ($userTypeParam && $user['user_type'] !== $userTypeParam) {
            error_log("Security Warning: Token type parameter ({$userTypeParam}) doesn't match user type ({$user['user_type']}) for user ID: {$userId}");
            sendErrorResponse('ユーザータイプが一致しません', 403);
        }
        
        sendSuccessResponse([
            'valid' => true,
            'email' => $user['email'],
            'user_type' => $user['user_type'],
            'email_verified' => (bool)$user['email_verified']
        ], 'トークンは有効です');
        
    } else {
        // For non-logged-in users, check email_invitations table (pre-registration)
        $stmt = $db->prepare("
            SELECT id, email, role_type, email_sent
            FROM email_invitations
            WHERE invitation_token = ?
        ");
        $stmt->execute([$token]);
        $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invitation) {
            sendErrorResponse('無効なトークンです', 404);
        }
        
        // Security: Verify user_type matches if provided
        if ($userTypeParam && $invitation['role_type'] !== $userTypeParam) {
            error_log("Security Warning: Invitation token type parameter ({$userTypeParam}) doesn't match invitation type ({$invitation['role_type']}) for invitation ID: {$invitation['id']}");
            sendErrorResponse('ユーザータイプが一致しません', 403);
        }
        
        // Check if user already registered with this email (prevent token reuse after registration)
        $userStmt = $db->prepare("SELECT id, user_type, status FROM users WHERE email = ?");
        $userStmt->execute([$invitation['email']]);
        $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        sendSuccessResponse([
            'valid' => true,
            'email' => $invitation['email'],
            'role_type' => $invitation['role_type'],
            'user_exists' => $existingUser !== false,
            'user_id' => $existingUser['id'] ?? null,
            'user_status' => $existingUser['status'] ?? null
        ], 'トークンは有効です');
    }
    
} catch (Exception $e) {
    error_log("Validate User Invitation Token Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


<?php
/**
 * Validate Invitation Token
 * Checks if a token is valid and returns invitation details
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }
    
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        sendErrorResponse('トークンが提供されていません', 400);
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if token exists and get invitation details
    $stmt = $db->prepare("
        SELECT id, email, role_type, email_sent, sent_at
        FROM email_invitations
        WHERE invitation_token = ?
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invitation) {
        sendErrorResponse('無効なトークンです', 404);
    }
    
    // Check if user already registered with this email
    $userStmt = $db->prepare("SELECT id, user_type, status FROM users WHERE email = ?");
    $userStmt->execute([$invitation['email']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    sendSuccessResponse([
        'valid' => true,
        'email' => $invitation['email'],
        'role_type' => $invitation['role_type'],
        'user_exists' => $user !== false,
        'user_id' => $user['id'] ?? null,
        'user_status' => $user['status'] ?? null
    ], 'トークンは有効です');
    
} catch (Exception $e) {
    error_log("Validate Invitation Token Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


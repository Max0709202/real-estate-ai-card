<?php
/**
 * Get User by Invitation Token
 * Allows users to access their account using their invitation token
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
    
    // Check if user exists with this token
    $stmt = $db->prepare("
        SELECT id, email, user_type, status, created_at
        FROM users
        WHERE invitation_token = ?
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        sendErrorResponse('トークンに紐づくユーザーが見つかりません', 404);
    }
    
    sendSuccessResponse([
        'user_id' => $user['id'],
        'email' => $user['email'],
        'user_type' => $user['user_type'],
        'status' => $user['status']
    ], 'ユーザー情報を取得しました');
    
} catch (Exception $e) {
    error_log("Get User by Token Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


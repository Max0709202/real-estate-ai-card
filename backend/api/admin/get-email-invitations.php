<?php
/**
 * Get Email Invitations List
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendErrorResponse('Method not allowed', 405);
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Get all email invitations ordered by creation date
    $sql = "
        SELECT 
            id,
            username,
            email,
            role_type,
            email_sent,
            sent_at,
            created_at,
            updated_at
        FROM email_invitations
        ORDER BY created_at DESC
    ";
    
    $stmt = $db->query($sql);
    $invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendSuccessResponse([
        'invitations' => $invitations,
        'total' => count($invitations)
    ], 'データを取得しました');
    
} catch (Exception $e) {
    error_log("Get Email Invitations Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


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

    $check = validateInvitationTokenInDatabase($db, $token);
    if (!$check['ok']) {
        if (($check['error'] ?? '') === 'empty') {
            sendErrorResponse('トークンが提供されていません', 400);
        }
        sendErrorResponse('無効なトークンです', 404);
    }

    sendSuccessResponse($check['data'], 'トークンは有効です');
    
} catch (Exception $e) {
    error_log("Validate Invitation Token Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


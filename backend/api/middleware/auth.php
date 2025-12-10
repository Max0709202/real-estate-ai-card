<?php
/**
 * Authentication Middleware
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        sendErrorResponse('認証が必要です', 401);
    }
    return $_SESSION['user_id'];
}

function getCurrentUserId() {
    startSessionIfNotStarted();
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserType() {
    startSessionIfNotStarted();
    return $_SESSION['user_type'] ?? null;
}

function requireAdmin() {
    startSessionIfNotStarted();
    
    if (empty($_SESSION['admin_id'])) {
        sendErrorResponse('管理者権限が必要です', 403);
    }
    
    return $_SESSION['admin_id'];
}


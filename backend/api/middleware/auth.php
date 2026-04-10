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

/**
 * Require a full administrator (role admin), or legacy super-admin id 1.
 * Client-role dashboard logins can view data but must not mutate it via APIs.
 */
function requireFullAdminAccess() {
    startSessionIfNotStarted();

    if (empty($_SESSION['admin_id'])) {
        sendErrorResponse('管理者権限が必要です', 403);
    }

    $role = $_SESSION['admin_role'] ?? 'client';
    $id = (int) $_SESSION['admin_id'];

    if ($role !== 'admin' && $id !== 1) {
        sendErrorResponse('この操作には管理者（フル）権限が必要です', 403);
    }

    return $_SESSION['admin_id'];
}


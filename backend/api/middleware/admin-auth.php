<?php
/**
 * Admin/Client Authentication Middleware
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();

/**
 * Require admin authentication
 * @param string $requiredRole 'admin' or 'client' or null for any
 * @return array Admin data
 */
function requireAdminAuth($requiredRole = null) {
    if (empty($_SESSION['admin_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => '認証が必要です',
            'redirect' => BASE_URL . '/frontend/admin/login.php'
        ]);
        exit();
    }
    
    $adminRole = $_SESSION['admin_role'] ?? 'client';
    
    if ($requiredRole === 'admin' && $adminRole !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => '管理者権限が必要です'
        ]);
        exit();
    }
    
    return [
        'id' => $_SESSION['admin_id'],
        'email' => $_SESSION['admin_email'],
        'role' => $adminRole
    ];
}

/**
 * Check if current user is admin (can edit)
 * @return bool
 */
function isAdmin() {
    return !empty($_SESSION['admin_id']) && ($_SESSION['admin_role'] ?? 'client') === 'admin';
}

/**
 * Check if current user is client (read-only)
 * @return bool
 */
function isClient() {
    return !empty($_SESSION['admin_id']) && ($_SESSION['admin_role'] ?? 'client') === 'client';
}

/**
 * Get current admin info
 * @return array|null
 */
function getCurrentAdmin() {
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    
    return [
        'id' => $_SESSION['admin_id'],
        'email' => $_SESSION['admin_email'],
        'role' => $_SESSION['admin_role'] ?? 'client'
    ];
}




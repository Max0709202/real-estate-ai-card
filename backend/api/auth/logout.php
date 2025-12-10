<?php
/**
 * User Logout API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();

header('Content-Type: application/json; charset=UTF-8');

try {
    // セッション破棄
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();

    sendSuccessResponse([], 'ログアウトしました');

} catch (Exception $e) {
    error_log("Logout Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


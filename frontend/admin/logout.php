<?php
/**
 * Admin Logout Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

// 管理者セッションのみをクリア（通常ユーザーセッションは保持）
unset($_SESSION['admin_id']);
unset($_SESSION['admin_email']);
unset($_SESSION['admin_role']);

// セッションが完全に空の場合は破棄
if (empty($_SESSION)) {
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// ログインページにリダイレクト
header('Location: login.php');
exit();


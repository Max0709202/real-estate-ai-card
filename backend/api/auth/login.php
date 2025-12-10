<?php
/**
 * User Login API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    // バリデーション
    if (empty($input['email']) || empty($input['password'])) {
        sendErrorResponse('メールアドレスとパスワードを入力してください', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // ユーザー検索
    $stmt = $db->prepare("
        SELECT u.*, bc.id as business_card_id, bc.url_slug
        FROM users u
        LEFT JOIN business_cards bc ON u.id = bc.user_id
        WHERE u.email = ?
    ");
    
    $stmt->execute([$input['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // ユーザーが見つからない場合、管理者テーブルを確認
    if (!$user) {
        $adminStmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
        $adminStmt->execute([$input['email']]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin) {
            // 管理者アカウントが見つかった場合、パスワードを検証
            $storedHash = trim($admin['password_hash']);
            $inputPassword = trim($input['password']);
            $passwordValid = verifyPassword($inputPassword, $storedHash);
            
            // プレーンテキストのパスワードが検出された場合、自動的に再ハッシュ化
            if (!$passwordValid && $inputPassword === $storedHash) {
                $newHash = hashPassword($inputPassword);
                $updateStmt = $db->prepare("UPDATE admins SET password_hash = ?, last_password_change = NOW() WHERE id = ?");
                $updateStmt->execute([$newHash, $admin['id']]);
                error_log("SECURITY: Plain text password detected and rehashed for admin ID: " . $admin['id']);
                $passwordValid = true;
            }
            
            if ($passwordValid) {
                // 管理者としてログイン成功 - セッション設定
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];
                
                // 最終ログイン時刻更新
                $updateStmt = $db->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?");
                $updateStmt->execute([$admin['id']]);
                
                sendSuccessResponse([
                    'admin_id' => $admin['id'],
                    'email' => $admin['email'],
                    'role' => $admin['role'],
                    'is_admin' => true,
                    'redirect' => 'admin/dashboard.php'
                ], 'ログインに成功しました');
            } else {
                sendErrorResponse('メールアドレスまたはパスワードが正しくありません', 401);
            }
            exit;
        } else {
            sendErrorResponse('メールアドレスまたはパスワードが正しくありません', 401);
        }
    }

    // パスワード検証
    $storedHash = trim($user['password_hash']);
    $inputPassword = trim($input['password']);
    $passwordValid = verifyPassword($inputPassword, $storedHash);
    
    // プレーンテキストのパスワードが検出された場合、自動的に再ハッシュ化
    if (!$passwordValid && $inputPassword === $storedHash) {
        // プレーンテキストが検出されたので、適切にハッシュ化して保存
        $newHash = hashPassword($inputPassword);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $updateStmt->execute([$newHash, $user['id']]);
        
        error_log("SECURITY: Plain text password detected and rehashed for user ID: " . $user['id']);
        $passwordValid = true; // 再ハッシュ化後、認証を許可
    }
    
    if (!$passwordValid) {
        sendErrorResponse('メールアドレスまたはパスワードが正しくありません', 401);
    }

    // メール認証チェック
    if ($user['email_verified'] == 0) {
        sendErrorResponse('メール認証が完了していません。登録時に送信されたメールの認証リンクをクリックして認証を完了してください。', 403);
    }

    // ステータスチェック
    if ($user['status'] === 'suspended' || $user['status'] === 'cancelled') {
        sendErrorResponse('このアカウントは利用できません', 403);
    }

    // 最終ログイン時刻更新
    $stmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    // セッション設定
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_type'] = $user['user_type'];

    sendSuccessResponse([
        'user_id' => $user['id'],
        'email' => $user['email'],
        'user_type' => $user['user_type'],
        'business_card_id' => $user['business_card_id'],
        'url_slug' => $user['url_slug'],
        'status' => $user['status']
    ], 'ログインに成功しました');

} catch (Exception $e) {
    error_log("Login Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


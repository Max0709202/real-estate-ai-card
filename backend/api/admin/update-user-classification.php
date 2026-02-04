<?php
/**
 * Update User Classification API
 * Updates user_type and is_era_member based on classification selection
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

startSessionIfNotStarted();
header('Content-Type: application/json; charset=UTF-8');

try {
    // Check admin authentication
    if (empty($_SESSION['admin_id'])) {
        sendErrorResponse('管理者認証が必要です', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    // Log the raw input for debugging
    error_log("Classification Update Request: " . json_encode($input));
    
    $userId = isset($input['user_id']) ? intval($input['user_id']) : null;
    $bcId = isset($input['business_card_id']) ? intval($input['business_card_id']) : null;
    $classification = $input['classification'] ?? '';

    if (!$userId || empty($classification)) {
        error_log("Classification Update Validation Failed: user_id={$userId}, classification={$classification}");
        sendErrorResponse('ユーザーIDと分類が必要です', 400);
    }

    // Validate classification
    if (!in_array($classification, ['new', 'existing', 'era'])) {
        sendErrorResponse('無効な分類です', 400);
    }

    $database = new Database();
    $db = $database->getConnection();

    // Get current user info
    $stmt = $db->prepare("SELECT email, user_type, is_era_member FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendErrorResponse('ユーザーが見つかりません', 404);
    }

    // Determine user_type and is_era_member based on classification
    $newUserType = 'new';
    $newIsEraMember = 0;

    switch ($classification) {
        case 'new':
            $newUserType = 'new';
            $newIsEraMember = 0;
            break;
        case 'existing':
            $newUserType = 'existing';
            $newIsEraMember = 0;
            break;
        case 'era':
            $newUserType = 'existing'; // ERA members are also existing users
            $newIsEraMember = 1;
            break;
    }

    // Update user
    $stmt = $db->prepare("UPDATE users SET user_type = ?, is_era_member = ? WHERE id = ?");
    $result = $stmt->execute([$newUserType, $newIsEraMember, $userId]);
    
    // Log for debugging
    error_log("Classification Update: user_id={$userId}, new_type={$newUserType}, is_era={$newIsEraMember}, affected_rows=" . $stmt->rowCount());
    
    if (!$result || $stmt->rowCount() === 0) {
        error_log("Classification Update Failed: user_id={$userId}, execute_result=" . ($result ? 'true' : 'false'));
    }

    // Log the change
    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? '';
    
    $classificationText = [
        'new' => '新規',
        'existing' => '既存',
        'era' => 'ＥＲＡ'
    ];
    
    $oldClassification = $user['is_era_member'] ? 'era' : $user['user_type'];
    
    logAdminChange(
        $db, 
        $adminId, 
        $adminEmail, 
        'other',  // 'classification_changed' is not in the ENUM, use 'other' 
        'user', 
        $userId,
        "分類変更: {$classificationText[$oldClassification]} → {$classificationText[$classification]} (ユーザー: {$user['email']})"
    );

    sendSuccessResponse([
        'user_id' => $userId,
        'classification' => $classification,
        'user_type' => $newUserType,
        'is_era_member' => $newIsEraMember
    ], '分類を更新しました');

} catch (Exception $e) {
    error_log("Update User Classification Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

<?php
/**
 * 画像アップロードAPI
 * 
 * 自動リサイズ機能付き
 * - logo: 400x400px
 * - photo: 800x800px
 * - free: 1200x1200px
 */

// Ensure clean JSON output - disable all error display
ini_set('display_errors', 0);
error_reporting(0);

// Increase memory limit for large image processing
ini_set('memory_limit', '256M');

// Start output buffering first
ob_start();

// CORS: when request uses credentials, browser requires a specific origin (not *).
$allowedOrigins = ['https://ai-fcard.com', 'https://www.ai-fcard.com'];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

// Re-disable display errors (config.php may have enabled it)
ini_set('display_errors', 0);

// Helper function to send clean JSON response
function cleanJsonResponse($success, $data, $message, $statusCode = 200) {
    // Clear any buffered output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'data' => $data,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cleanJsonResponse(false, null, 'Method not allowed', 405);
    }

    $userId = requireAuth();

    // Check for upload errors first
    if (empty($_FILES['file'])) {
        // Check if there was a PHP upload error
        $uploadError = '';
        if (isset($_FILES['file']['error'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $uploadError = 'ファイルサイズが大きすぎます。10MB以下のファイルを選択してください。';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $uploadError = 'ファイルが完全にアップロードされませんでした。';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $uploadError = 'ファイルが選択されていません。';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                case UPLOAD_ERR_CANT_WRITE:
                case UPLOAD_ERR_EXTENSION:
                    $uploadError = 'サーバーエラーが発生しました。';
                    break;
                default:
                    $uploadError = 'ファイルがアップロードされていません';
            }
        } else {
            $uploadError = 'ファイルがアップロードされていません。ファイルサイズが大きすぎる可能性があります。';
        }
        cleanJsonResponse(false, null, $uploadError, 400);
    }

    // Check for upload error code
    if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'ファイルサイズが大きすぎます（サーバー制限超過）',
            UPLOAD_ERR_FORM_SIZE => 'ファイルサイズが大きすぎます',
            UPLOAD_ERR_PARTIAL => 'ファイルが完全にアップロードされませんでした',
            UPLOAD_ERR_NO_FILE => 'ファイルが選択されていません',
            UPLOAD_ERR_NO_TMP_DIR => 'サーバー設定エラー',
            UPLOAD_ERR_CANT_WRITE => 'サーバー書き込みエラー',
            UPLOAD_ERR_EXTENSION => 'アップロードが拒否されました'
        ];
        $errorMsg = isset($errorMessages[$_FILES['file']['error']]) 
            ? $errorMessages[$_FILES['file']['error']] 
            : 'アップロードエラーが発生しました';
        cleanJsonResponse(false, null, $errorMsg, 400);
    }

    $fileType = isset($_POST['file_type']) ? $_POST['file_type'] : 'photo';
    $file = $_FILES['file'];

    // 許可されたファイルタイプ
    $allowedTypes = ['logo', 'photo', 'free'];
    if (!in_array($fileType, $allowedTypes)) {
        $fileType = 'photo';
    }

    // アップロード処理（自動リサイズ含む）
    $uploadResult = uploadFile($file, $fileType . '/');

    if (!$uploadResult['success']) {
        cleanJsonResponse(false, null, $uploadResult['message'], 400);
    }

    // free 以外は DB に保存
    if ($fileType !== 'free') {
        $database = new Database();
        $db = $database->getConnection();

        $fieldName = ($fileType === 'logo') ? 'company_logo' : 'profile_photo';

        // Ensure we have a business card record
        $stmt = $db->prepare("SELECT id FROM business_cards WHERE user_id = ?");
        $stmt->execute([$userId]);
        $businessCard = $stmt->fetch();
        
        if ($businessCard) {
            // Update existing business card
            $stmt = $db->prepare("UPDATE business_cards SET $fieldName = ? WHERE user_id = ?");
            $result = $stmt->execute([$uploadResult['file_path'], $userId]);
            
            if (!$result) {
                error_log("Failed to update business card: " . print_r($stmt->errorInfo(), true));
            } else {
                error_log("Successfully updated business card $fieldName for user $userId: " . $uploadResult['file_path']);
            }
        } else {
            // Create new business card if it doesn't exist
            $stmt = $db->prepare("INSERT INTO business_cards (user_id, $fieldName) VALUES (?, ?)");
            $result = $stmt->execute([$userId, $uploadResult['file_path']]);
            
            if (!$result) {
                error_log("Failed to create business card: " . print_r($stmt->errorInfo(), true));
            } else {
                error_log("Successfully created business card with $fieldName for user $userId: " . $uploadResult['file_path']);
            }
        }
    }

    // レスポンスデータ
    $responseData = [
        'file_path' => BASE_URL . '/' . $uploadResult['file_path'],
        'file_name' => $uploadResult['file_name'],
        'file_type' => $fileType,
        'was_resized' => isset($uploadResult['was_resized']) ? $uploadResult['was_resized'] : false
    ];

    // リサイズ情報があれば追加
    if (isset($uploadResult['original_dimensions'])) {
        $responseData['original_dimensions'] = $uploadResult['original_dimensions'];
    }
    if (isset($uploadResult['final_dimensions'])) {
        $responseData['final_dimensions'] = $uploadResult['final_dimensions'];
    }
    if (isset($uploadResult['original_size']) && isset($uploadResult['final_size'])) {
        $responseData['original_size_kb'] = round($uploadResult['original_size'] / 1024, 2);
        $responseData['final_size_kb'] = round($uploadResult['final_size'] / 1024, 2);
    }

    $message = $responseData['was_resized'] 
        ? '画像をアップロードし、自動リサイズしました' 
        : 'ファイルをアップロードしました';

    cleanJsonResponse(true, $responseData, $message);

} catch (Exception $e) {
    error_log("Upload Error: " . $e->getMessage());
    cleanJsonResponse(false, null, 'サーバーエラーが発生しました', 500);
} catch (Error $e) {
    error_log("Upload Fatal Error: " . $e->getMessage());
    cleanJsonResponse(false, null, 'サーバーエラーが発生しました', 500);
}

<?php
/**
 * Common Utility Functions
 */

// Load Composer autoloader for PHPMailer and other dependencies
// require_once __DIR__ . '/../vendor/autoload.php';
// require __DIR__ . '/../config/config.php';
// require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * JSONレスポンスを送信
 */
function sendJsonResponse($data, $statusCode = 200) {
    // Clear any output buffer
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // End output buffering if active
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    
    exit();
}

/**
 * エラーレスポンスを送信
 */
function sendErrorResponse($message, $statusCode = 400, $errors = []) {
    $response = [
        'success' => false,
        'message' => $message
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    sendJsonResponse($response, $statusCode);
}

/**
 * 成功レスポンスを送信
 */
function sendSuccessResponse($data = [], $message = 'Success') {
    $response = [
        'success' => true,
        'message' => $message,
        'data' => $data
    ];
    
    sendJsonResponse($response, 200);
}

/**
 * パスワードハッシュ生成
 */
function hashPassword($password) {
    if (empty($password)) {
        throw new InvalidArgumentException('Password cannot be empty');
    }
    
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    if ($hash === false) {
        throw new RuntimeException('Failed to hash password');
    }
    
    return $hash;
}

/**
 * パスワード検証
 * プレーンテキストのパスワードが検出された場合、自動的に再ハッシュ化します
 */
function verifyPassword($password, $hash) {
    if (empty($password) || empty($hash)) {
        return false;
    }
    
    // Trim whitespace that might have been accidentally added
    $hash = trim($hash);
    
    // プレーンテキストのパスワードが保存されている場合を検出
    // bcryptハッシュは常に$2[ayb]$で始まり、60文字の長さです
    // より柔軟なチェック: $2[ayb]$の後に数字と$が続き、その後53文字
    if (!preg_match('/^\$2[ayb]\$\d{2}\$[A-Za-z0-9\.\/]{53}$/', $hash)) {
        // プレーンテキストの可能性がある場合、直接比較を試みる
        // ただし、これはセキュリティリスクなので、ログに記録して再ハッシュ化を推奨
        if ($password === $hash) {
            error_log("SECURITY WARNING: Plain text password detected in database. Password should be rehashed immediately.");
            // 自動的に再ハッシュ化を試みる（データベース接続が必要な場合は呼び出し元で処理）
            return true; // 一時的にtrueを返すが、呼び出し元で再ハッシュ化が必要
        }
        // ハッシュ形式が無効でも、password_verifyを試してみる（互換性のため）
        // password_verifyは自分で形式をチェックするので、これで十分
    }
    
    return password_verify($password, $hash);
}

/**
 * ランダムトークン生成
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * 画像リサイズ
 * 
 * @param string $filePath 画像ファイルパス
 * @param int $maxWidth 最大幅
 * @param int $maxHeight 最大高さ
 * @param int $quality JPEG/WebP品質 (1-100)
 * @return array|false リサイズ結果の配列、または失敗時はfalse
 */
function resizeImage($filePath, $maxWidth = 800, $maxHeight = 800, $quality = 85) {
    if (!file_exists($filePath)) {
        error_log("resizeImage: File not found - $filePath");
        return false;
    }

    $imageInfo = @getimagesize($filePath);
    if ($imageInfo === false) {
        error_log("resizeImage: Cannot get image info - $filePath");
        return false;
    }

    $originalWidth = $imageInfo[0];
    $originalHeight = $imageInfo[1];
    $mimeType = $imageInfo['mime'];
    $originalSize = filesize($filePath);

    // Check if image is too large to process (estimate memory needed)
    // GD needs roughly: width * height * 4 bytes per pixel * 2 (source + dest)
    $estimatedMemory = $originalWidth * $originalHeight * 4 * 2;
    $memoryLimit = (int)ini_get('memory_limit') * 1024 * 1024;
    
    // If estimated memory is more than 50% of limit, increase memory limit
    if ($estimatedMemory > $memoryLimit * 0.5) {
        $neededMemory = $estimatedMemory * 2.5; // Add buffer
        $newLimit = max(256, (int)($neededMemory / 1024 / 1024)) . 'M';
        @ini_set('memory_limit', $newLimit);
        error_log("resizeImage: Increased memory limit to $newLimit for {$originalWidth}x{$originalHeight} image");
    }

    // リサイズ不要の場合
    if ($originalWidth <= $maxWidth && $originalHeight <= $maxHeight) {
        error_log("resizeImage: No resize needed - {$originalWidth}x{$originalHeight} <= {$maxWidth}x{$maxHeight}");
        return [
            'resized' => false,
            'original' => ['width' => $originalWidth, 'height' => $originalHeight, 'size' => $originalSize],
            'final' => ['width' => $originalWidth, 'height' => $originalHeight, 'size' => $originalSize]
        ];
    }

    // アスペクト比を保持してリサイズ
    $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
    $newWidth = (int)($originalWidth * $ratio);
    $newHeight = (int)($originalHeight * $ratio);

    error_log("resizeImage: Resizing {$originalWidth}x{$originalHeight} -> {$newWidth}x{$newHeight}");

    // 画像リソース作成
    $source = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($filePath);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($filePath);
            break;
        case 'image/gif':
            $source = @imagecreatefromgif($filePath);
            break;
        case 'image/webp':
            $source = @imagecreatefromwebp($filePath);
            break;
        default:
            error_log("resizeImage: Unsupported mime type - $mimeType");
            return false;
    }

    if ($source === false || $source === null) {
        error_log("resizeImage: Failed to create image from source - $filePath");
        return false;
    }

    // 新しい画像リソース作成
    $destination = imagecreatetruecolor($newWidth, $newHeight);

    if ($destination === false) {
        imagedestroy($source);
        error_log("resizeImage: Failed to create destination image");
        return false;
    }

    // PNG/GIF/WebPの透明度対応
    if ($mimeType === 'image/png' || $mimeType === 'image/gif' || $mimeType === 'image/webp') {
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
        $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
        imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);
    } else {
        // JPEGの場合は白い背景を設定
        $white = imagecolorallocate($destination, 255, 255, 255);
        imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $white);
    }

    // リサイズ（高品質リサンプリング）
    imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

    // 保存
    $result = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $result = imagejpeg($destination, $filePath, $quality);
            break;
        case 'image/png':
            // PNG compression level (0-9, 9 is max compression)
            $pngQuality = (int)((100 - $quality) / 11.11);
            $result = imagepng($destination, $filePath, min(9, max(0, $pngQuality)));
            break;
        case 'image/gif':
            $result = imagegif($destination, $filePath);
            break;
        case 'image/webp':
            $result = imagewebp($destination, $filePath, $quality);
            break;
    }

    imagedestroy($source);
    imagedestroy($destination);

    if (!$result) {
        error_log("resizeImage: Failed to save resized image - $filePath");
        return false;
    }

    $finalSize = filesize($filePath);
    $compression = $originalSize > 0 ? round((1 - $finalSize / $originalSize) * 100, 1) : 0;

    error_log("resizeImage: Success - {$originalWidth}x{$originalHeight} -> {$newWidth}x{$newHeight}, " . 
              round($originalSize/1024, 2) . "KB -> " . round($finalSize/1024, 2) . "KB ({$compression}% reduced)");

    return [
        'resized' => true,
        'original' => ['width' => $originalWidth, 'height' => $originalHeight, 'size' => $originalSize],
        'final' => ['width' => $newWidth, 'height' => $newHeight, 'size' => $finalSize],
        'compression' => $compression
    ];
}

/**
 * ファイルアップロード
 * 
 * @param array $file $_FILES array element
 * @param string $subDirectory Subdirectory (e.g., 'logo/', 'photo/', 'free/')
 * @return array Success/failure with file info
 */
function uploadFile($file, $subDirectory = '') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        error_log("uploadFile: File not uploaded - tmp_name: " . ($file['tmp_name'] ?? 'not set'));
        return ['success' => false, 'message' => 'ファイルがアップロードされていません'];
    }

    // ファイルサイズチェック（リサイズ前の上限）
    if ($file['size'] > MAX_FILE_SIZE) {
        error_log("uploadFile: File too large - size: " . $file['size'] . ", max: " . MAX_FILE_SIZE);
        return ['success' => false, 'message' => 'ファイルサイズが大きすぎます（最大: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB）'];
    }

    // ファイルタイプチェック
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        error_log("uploadFile: Invalid file type - mime: $mimeType, allowed: " . implode(', ', ALLOWED_IMAGE_TYPES));
        return ['success' => false, 'message' => '許可されていないファイルタイプです: ' . $mimeType];
    }

    // ディレクトリ作成
    $uploadDir = UPLOAD_DIR . $subDirectory;
    if (!is_dir($uploadDir)) {
        error_log("uploadFile: Creating directory: $uploadDir");
        if (!mkdir($uploadDir, 0755, true)) {
            error_log("uploadFile: Failed to create directory: $uploadDir");
            return ['success' => false, 'message' => 'アップロードディレクトリの作成に失敗しました'];
        }
    }

    // ファイル名生成
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileName = uniqid() . '_' . time() . '.' . $extension;
    $filePath = $uploadDir . $fileName;

    // 元のファイル情報を取得
    $originalSize = $file['size'];
    $originalInfo = @getimagesize($file['tmp_name']);
    $originalWidth = $originalInfo ? $originalInfo[0] : 0;
    $originalHeight = $originalInfo ? $originalInfo[1] : 0;

    error_log("uploadFile: Moving file to: $filePath (Original: {$originalWidth}x{$originalHeight}, " . round($originalSize / 1024, 2) . "KB)");

    // ファイル移動
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        error_log("uploadFile: Failed to move file from " . $file['tmp_name'] . " to $filePath");
        return ['success' => false, 'message' => 'ファイルの移動に失敗しました'];
    }

    // 画像リサイズ（有効な場合）
    $resizeInfo = null;
    if (defined('IMAGE_RESIZE_ENABLED') && IMAGE_RESIZE_ENABLED) {
        try {
            $resizeInfo = resizeImageWithType($filePath, $subDirectory);
        } catch (Exception $e) {
            error_log("uploadFile: Resize failed - " . $e->getMessage());
            $resizeInfo = null;
        }
    }

    // リサイズ後のファイル情報
    $finalSize = filesize($filePath);
    $finalInfo = @getimagesize($filePath);
    $finalWidth = $finalInfo ? $finalInfo[0] : $originalWidth;
    $finalHeight = $finalInfo ? $finalInfo[1] : $originalHeight;

    $relativePath = 'backend/uploads/' . $subDirectory . $fileName;

    error_log("uploadFile: Success - Final: {$finalWidth}x{$finalHeight}, " . round($finalSize / 1024, 2) . "KB");
    
    return [
        'success' => true,
        'file_path' => $relativePath,
        'file_name' => $fileName,
        'mime_type' => $mimeType,
        'original_size' => $originalSize,
        'final_size' => $finalSize,
        'original_dimensions' => ['width' => $originalWidth, 'height' => $originalHeight],
        'final_dimensions' => ['width' => $finalWidth, 'height' => $finalHeight],
        'was_resized' => $resizeInfo !== null && $resizeInfo !== false
    ];
}

/**
 * アップロードタイプに応じて画像をリサイズ
 * 
 * @param string $filePath File path
 * @param string $subDirectory Upload type directory (e.g., 'logo/', 'photo/')
 * @return bool|array Resize result
 */
function resizeImageWithType($filePath, $subDirectory = '') {
    try {
        // サブディレクトリからタイプを判定
        $type = trim($subDirectory, '/');
        
        // 設定から適切なサイズを取得
        $defaultSizes = [
            'logo' => ['maxWidth' => 400, 'maxHeight' => 400],
            'photo' => ['maxWidth' => 800, 'maxHeight' => 800],
            'free' => ['maxWidth' => 1200, 'maxHeight' => 1200],
            'default' => ['maxWidth' => 1024, 'maxHeight' => 1024]
        ];
        
        $sizes = defined('IMAGE_SIZES') ? IMAGE_SIZES : $defaultSizes;
        
        $sizeConfig = isset($sizes[$type]) ? $sizes[$type] : (isset($sizes['default']) ? $sizes['default'] : $defaultSizes['default']);
        $quality = defined('IMAGE_QUALITY') ? IMAGE_QUALITY : 85;
        
        $maxWidth = isset($sizeConfig['maxWidth']) ? $sizeConfig['maxWidth'] : 1024;
        $maxHeight = isset($sizeConfig['maxHeight']) ? $sizeConfig['maxHeight'] : 1024;
        
        error_log("resizeImageWithType: Type=$type, MaxSize={$maxWidth}x{$maxHeight}, Quality=$quality");
        
        return resizeImage($filePath, $maxWidth, $maxHeight, $quality);
    } catch (Exception $e) {
        error_log("resizeImageWithType Error: " . $e->getMessage());
        return false;
    }
}

/**
 * 郵便番号から住所を取得（郵便番号データベースまたはAPI使用）
 */
function getAddressFromPostalCode($postalCode) {
    // ハイフン除去
    $postalCode = str_replace('-', '', $postalCode);
    
    // ここで郵便番号APIを呼び出すか、データベースから取得
    // 例: Yahoo APIや郵便番号データベースを使用
    // 簡易版として、今後実装が必要
    return null;
}

/**
 * URLスラッグ生成
 */
function generateUrlSlug($length = 6) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $slug = '';
    
    for ($i = 0; $i < $length; $i++) {
        $slug .= $characters[random_int(0, strlen($characters) - 1)];
    }
    
    return $slug;
}

/**
 * サニタイズ
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    if ($data === null || $data === '') {
        return null;
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * メール送信
 */
/**
 * Detect email type (business vs personal)
 */
function detectEmailType($email) {
    $domain = strtolower(substr(strrchr($email, "@"), 1));
    
    // Common personal email providers
    $personalDomains = [
        'gmail.com', 'yahoo.co.jp', 'yahoo.com', 'hotmail.com', 'outlook.com',
        'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com',
        'ymail.com', 'rocketmail.com', 'mail.com', 'protonmail.com', 'zoho.com'
    ];
    
    if (in_array($domain, $personalDomains)) {
        return 'personal';
    }
    
    // Business emails are typically custom domains
    return 'business';
}

/**
 * Log email sending with timing information
 */
function logEmail($recipientEmail, $subject, $emailType, $status, $deliveryTimeMs, $smtpResponse = null, $errorMessage = null, $userId = null, $relatedId = null) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        
        $recipientType = detectEmailType($recipientEmail);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $sentAt = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
        $completedAt = date('Y-m-d H:i:s');
        
        $stmt = $db->prepare("
            INSERT INTO email_logs 
            (recipient_email, recipient_type, subject, email_type, status, sent_at, started_at, completed_at, 
             delivery_time_ms, smtp_response, error_message, user_id, related_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $recipientEmail,
            $recipientType,
            $subject,
            $emailType,
            $status,
            $sentAt,
            $completedAt,
            $deliveryTimeMs,
            $smtpResponse,
            $errorMessage,
            $userId,
            $relatedId,
            $ipAddress
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("[Email Log Error] Failed to log email: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email with logging and timing
 */
function sendEmail($to, $subject, $htmlMessage, $textMessage = '', $emailType = 'general', $userId = null, $relatedId = null) {
    $mail = new PHPMailer(true);
    $startTime = microtime(true);
    $logId = null;
    $status = 'pending';
    $errorMessage = null;
    $smtpResponse = null;

    try {
        // SMTP 設定
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ctha43843@gmail.com'; // あなたのGmail
        $mail->Password = 'lsdimxhugzdlhxla'; // Gmailアプリパスワード
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Enable verbose debug output (only for logging, not for user)
        $mail->SMTPDebug = 0; // 0 = off, 2 = client and server messages
        $mail->Debugoutput = function($str, $level) use (&$smtpResponse) {
            if ($smtpResponse === null) {
                $smtpResponse = '';
            }
            $smtpResponse .= $str . "\n";
        };

        // 送信者情報
        $mail->setFrom('ctha43843@gmail.com', '不動産AI名刺');
        $mail->addReplyTo('ctha43843@gmail.com');

        // 宛先
        $mail->addAddress($to);

        // メール内容
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'quoted-printable';
        $mail->Subject = $subject;
        $mail->Body    = $htmlMessage;
        $mail->AltBody = $textMessage ?: strip_tags($htmlMessage);

        // Send email
        $result = $mail->send();
        
        // Calculate delivery time
        $endTime = microtime(true);
        $deliveryTimeMs = round(($endTime - $startTime) * 1000, 2);
        
        $status = $result ? 'sent' : 'failed';
        
        // Log success
        logEmail($to, $subject, $emailType, $status, $deliveryTimeMs, 
                 substr($smtpResponse, 0, 500), null, $userId, $relatedId);
        
        if ($result) {
            error_log("[Email Success] Sent to {$to} in {$deliveryTimeMs}ms - Type: " . detectEmailType($to));
        }

        return $result;

    } catch (Exception $e) {
        $endTime = microtime(true);
        $deliveryTimeMs = round(($endTime - $startTime) * 1000, 2);
        $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
        $status = 'failed';
        
        // Log failure
        logEmail($to, $subject, $emailType, $status, $deliveryTimeMs, 
                 substr($smtpResponse, 0, 500), $errorMessage, $userId, $relatedId);
        
        error_log("[Email Error] Failed to send to {$to}: {$errorMessage} - Time: {$deliveryTimeMs}ms");
        return false;
    }
}

/**
 * 管理者に新規登録通知メールを送信
 */
function sendAdminNotificationEmail($userEmail, $userType, $userId, $urlSlug) {
    if (!defined('NOTIFICATION_EMAIL') || empty(NOTIFICATION_EMAIL)) {
        error_log("NOTIFICATION_EMAIL is not defined");
        return false;
    }

    $adminEmail = 'nishio@rchukai.jp';
    
    // ユーザータイプの日本語表示
    $userTypeLabels = [
        'new' => '新規ユーザー',
        'existing' => '既存ユーザー',
        'free' => '無料ユーザー'
    ];
    $userTypeLabel = $userTypeLabels[$userType] ?? $userType;
    
    $registrationDate = date('Y年m月d日 H:i:s');
    
    // メール件名
    $emailSubject = '【不動産AI名刺】新規ユーザー登録通知';
    
    // HTML本文
    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0066cc; color: #fff; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #fff; }
            .info-table th { background: #e9ecef; padding: 12px; text-align: left; border: 1px solid #dee2e6; font-weight: bold; width: 30%; }
            .info-table td { padding: 12px; border: 1px solid #dee2e6; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>不動産AI名刺</h1>
            </div>
            <div class='content'>
                <p>新規ユーザーが登録されました。</p>
                <table class='info-table'>
                    <tr>
                        <th>ユーザーID</th>
                        <td>{$userId}</td>
                    </tr>
                    <tr>
                        <th>メールアドレス</th>
                        <td>{$userEmail}</td>
                    </tr>
                    <tr>
                        <th>ユーザータイプ</th>
                        <td>{$userTypeLabel}</td>
                    </tr>
                    <tr>
                        <th>URLスラッグ</th>
                        <td>{$urlSlug}</td>
                    </tr>
                    <tr>
                        <th>登録日時</th>
                        <td>{$registrationDate}</td>
                    </tr>
                </table>
                <div class='footer'>
                    <p>このメールは自動送信されています。返信はできません。</p>
                    <p>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // プレーンテキスト版
    $emailBodyText = 
        "新規ユーザーが登録されました。\n\n" .
        "ユーザーID: {$userId}\n" .
        "メールアドレス: {$userEmail}\n" .
        "ユーザータイプ: {$userTypeLabel}\n" .
        "URLスラッグ: {$urlSlug}\n" .
        "登録日時: {$registrationDate}\n";
    
    return sendEmail($adminEmail, $emailSubject, $emailBody, $emailBodyText, 'admin_notification', null, $userId);
}

/**
 * バリデーション: メールアドレス
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * バリデーション: 電話番号（日本形式）
 */
function validatePhoneNumber($phone) {
    // ハイフンやスペースを除去
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    // 10桁または11桁の数字
    return preg_match('/^0\d{9,10}$/', $phone);
}

/**
 * バリデーション: 郵便番号（日本形式）
 */
function validatePostalCode($postalCode) {
    $postalCode = str_replace('-', '', $postalCode);
    return preg_match('/^\d{7}$/', $postalCode);
}

/**
 * セッション開始
 */
function startSessionIfNotStarted() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}


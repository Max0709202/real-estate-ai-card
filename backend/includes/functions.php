<?php
/**
 * Send an email using PHPMailer + SMTP (Xserver / business mail friendly).
 *
 * Requirements:
 * - Define env vars (recommended):
 *   SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME, SMTP_REPLY_TO
 *
 * Expected helper functions (you already referenced them):
 * - logEmail($to, $subject, $emailType, $status, $deliveryTimeMs, $smtpResponseSafe, $errorMessage, $userId, $relatedId)
 * - queueEmailForLater(...)
 *
 **/
/**
 * Common Utility Functions
 */

// Load Composer autoloader for PHPMailer and other dependencies
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',   // backend/vendor (composer run from backend/)
    __DIR__ . '/../../vendor/autoload.php' // project root vendor
];
$autoloadLoaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloadLoaded = true;
        break;
    }
}
if (!$autoloadLoaded) {
    throw new RuntimeException('Composer autoload not found. Run: composer install in backend/');
}

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
 * 管理者変更履歴を記録
 *
 * @param PDO $db Database connection
 * @param int $adminId Admin ID who made the change
 * @param string $adminEmail Admin email who made the change
 * @param string $changeType Type of change (payment_confirmed, qr_code_issued, published_changed, user_deleted, other)
 * @param string $targetType Type of target (user, business_card, payment, other)
 * @param int|null $targetId Target ID
 * @param string|null $description Description of the change
 * @return bool Success status
 */
/**
 * Force close business card (is_published=0) when payment_status is not allowed for OPEN
 * This ensures data consistency when payment_status changes to disallowed state
 * 
 * @param PDO $db Database connection
 * @param int $businessCardId Business card ID
 * @param string $paymentStatus New payment status
 * @return bool True if card was forced closed, false otherwise
 */
function enforceOpenPaymentStatusRule($db, $businessCardId, $paymentStatus) {
    // ST (Stripe bank transfer) and CR (credit card) allow OPEN, same as BANK_PAID
    $canOpen = in_array($paymentStatus, ['CR', 'BANK_PAID', 'ST']);
    
    if (!$canOpen) {
        // Check if card is currently open
        $stmt = $db->prepare("SELECT is_published FROM business_cards WHERE id = ?");
        $stmt->execute([$businessCardId]);
        $card = $stmt->fetch();
        
        if ($card && $card['is_published'] == 1) {
            // Force close the card
            $stmt = $db->prepare("UPDATE business_cards SET is_published = 0 WHERE id = ?");
            $stmt->execute([$businessCardId]);
            
            error_log("Payment status rule enforced: Business card ID {$businessCardId} forced to closed (is_published=0) due to payment_status={$paymentStatus}");
            return true;
        }
    }
    
    return false;
}

function logAdminChange($db, $adminId, $adminEmail, $changeType, $targetType, $targetId = null, $description = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO admin_change_logs (admin_id, admin_email, change_type, target_type, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $adminId,
            $adminEmail,
            $changeType,
            $targetType,
            $targetId,
            $description
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log admin change: " . $e->getMessage());
        return false;
    }
}

/**
 * 画像のEXIF向き情報を正規化
 *
 * スマートフォンで撮影した画像のEXIF向き情報を読み取り、
 * 画像を物理的に回転させて正しい向きにし、EXIFメタデータを削除します。
 *
 * @param string $filePath 画像ファイルパス（上書きされます）
 * @param string $mimeType MIMEタイプ（'image/jpeg', 'image/png'など）
 * @return bool 成功時true、失敗時false
 */
function normalizeImageOrientation($filePath, $mimeType) {
    if (!file_exists($filePath)) {
        error_log("normalizeImageOrientation: File not found - $filePath");
        return false;
    }

    // JPEG以外はEXIF向き情報がないためスキップ（PNG/GIF/WebPは通常向き情報なし）
    if ($mimeType !== 'image/jpeg') {
        error_log("normalizeImageOrientation: Skipping non-JPEG file - $mimeType");
        return true; // エラーではなく、処理不要として成功を返す
    }

    // EXIF拡張機能の確認
    if (!function_exists('exif_read_data')) {
        error_log("normalizeImageOrientation: EXIF extension not available, skipping normalization");
        return true; // EXIF拡張がない場合はスキップ（エラーではない）
    }

    // EXIF向き情報の読み取り
    $exif = @exif_read_data($filePath);
    if ($exif === false || !isset($exif['Orientation'])) {
        error_log("normalizeImageOrientation: No EXIF orientation data found or already normalized");
        return true; // 向き情報がない場合は既に正規化済みとみなす
    }

    $orientation = (int)$exif['Orientation'];

    // 向き1（TopLeft）の場合は処理不要
    if ($orientation === 1) {
        error_log("normalizeImageOrientation: Orientation is already TopLeft (1), no rotation needed");
        return true;
    }

    error_log("normalizeImageOrientation: Detected orientation $orientation, normalizing...");

    // Imagickが利用可能な場合はImagickを使用
    if (class_exists('Imagick')) {
        return normalizeImageOrientationImagick($filePath, $orientation);
    }

    // GDフォールバック
    return normalizeImageOrientationGD($filePath, $orientation);
}

/**
 * Imagickを使用した画像向き正規化
 *
 * @param string $filePath 画像ファイルパス
 * @param int $orientation EXIF向き値（1-8）
 * @return bool 成功時true、失敗時false
 */
function normalizeImageOrientationImagick($filePath, $orientation) {
    try {
        $img = new Imagick($filePath);

        // 向きに応じて回転・反転
        switch ($orientation) {
            case 2: // TopRight - 水平反転
                $img->flopImage();
                break;
            case 3: // BottomRight - 180度回転
                $img->rotateImage(new ImagickPixel('#00000000'), 180);
                break;
            case 4: // BottomLeft - 垂直反転
                $img->flipImage();
                break;
            case 5: // LeftTop - 90度CCW回転 + 水平反転
                $img->rotateImage(new ImagickPixel('#00000000'), 90);
                $img->flopImage();
                break;
            case 6: // RightTop - 90度CW回転
                $img->rotateImage(new ImagickPixel('#00000000'), -90);
                break;
            case 7: // RightBottom - 90度CW回転 + 水平反転
                $img->rotateImage(new ImagickPixel('#00000000'), -90);
                $img->flopImage();
                break;
            case 8: // LeftBottom - 90度CCW回転
                $img->rotateImage(new ImagickPixel('#00000000'), 90);
                break;
        }

        // 向きをTopLeftに設定
        $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

        // EXIFメタデータを削除
        $img->stripImage();

        // ファイルを上書き保存
        $img->writeImage($filePath);
        $img->clear();
        $img->destroy();

        error_log("normalizeImageOrientationImagick: Successfully normalized orientation $orientation");
        return true;

    } catch (Exception $e) {
        error_log("normalizeImageOrientationImagick: Failed - " . $e->getMessage());
        return false;
    }
}

/**
 * GDを使用した画像向き正規化
 *
 * @param string $filePath 画像ファイルパス
 * @param int $orientation EXIF向き値（1-8）
 * @return bool 成功時true、失敗時false
 */
function normalizeImageOrientationGD($filePath, $orientation) {
    // 画像を読み込み
    $source = @imagecreatefromjpeg($filePath);
    if ($source === false) {
        error_log("normalizeImageOrientationGD: Failed to create image from JPEG");
        return false;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $destination = null;
    $rotated = false;
    $flipped = false;

    // 向きに応じて回転・反転
    switch ($orientation) {
        case 2: // TopRight - 水平反転
            $destination = imagecreatetruecolor($width, $height);
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            for ($x = 0; $x < $width; $x++) {
                imagecopy($destination, $source, $width - $x - 1, 0, $x, 0, 1, $height);
            }
            $flipped = true;
            break;

        case 3: // BottomRight - 180度回転
            $destination = imagerotate($source, 180, 0);
            $rotated = true;
            break;

        case 4: // BottomLeft - 垂直反転
            $destination = imagecreatetruecolor($width, $height);
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            for ($y = 0; $y < $height; $y++) {
                imagecopy($destination, $source, 0, $height - $y - 1, 0, $y, $width, 1);
            }
            $flipped = true;
            break;

        case 5: // LeftTop - 90度CCW回転 + 水平反転
            $destination = imagerotate($source, 90, 0);
            $tempWidth = imagesx($destination);
            $tempHeight = imagesy($destination);
            $flippedDest = imagecreatetruecolor($tempWidth, $tempHeight);
            imagealphablending($flippedDest, false);
            imagesavealpha($flippedDest, true);
            for ($x = 0; $x < $tempWidth; $x++) {
                imagecopy($flippedDest, $destination, $tempWidth - $x - 1, 0, $x, 0, 1, $tempHeight);
            }
            imagedestroy($destination);
            $destination = $flippedDest;
            $rotated = true;
            $flipped = true;
            break;

        case 6: // RightTop - 90度CW回転
            $destination = imagerotate($source, -90, 0);
            $rotated = true;
            break;

        case 7: // RightBottom - 90度CW回転 + 水平反転
            $destination = imagerotate($source, -90, 0);
            $tempWidth = imagesx($destination);
            $tempHeight = imagesy($destination);
            $flippedDest = imagecreatetruecolor($tempWidth, $tempHeight);
            imagealphablending($flippedDest, false);
            imagesavealpha($flippedDest, true);
            for ($x = 0; $x < $tempWidth; $x++) {
                imagecopy($flippedDest, $destination, $tempWidth - $x - 1, 0, $x, 0, 1, $tempHeight);
            }
            imagedestroy($destination);
            $destination = $flippedDest;
            $rotated = true;
            $flipped = true;
            break;

        case 8: // LeftBottom - 90度CCW回転
            $destination = imagerotate($source, 90, 0);
            $rotated = true;
            break;

        default:
            imagedestroy($source);
            error_log("normalizeImageOrientationGD: Unknown orientation $orientation");
            return false;
    }

    if ($destination === null) {
        imagedestroy($source);
        error_log("normalizeImageOrientationGD: Failed to create destination image");
        return false;
    }

    // 元の画像を破棄
    imagedestroy($source);

    // JPEGとして保存（品質85、EXIFメタデータは自動的に削除される）
    $result = imagejpeg($destination, $filePath, 85);

    imagedestroy($destination);

    if (!$result) {
        error_log("normalizeImageOrientationGD: Failed to save normalized image");
        return false;
    }

    error_log("normalizeImageOrientationGD: Successfully normalized orientation $orientation");
    return true;
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

    // EXIF向き情報の正規化（リサイズ前に実行）
    // これにより、スマートフォンで撮影した画像が正しい向きで保存されます
    $orientationNormalized = false;
    try {
        $orientationNormalized = normalizeImageOrientation($filePath, $mimeType);
        if ($orientationNormalized) {
            error_log("uploadFile: Image orientation normalized successfully");
            // 正規化後の画像情報を再取得（向きが変わった可能性があるため）
            $normalizedInfo = @getimagesize($filePath);
            if ($normalizedInfo) {
                $originalWidth = $normalizedInfo[0];
                $originalHeight = $normalizedInfo[1];
                error_log("uploadFile: After normalization - {$originalWidth}x{$originalHeight}");
            }
        } else {
            error_log("uploadFile: Image orientation normalization failed or skipped (non-JPEG or no EXIF)");
        }
    } catch (Exception $e) {
        error_log("uploadFile: Orientation normalization error - " . $e->getMessage());
        // 正規化エラーは致命的ではないため、処理を続行
    }

    // 画像リサイズ（有効な場合、正規化後に実行）
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

function sendEmail(
    string $to,
    string $subject,
    string $htmlMessage,
    string $textMessage = '',
    string $emailType = 'general',
    ?int $userId = null,
    $relatedId = null
): bool {
    // Basic email validation (prevents obvious errors)
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("[Email Error] Invalid recipient email: {$to}");
        return false;
    }

    $mail = new PHPMailer(true);
    $startTime = microtime(true);
    $smtpResponse = null;

    try {
        // ---------- SMTP CONFIG ----------
        $mail->isSMTP();
        $mail->SMTPAuth = true;

        // Environment variables (fallbacks for Xserver)
        $smtpHost = getenv('SMTP_HOST') ?: 'sv16576.xserver.jp';
        $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
        $smtpUser = getenv('SMTP_USERNAME') ?: 'no-reply@ai-fcard.com';
        $smtpPass = getenv('SMTP_PASSWORD') ?: 'Renewal4329';
        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'no-reply@ai-fcard.com';
        $fromName  = getenv('SMTP_FROM_NAME')  ?: '不動産AI名刺';

        // Reply-To should usually be a real inbox (not no-reply)
        $replyToEmail = getenv('SMTP_REPLY_TO') ?: 'no-reply@ai-fcard.com';
        $replyToName  = getenv('SMTP_REPLY_TO_NAME') ?: $fromName;

        $mail->Host = $smtpHost;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port = $smtpPort;

        // Encryption based on port
        if ($smtpPort === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587 recommended
        }

        // Prevent long hangs
        $mail->Timeout = (int)(getenv('SMTP_TIMEOUT') ?: 20);
        $mail->SMTPKeepAlive = false;

        // TLS options
        // Xserver's certificate chain can fail strict verification from some PHP/OpenSSL builds.
        // Relax verification here so SMTP can connect reliably from the app server.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Debug (keep OFF in production)
        $mail->SMTPDebug = (int)(getenv('SMTP_DEBUG') ?: 0);
        $mail->Debugoutput = function ($str, $level) use (&$smtpResponse) {
            if ($smtpResponse === null) {
                $smtpResponse = '';
            }
            $smtpResponse .= $str . "\n";
        };

        // ---------- HEADERS ----------
        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($replyToEmail, $replyToName);

        // ---------- RECIPIENT ----------
        $mail->addAddress($to);

        // ---------- CONTENT ----------
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'quoted-printable';

        // Subject + body
        $mail->Subject = $subject;
        $mail->Body    = $htmlMessage;
        $mail->AltBody = $textMessage !== '' ? $textMessage : trim(strip_tags($htmlMessage));

        // Optional: set a message-id domain for better deliverability
        // $mail->MessageID = sprintf('<%s@%s>', bin2hex(random_bytes(16)), 'ai-fcard.com');

        // ---------- SEND ----------
        $result = $mail->send();

        $deliveryTimeMs = round((microtime(true) - $startTime) * 1000, 2);
        $status = $result ? 'sent' : 'failed';

        // Safely truncate debug output
        $smtpResponseSafe = ($smtpResponse !== null) ? mb_substr($smtpResponse, 0, 500) : null;

        // Log
        if (function_exists('logEmail')) {
            logEmail(
                $to,
                $subject,
                $emailType,
                $status,
                $deliveryTimeMs,
                $smtpResponseSafe,
                null,
                $userId,
                $relatedId
            );
        }

        if ($result) {
            error_log("[Email Success] Sent to {$to} in {$deliveryTimeMs}ms - Type: {$emailType}");
        } else {
            error_log("[Email Error] Failed to send to {$to} (unknown reason) - Time: {$deliveryTimeMs}ms");
        }

        return $result;

    } catch (Exception $e) {
        $deliveryTimeMs = round((microtime(true) - $startTime) * 1000, 2);
        $errorMessage = $mail->ErrorInfo ?: $e->getMessage();

        $smtpResponseSafe = ($smtpResponse !== null) ? mb_substr($smtpResponse, 0, 500) : null;

        // Log failure
        if (function_exists('logEmail')) {
            logEmail(
                $to,
                $subject,
                $emailType,
                'failed',
                $deliveryTimeMs,
                $smtpResponseSafe,
                $errorMessage,
                $userId,
                $relatedId
            );
        }

        // Optional: queue for later (generic retry)
        // Only queue for temporary errors, not permanent address errors
        $temporaryError = false;
        $msg = strtolower($errorMessage);

        // Typical temporary SMTP/network errors
        if (strpos($msg, 'timed out') !== false ||
            strpos($msg, 'connection failed') !== false ||
            strpos($msg, 'could not connect') !== false ||
            strpos($msg, 'try again later') !== false ||
            strpos($msg, '4.') !== false // many SMTP temp errors include 4.x.x
        ) {
            $temporaryError = true;
        }

        if ($temporaryError && function_exists('queueEmailForLater')) {
            queueEmailForLater($to, $subject, $htmlMessage, $textMessage, $emailType, $userId, $relatedId);
            error_log("[Email Queue] Temporary failure. Queued email to {$to}");
        }

        error_log("[Email Error] Failed to send to {$to}: {$errorMessage} - Time: {$deliveryTimeMs}ms");
        return false;
    }
}


/**
 * Queue email for later sending (when Gmail limit is exceeded)
 */
function queueEmailForLater($to, $subject, $htmlMessage, $textMessage, $emailType, $userId = null, $relatedId = null) {
    try {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();

        // Check if email_queue table exists, if not, just log the attempt
        $stmt = $db->prepare("
            INSERT INTO email_queue (recipient_email, subject, html_body, text_body, email_type, user_id, related_id, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");

        $stmt->execute([
            $to,
            $subject,
            $htmlMessage,
            $textMessage,
            $emailType,
            $userId,
            $relatedId
        ]);

        error_log("[Email Queue] Email queued for later sending to {$to}");
        return true;
    } catch (Exception $e) {
        // If table doesn't exist, just log the error
        error_log("[Email Queue Error] Failed to queue email: " . $e->getMessage());
        return false;
    }
}

/**
 * 管理者にサブスクリプションキャンセル通知メールを送信
 *
 * @param string $userEmail User email address
 * @param int $userId User ID
 * @param int $subscriptionId Subscription ID
 * @param int $businessCardId Business card ID
 * @param string $urlSlug Business card URL slug
 * @param bool $cancelImmediately Whether cancellation was immediate or at period end
 * @param bool $isAdminInitiated Whether cancellation was initiated by admin
 * @return bool Success status
 */
function sendAdminCancellationEmail($userEmail, $userId, $subscriptionId, $businessCardId, $urlSlug, $cancelImmediately, $isAdminInitiated = false) {
    $adminEmail = 'nishio@rchukai.jp';

    $cancellationType = $cancelImmediately ? '即座にキャンセル' : '期間終了時にキャンセル';
    $initiatedBy = $isAdminInitiated ? '管理者' : 'ユーザー';
    $cancellationDate = date('Y年m月d日 H:i:s');
    $cardFullUrl = defined('QR_CODE_BASE_URL') ? rtrim(QR_CODE_BASE_URL, '/') . '/card.php?slug=' . $urlSlug : '';

    $emailSubject = '【不動産AI名刺】サブスクリプションキャンセル通知（' . $cancellationType . '）';

    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto;}
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { background: #ffffff; padding: 15px; display: inline-block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #fff; }
            .info-table th { background: #e9ecef; padding: 12px; text-align: left; border: 1px solid #dee2e6; font-weight: bold; width: 30%; }
            .info-table td { padding: 12px; border: 1px solid #dee2e6; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            .highlight { background: #fff3cd; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 100px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>サブスクリプションがキャンセルされました。（{$initiatedBy}による操作）</p>
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
                        <th>サブスクリプションID</th>
                        <td>{$subscriptionId}</td>
                    </tr>
                    <tr>
                        <th>ビジネスカードID</th>
                        <td>{$businessCardId}</td>
                    </tr>
                    <tr>
                        <th>URLスラッグ</th>
                        <td><span class='highlight'>{$urlSlug}</span></td>
                    </tr>
                    <tr>
                        <th>キャンセル種別</th>
                        <td>{$cancellationType}</td>
                    </tr>
                    <tr>
                        <th>操作者</th>
                        <td>{$initiatedBy}</td>
                    </tr>
                    <tr>
                        <th>キャンセル日時</th>
                        <td>{$cancellationDate}</td>
                    </tr>" .
                    ($cardFullUrl ? "
                    <tr>
                        <th>名刺URL</th>
                        <td><a href='{$cardFullUrl}' target='_blank'>{$cardFullUrl}</a></td>
                    </tr>" : "") . "
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

    $emailBodyText =
        "サブスクリプションがキャンセルされました。（{$initiatedBy}による操作）\n\n" .
        "ユーザーID: {$userId}\n" .
        "メールアドレス: {$userEmail}\n" .
        "サブスクリプションID: {$subscriptionId}\n" .
        "ビジネスカードID: {$businessCardId}\n" .
        "URLスラッグ: {$urlSlug}\n" .
        "キャンセル種別: {$cancellationType}\n" .
        "操作者: {$initiatedBy}\n" .
        "キャンセル日時: {$cancellationDate}\n";

    return sendEmail($adminEmail, $emailSubject, $emailBody, $emailBodyText, 'admin_cancellation_notification', null, $subscriptionId);
}

/**
 * ユーザーにサブスクリプションキャンセル確認メールを送信
 *
 * @param string $userEmail User email address
 * @param bool $cancelImmediately Whether cancellation was immediate or at period end
 * @return bool Success status
 */
function sendUserCancellationConfirmationEmail($userEmail, $cancelImmediately) {
    if (empty($userEmail)) {
        error_log("sendUserCancellationConfirmationEmail: User email is empty");
        return false;
    }

    $cancellationDate = date('Y年m月d日 H:i:s');

    if ($cancelImmediately) {
        $emailSubject = '【不動産AI名刺】サブスクリプションのキャンセルが完了しました';
        $mainMessage = 'サブスクリプションを即座にキャンセルいたしました。';
        $detailMessage = 'デジタル名刺の公開は停止されました。再度ご利用いただく場合は、新規登録が必要となります。';
    } else {
        $emailSubject = '【不動産AI名刺】サブスクリプションの期間終了時キャンセルを設定しました';
        $mainMessage = 'サブスクリプションを期間終了時にキャンセルするよう設定いたしました。';
        $detailMessage = '現在の課金期間の終了日まで、デジタル名刺は引き続きご利用いただけます。期間終了後、自動的に公開が停止されます。';
    }

    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto;}
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { background: #ffffff; padding: 15px; display: inline-block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .info-box { background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 100px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>いつも不動産AI名刺をご利用いただき、ありがとうございます。</p>
                <p>{$mainMessage}</p>
                <div class='info-box'>
                    <p><strong>{$detailMessage}</strong></p>
                </div>
                <p>キャンセル日時: {$cancellationDate}</p>
                <div class='footer'>
                    <p>ご不明な点がございましたら、お気軽にお問い合わせください。</p>
                    <p>このメールは自動送信されています。</p>
                    <p>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    $emailBodyText =
        "いつも不動産AI名刺をご利用いただき、ありがとうございます。\n\n" .
        "{$mainMessage}\n\n" .
        "{$detailMessage}\n\n" .
        "キャンセル日時: {$cancellationDate}\n\n" .
        "ご不明な点がございましたら、お気軽にお問い合わせください。\n";

    return sendEmail($userEmail, $emailSubject, $emailBody, $emailBodyText, 'user_cancellation_confirmation', null, null);
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
    ];
    $userTypeLabel = $userTypeLabels[$userType] ?? $userType;

    $registrationDate = date('Y年m月d日 H:i:s');

    // メール件名（ユーザータイプに応じて変更）
    $emailSubject = '【不動産AI名刺】ユーザー登録通知（' . $userTypeLabel . '）';

    // HTML本文
    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto;}
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { background: #ffffff; padding: 15px; display: inline-block; margin: 0 auto; }
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
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 100px; height: auto;'>
                </div>
            </div>
            <div class='content'>
                <p>{$userTypeLabel}が登録されました。</p>
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
        "{$userTypeLabel}が登録されました。\n\n" .
        "ユーザーID: {$userId}\n" .
        "メールアドレス: {$userEmail}\n" .
        "ユーザータイプ: {$userTypeLabel}\n" .
        "URLスラッグ: {$urlSlug}\n" .
        "登録日時: {$registrationDate}\n";

    return sendEmail($adminEmail, $emailSubject, $emailBody, $emailBodyText, 'admin_notification', null, $userId);
}

/**
 * ユーザーにQRコード発行完了メールを送信
 * @param string $userEmail ユーザーメールアドレス
 * @param string $userName ユーザー名
 * @param string $cardUrl 名刺URL
 * @param string $qrCodeUrl QRコードURL
 * @param string $urlSlug URLスラッグ
 * @param float|null $paymentAmount 支払い金額
 * @param string $userType ユーザータイプ ('new' or 'existing')
 * @param int $isEraMember ERA会員かどうか (0 or 1)
 * @param string|null $paymentType 支払い方法 ('credit_card', 'bank_transfer', etc.)
 */
function sendQRCodeIssuedEmailToUser($userEmail, $userName, $cardUrl, $qrCodeUrl, $urlSlug, $paymentAmount = null, $userType = 'new', $isEraMember = 0, $paymentType = null) {
    if (empty($userEmail)) {
        error_log("sendQRCodeIssuedEmailToUser: User email is empty");
        return false;
    }

    $issuedDate = date('Y年m月d日 H:i:s');
    $cardFullUrl = rtrim(QR_CODE_BASE_URL, '/') . '/card.php?slug=' . $urlSlug;
    
    // 既存/ERA会員で企業URLが未設定かチェック（url_slugが"user-"で始まる場合は仮URL）
    $isExistingOrEra = ($userType === 'existing' || $isEraMember);
    $isUrlSlugPending = (strpos($urlSlug, 'user-') === 0);
    $showPendingUrlNotice = $isExistingOrEra && $isUrlSlugPending;
    
    // 支払い方法の表示テキスト
    $paymentTypeText = '';
    if ($paymentType) {
        $paymentTypeLabels = [
            'credit_card' => 'クレジットカード',
            'bank_transfer' => '銀行振込',
            'stripe' => 'クレジットカード',
            'CR' => 'クレジットカード',
            'BANK_PAID' => '銀行振込',
            'ST' => 'Stripe送金'
        ];
        $paymentTypeText = $paymentTypeLabels[$paymentType] ?? $paymentType;
    }

    // メール件名
    $emailSubject = '【不動産AI名刺】デジタル名刺のQRコード発行完了';

    // HTML本文
    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid #a3a3a3; border-radius: 1%; max-width: 600px; margin: 0 auto;}
            .header { color: #000000; padding: 30px 20px; text-align: center; }
            .header .logo-container { padding: 15px; display: inline-block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .success-icon { font-size: 48px; margin-bottom: 10px; }
            .info-box { background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
            .info-box h3 { margin-top: 0; color: #667eea; }
            .button { display: inline-block; padding: 12px 30px; background: #667eea; color: #fff; text-decoration: none; border-radius: 6px; margin: 10px 0; font-weight: bold; }
            .button:hover { background: #5568d3; }
            .qr-info { background: #e8f4f8; padding: 15px; border-radius: 6px; margin: 15px 0; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            .warning-box { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .warning-box h3 { margin-top: 0; color: #856404; }
            ul { padding-left: 20px; }
            li { margin: 8px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 100px; height: auto;'>
                </div>
                <h1>QRコード発行完了</h1>
            </div>
            <div class='content'>
                <p>{$userName} 様</p>
                <p>お支払いいただき、ありがとうございます。<br>
                デジタル名刺のQRコードが正常に発行されました。</p>";
    
    // 既存/ERA会員で企業URLが未設定の場合、注意書きを追加
    if ($showPendingUrlNotice) {
        $emailBody .= "
                <div class='warning-box'>
                    <h3>⚠️ 企業URLについてのお知らせ</h3>
                    <p>現在、お客様の企業URL（名刺URL）は管理者による設定待ちの状態です。</p>
                    <p>企業URLの設定が完了次第、正式なURLでご利用いただけるようになります。</p>
                    <p>設定完了までしばらくお待ちいただくか、お急ぎの場合は管理者までお問い合わせください。</p>
                    <p><strong>お問い合わせ先:</strong> nishio@rchukai.jp</p>
                </div>";
    }

    $emailBody .= "
                <div class='info-box'>
                    <h3>📱 あなたのデジタル名刺</h3>
                    <p><strong>名刺URL:</strong><br>
                    <a href='{$cardFullUrl}' target='_blank'>{$cardFullUrl}</a></p>" .
                    ($showPendingUrlNotice ? "<p style='color: #856404; font-size: 0.9em;'>※このURLは仮URLです。正式なURLは管理者設定後に変更されます。</p>" : "") . "
                    <p>
                        <a href='{$cardFullUrl}' class='button' target='_blank'>名刺を表示する</a>
                    </p>
                </div>

                <div class='qr-info'>
                    <h3>🔲 QRコードについて</h3>
                    <p>QRコードは名刺ページに表示されています。このQRコードをスキャンすると、上記の名刺URLに直接アクセスできます。</p>
                    <ul>
                        <li>名刺ページからQRコード画像をダウンロードできます</li>
                        <li>印刷物やメールに添付して共有できます</li>
                        <li>スマートフォンでスキャンするだけでアクセス可能</li>
                    </ul>
                </div>

                <div class='info-box'>
                    <h3>📝 次のステップ</h3>
                    <ul>
                        <li>名刺の内容を確認・編集できます</li>
                        <li>QRコードを名刺に印刷して配布できます</li>
                        <li>SNSやメールで簡単に共有できます</li>
                    </ul>
                    <p>
                        <a href='" . BASE_URL . "/edit.php' class='button'>マイページで編集する</a>
                    </p>
                </div>";

    if ($paymentAmount) {
        $emailBody .= "
                <div class='info-box'>
                    <h3>💳 お支払い情報</h3>
                    <p><strong>お支払い金額:</strong> ¥" . number_format($paymentAmount) . "</p>
                    <p><strong>発行日時:</strong> {$issuedDate}</p>
                </div>";
    }

    $emailBody .= "
                <div class='footer'>
                    <p>ご不明な点がございましたら、お気軽にお問い合わせください。</p>
                    <p>このメールは自動送信されています。</p>
                    <p>© " . date('Y') . " 不動産AI名刺 All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    // プレーンテキスト版
    $pendingUrlNoticeText = '';
    if ($showPendingUrlNotice) {
        $pendingUrlNoticeText = 
            "【⚠️ 企業URLについてのお知らせ】\n" .
            "現在、お客様の企業URL（名刺URL）は管理者による設定待ちの状態です。\n" .
            "企業URLの設定が完了次第、正式なURLでご利用いただけるようになります。\n" .
            "設定完了までしばらくお待ちいただくか、お急ぎの場合は管理者までお問い合わせください。\n" .
            "お問い合わせ先: nishio@rchukai.jp\n\n";
    }
    
    $emailBodyText =
        "{$userName} 様\n\n" .
        "お支払いいただき、ありがとうございます。\n" .
        "デジタル名刺のQRコードが正常に発行されました。\n\n" .
        $pendingUrlNoticeText .
        // 名刺URLのテキスト表記も、新規ユーザー以外には表示しない
        (!$isExistingOrEra
            ? "【あなたのデジタル名刺】\n" .
              "名刺URL: {$cardFullUrl}\n" .
              ($showPendingUrlNotice ? "※このURLは仮URLです。正式なURLは管理者設定後に変更されます。\n" : "") . "\n"
            : ""
        ) .
        "【QRコードについて】\n" .
        "QRコードは名刺ページに表示されています。\n" .
        "このQRコードをスキャンすると、上記の名刺URLに直接アクセスできます。\n\n" .
        "【次のステップ】\n" .
        "- 名刺の内容を確認・編集できます\n" .
        "- QRコードを名刺に印刷して配布できます\n" .
        "- SNSやメールで簡単に共有できます\n\n" .
        "マイページ: " . BASE_URL . "/edit.php\n\n" .
        ($paymentAmount ? "【お支払い情報】\nお支払い金額: ¥" . number_format($paymentAmount) . ($paymentTypeText ? "\nお支払い方法: {$paymentTypeText}" : "") . "\n発行日時: {$issuedDate}\n\n" : "") .
        "発行日時: {$issuedDate}\n";

    // 既存/ERA会員向けメールからは、HTML本文の名刺URLセクションも削除する
    if ($isExistingOrEra) {
        $emailBody = preg_replace(
            "/<div class='info-box'>\\s*<h3>📱 あなたのデジタル名刺<\\/h3>[\\s\\S]*?<\\/div>/u",
            '',
            $emailBody
        );
    }

    return sendEmail($userEmail, $emailSubject, $emailBody, $emailBodyText, 'qr_code_issued', null, null);
}

/**
 * 管理者にQRコード発行通知メールを送信
 * @param string $userEmail ユーザーメールアドレス
 * @param string $userName ユーザー名
 * @param int $userId ユーザーID
 * @param string $urlSlug URLスラッグ
 * @param float|null $paymentAmount 支払い金額
 * @param string|null $companyName 会社名
 * @param string|null $name 名前
 * @param string|null $nameRomaji ローマ字表記
 * @param string|null $phoneNumber 電話番号
 * @param string $userType ユーザータイプ ('new' or 'existing')
 * @param int $isEraMember ERA会員かどうか (0 or 1)
 * @param string|null $paymentType 支払い方法 ('CR', 'BANK_PAID', 'ST', etc.)
 */
function sendQRCodeIssuedEmailToAdmin($userEmail, $userName, $userId, $urlSlug, $paymentAmount = null, $companyName = null, $name = null, $nameRomaji = null, $phoneNumber = null, $userType = 'new', $isEraMember = 0, $paymentType = null) {
    $adminEmail = 'nishio@rchukai.jp';

    $issuedDate = date('Y年m月d日 H:i:s');
    $cardFullUrl = rtrim(QR_CODE_BASE_URL, '/') . '/card.php?slug=' . $urlSlug;
    
    // 既存/ERA会員で企業URLが未設定かチェック（url_slugが"user-"で始まる場合は仮URL）
    $isExistingOrEra = ($userType === 'existing' || $isEraMember);
    $isUrlSlugPending = (strpos($urlSlug, 'user-') === 0);
    $showUrgentUrlNotice = $isExistingOrEra && $isUrlSlugPending;
    
    // 支払い方法の表示テキスト
    $paymentTypeText = '';
    if ($paymentType) {
        $paymentTypeLabels = [
            'credit_card' => 'クレジットカード',
            'bank_transfer' => '銀行振込',
            'stripe' => 'クレジットカード',
            'CR' => 'クレジットカード',
            'BANK_PAID' => '銀行振込',
            'ST' => 'Stripe送金'
        ];
        $paymentTypeText = $paymentTypeLabels[$paymentType] ?? $paymentType;
    }

    // ユーザータイプに応じてメール件名とヘッダーを変更
    $subjectPrefix = '';
    $headerPrefix = '';
    $headerPrefixStyle = '';
    $userTypeLabel = '新規';
    $urgentNotice = '';
    
    // 管理画面ログインURL（企業URL変更用）
    $adminLoginUrl = rtrim(BASE_URL, '/') . '/admin/login.php';

    if ($isEraMember) {
        // ERA会員 … オレンジで表示
        $subjectPrefix = '【ERA/';
        $headerPrefix = '<span style="color: #fd7e14; font-weight: bold;">ERA/</span>';
        $userTypeLabel = 'ERA';
        $urgentColor = '#fd7e14'; // オレンジ
        if ($showUrgentUrlNotice) {
            $urgentNotice = '
                <div style="background: #fd7e14; color: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                    <h2 style="margin: 0 0 10px 0; font-size: 20px;">🚨 【緊急】ERA会員の企業URL未設定</h2>
                    <p style="margin: 0; font-size: 16px;">ERA会員のQRコードが発行されましたが、<strong>企業URLがまだ設定されていません。</strong></p>
                    <p style="margin: 10px 0 0 0; font-size: 16px;">至急、管理画面で企業URLの入力をお願いします。</p>
                    <p style="margin: 16px 0 0 0;"><a href="' . $adminLoginUrl . '" target="_blank" style="display: inline-block; padding: 12px 24px; background: #fff; color: #fd7e14; font-weight: bold; text-decoration: none; border-radius: 6px;">管理画面へ</a></p>
                </div>';
        } else {
            $urgentNotice = '<p style="color: #fd7e14; font-weight: bold; font-size: 16px; background: #fff8f0; padding: 10px; border-radius: 5px; margin-bottom: 20px;">⚠️ ERA会員です。企業URLの確認をお願いします。</p>';
        }
    } elseif ($userType === 'existing') {
        // 既存会員 … 赤で表示
        $subjectPrefix = '【既存/';
        $headerPrefix = '<span style="color: #dc3545; font-weight: bold;">既存/</span>';
        $userTypeLabel = '既存';
        $urgentColor = '#dc3545'; // 赤
        if ($showUrgentUrlNotice) {
            $urgentNotice = '
                <div style="background: #dc3545; color: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                    <h2 style="margin: 0 0 10px 0; font-size: 20px;">🚨 【緊急】既存会員の企業URL未設定</h2>
                    <p style="margin: 0; font-size: 16px;">既存会員のQRコードが発行されましたが、<strong>企業URLがまだ設定されていません。</strong></p>
                    <p style="margin: 10px 0 0 0; font-size: 16px;">至急、管理画面で企業URLの入力をお願いします。</p>
                    <p style="margin: 16px 0 0 0;"><a href="' . $adminLoginUrl . '" target="_blank" style="display: inline-block; padding: 12px 24px; background: #fff; color: #dc3545; font-weight: bold; text-decoration: none; border-radius: 6px;">管理画面へ</a></p>
                </div>';
        } else {
            $urgentNotice = '<p style="color: #dc3545; font-weight: bold; font-size: 16px; background: #fff3cd; padding: 10px; border-radius: 5px; margin-bottom: 20px;">⚠️ 既存会員です。企業URLの確認をお願いします。</p>';
        }
    } else {
        // 新規会員
        $subjectPrefix = '【';
        $urgentColor = '#dc3545';
    }

    // メール件名（緊急の場合は件名にも反映）
    if ($showUrgentUrlNotice) {
        $emailSubject = $subjectPrefix . '不動産AI名刺】🚨 QRコード発行通知 - 企業URL未設定';
    } else {
        $emailSubject = $subjectPrefix . '不動産AI名刺】QRコード発行通知';
    }

    // HTML本文（ERA=オレンジ / 既存=赤）
    $emailBody = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; line-height: 1.6; color: #333; }
            .container { border: 3px solid " . ($showUrgentUrlNotice ? $urgentColor : '#a3a3a3') . "; border-radius: 1%; max-width: 600px; margin: 0 auto;}
            .header { color: #000000; padding: 30px 20px; text-align: center; " . ($showUrgentUrlNotice ? "background: #fff8f5;" : "") . " }
            .header .logo-container { padding: 15px; display: inline-block; margin: 0 auto; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #fff; }
            .info-table th { background: #e9ecef; padding: 12px; text-align: left; border: 1px solid #dee2e6; font-weight: bold; width: 35%; }
            .info-table td { padding: 12px; border: 1px solid #dee2e6; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            .highlight { background: #fff3cd; padding: 2px 6px; border-radius: 3px; }
            .highlight-danger { background: #f8d7da; color: #721c24; padding: 2px 6px; border-radius: 3px; font-weight: bold; }
            .user-type-era { color: #fd7e14; font-weight: bold; }
            .user-type-existing { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 100px; height: auto;'>
                </div>
                <h1 style='" . ($showUrgentUrlNotice ? "color: " . $urgentColor . ";" : "") . "'>{$headerPrefix}QRコード発行通知</h1>
            </div>
            <div class='content'>
                {$urgentNotice}
                <p>新しいQRコードが発行されました。</p>
                <table class='info-table'>
                    <tr>
                        <th>ユーザーID</th>
                        <td>{$userId}</td>
                    </tr>
                    <tr>
                        <th>ユーザータイプ</th>
                        <td>" . ($isEraMember ? "<span class='user-type-era'>{$userTypeLabel}</span>" : ($userType === 'existing' ? "<span class='user-type-existing'>{$userTypeLabel}</span>" : $userTypeLabel)) . "</td>
                    </tr>
                    <tr>
                        <th>ユーザー名</th>
                        <td>{$userName}</td>
                    </tr>" .
                    ($companyName ? "
                    <tr>
                        <th>会社名</th>
                        <td>{$companyName}</td>
                    </tr>" : "") .
                    ($name ? "
                    <tr>
                        <th>名前</th>
                        <td>{$name}</td>
                    </tr>" : "") .
                    ($nameRomaji ? "
                    <tr>
                        <th>ローマ字表記</th>
                        <td>{$nameRomaji}</td>
                    </tr>" : "") . "
                    <tr>
                        <th>メールアドレス</th>
                        <td>{$userEmail}</td>
                    </tr>" .
                    ($paymentTypeText ? "
                    <tr>
                        <th>支払い方法</th>
                        <td>{$paymentTypeText}</td>
                    </tr>" : "") .
                    ($phoneNumber ? "
                    <tr>
                        <th>電話番号</th>
                        <td>{$phoneNumber}</td>
                    </tr>" : "") . "
                    <tr>
                        <th>URLスラッグ</th>
                        <td><span class='" . ($showUrgentUrlNotice ? "highlight-danger" : "highlight") . "'>{$urlSlug}</span>" . ($showUrgentUrlNotice ? " <strong style='color: " . $urgentColor . ";'>（仮URL - 要設定）</strong>" : "") . "</td>
                    </tr>
                    <tr>
                        <th>名刺URL</th>
                        <td><a href='{$cardFullUrl}' target='_blank'>{$cardFullUrl}</a></td>
                    </tr>";

    if ($paymentAmount) {
        $emailBody .= "
                    <tr>
                        <th>支払い金額</th>
                        <td>¥" . number_format($paymentAmount) . "</td>
                    </tr>";
    }

    $emailBody .= "
                    <tr>
                        <th>発行日時</th>
                        <td>{$issuedDate}</td>
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

    // 既存/ERA会員の場合、HTML本文からURLスラッグおよび名刺URLの行を削除（ダッシュボード側でのみ管理）
    if ($isExistingOrEra) {
        // URLスラッグ行
        $emailBody = preg_replace(
            "/<tr>\\s*<th>URLスラッグ<\\/th>[\\s\\S]*?<\\/tr>/u",
            '',
            $emailBody
        );
        // 名刺URL行
        $emailBody = preg_replace(
            "/<tr>\\s*<th>名刺URL<\\/th>[\\s\\S]*?<\\/tr>/u",
            '',
            $emailBody
        );
    }

    // プレーンテキスト版
    $urgentNoticeText = '';
    if ($showUrgentUrlNotice) {
        if ($isEraMember) {
            $urgentNoticeText = "🚨【緊急】ERA会員の企業URL未設定\n" .
                "ERA会員のQRコードが発行されましたが、企業URLがまだ設定されていません。\n" .
                "至急、管理画面で企業URLの入力をお願いします。\n" .
                "管理画面へ: {$adminLoginUrl}\n\n";
        } else {
            $urgentNoticeText = "🚨【緊急】既存会員の企業URL未設定\n" .
                "既存会員のQRコードが発行されましたが、企業URLがまだ設定されていません。\n" .
                "至急、管理画面で企業URLの入力をお願いします。\n" .
                "管理画面へ: {$adminLoginUrl}\n\n";
        }
    } elseif ($isEraMember) {
        $urgentNoticeText = "⚠️ ERA会員です。企業URLの確認をお願いします。\n\n";
    } elseif ($userType === 'existing') {
        $urgentNoticeText = "⚠️ 既存会員です。企業URLの確認をお願いします。\n\n";
    }
    
    $emailBodyText =
        $urgentNoticeText .
        "新しいQRコードが発行されました。\n\n" .
        "ユーザーID: {$userId}\n" .
        "ユーザータイプ: {$userTypeLabel}\n" .
        "ユーザー名: {$userName}\n" .
        ($companyName ? "会社名: {$companyName}\n" : "") .
        ($name ? "名前: {$name}\n" : "") .
        ($nameRomaji ? "ローマ字表記: {$nameRomaji}\n" : "") .
        "メールアドレス: {$userEmail}\n" .
        ($paymentTypeText ? "支払い方法: {$paymentTypeText}\n" : "") .
        ($phoneNumber ? "電話番号: {$phoneNumber}\n" : "") .
        // 既存/ERA会員にはテキストでもURLスラッグ・名刺URLを表示しない
        (!$isExistingOrEra ? "URLスラッグ: {$urlSlug}" . ($showUrgentUrlNotice ? "（仮URL - 要設定）" : "") . "\n" : "") .
        (!$isExistingOrEra ? "名刺URL: {$cardFullUrl}\n" : "") .
        ($paymentAmount ? "支払い金額: ¥" . number_format($paymentAmount) . "\n" : "") .
        "発行日時: {$issuedDate}\n";

    return sendEmail($adminEmail, $emailSubject, $emailBody, $emailBodyText, 'admin_qr_notification', null, $userId);
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
        // セッション保存パスをプロジェクト内に設定（権限エラーを回避）
        $sessionPath = __DIR__ . '/../../sessions';
        
        // セッションディレクトリが存在しない場合は作成
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0755, true);
        }
        
        // セッション保存パスが書き込み可能か確認
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        } else {
            // 書き込みできない場合は、システムの一時ディレクトリを使用
            $systemTemp = sys_get_temp_dir();
            if (is_writable($systemTemp)) {
                session_save_path($systemTemp);
            }
            // それでも書き込みできない場合は、デフォルトのパスを使用（警告は出るが動作は続行）
        }
        
        session_start();
    }
}
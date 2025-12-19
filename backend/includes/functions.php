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
        $mail->Username = 'maxlucky0709@gmail.com'; // あなたのGmail
        $mail->Password = 'jtbqdrigrrysyfqy'; // Gmailアプリパスワード
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
        $mail->setFrom('maxlucky0709@gmail.com', '不動産AI名刺');
        $mail->addReplyTo('maxlucky0709@gmail.com');

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
        $smtpResponseSafe = ($smtpResponse !== null) ? substr($smtpResponse, 0, 500) : null;
        logEmail($to, $subject, $emailType, $status, $deliveryTimeMs,
                 $smtpResponseSafe, null, $userId, $relatedId);

        if ($result) {
            error_log("[Email Success] Sent to {$to} in {$deliveryTimeMs}ms - Type: " . detectEmailType($to));
        }

        return $result;

    } catch (Exception $e) {
        $endTime = microtime(true);
        $deliveryTimeMs = round(($endTime - $startTime) * 1000, 2);
        $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
        $status = 'failed';

        // Check for Gmail daily sending limit error
        $isGmailLimitError = false;
        if (strpos($errorMessage, 'Daily user sending limit exceeded') !== false ||
            strpos($errorMessage, '550') !== false && strpos($errorMessage, '5.4.5') !== false ||
            strpos($errorMessage, 'DATA command failed') !== false) {
            $isGmailLimitError = true;
            $errorMessage .= ' [GMAIL_DAILY_LIMIT_EXCEEDED]';
            error_log("[Email Error] Gmail daily sending limit exceeded. Consider using email queue or alternative SMTP service.");
        }

        // Log failure
        $smtpResponseSafe = ($smtpResponse !== null) ? substr($smtpResponse, 0, 500) : null;
        logEmail($to, $subject, $emailType, $status, $deliveryTimeMs,
                 $smtpResponseSafe, $errorMessage, $userId, $relatedId);

        // If it's a Gmail limit error, try to queue the email for later
        if ($isGmailLimitError) {
            queueEmailForLater($to, $subject, $htmlMessage, $textMessage, $emailType, $userId, $relatedId);
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
                    <img src='" . BASE_URL . "/frontend/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 200px; height: auto;'>
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
 */
function sendQRCodeIssuedEmailToUser($userEmail, $userName, $cardUrl, $qrCodeUrl, $urlSlug, $paymentAmount = null) {
    if (empty($userEmail)) {
        error_log("sendQRCodeIssuedEmailToUser: User email is empty");
        return false;
    }

    $issuedDate = date('Y年m月d日 H:i:s');
    $cardFullUrl = QR_CODE_BASE_URL . $urlSlug;

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
            ul { padding-left: 20px; }
            li { margin: 8px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/frontend/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 200px; height: auto;'>
                </div>
                <h1>QRコード発行完了</h1>
            </div>
            <div class='content'>
                <p>{$userName} 様</p>
                <p>お支払いいただき、ありがとうございます。<br>
                デジタル名刺のQRコードが正常に発行されました。</p>

                <div class='info-box'>
                    <h3>📱 あなたのデジタル名刺</h3>
                    <p><strong>名刺URL:</strong><br>
                    <a href='{$cardFullUrl}' target='_blank'>{$cardFullUrl}</a></p>
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
                        <a href='" . BASE_URL . "/frontend/edit.php' class='button'>マイページで編集する</a>
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
    $emailBodyText =
        "{$userName} 様\n\n" .
        "お支払いいただき、ありがとうございます。\n" .
        "デジタル名刺のQRコードが正常に発行されました。\n\n" .
        "【あなたのデジタル名刺】\n" .
        "名刺URL: {$cardFullUrl}\n\n" .
        "【QRコードについて】\n" .
        "QRコードは名刺ページに表示されています。\n" .
        "このQRコードをスキャンすると、上記の名刺URLに直接アクセスできます。\n\n" .
        "【次のステップ】\n" .
        "- 名刺の内容を確認・編集できます\n" .
        "- QRコードを名刺に印刷して配布できます\n" .
        "- SNSやメールで簡単に共有できます\n\n" .
        "マイページ: " . BASE_URL . "/frontend/edit.php\n\n" .
        ($paymentAmount ? "【お支払い情報】\nお支払い金額: ¥" . number_format($paymentAmount) . "\n発行日時: {$issuedDate}\n\n" : "") .
        "発行日時: {$issuedDate}\n";

    return sendEmail($userEmail, $emailSubject, $emailBody, $emailBodyText, 'qr_code_issued', null, null);
}

/**
 * 管理者にQRコード発行通知メールを送信
 */
function sendQRCodeIssuedEmailToAdmin($userEmail, $userName, $userId, $urlSlug, $paymentAmount = null, $companyName = null, $name = null, $nameRomaji = null, $phoneNumber = null) {
    $adminEmail = 'nishio@rchukai.jp';

    $issuedDate = date('Y年m月d日 H:i:s');
    $cardFullUrl = QR_CODE_BASE_URL . $urlSlug;

    // メール件名
    $emailSubject = '【不動産AI名刺】QRコード発行通知';

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
            .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #fff; }
            .info-table th { background: #e9ecef; padding: 12px; text-align: left; border: 1px solid #dee2e6; font-weight: bold; width: 35%; }
            .info-table td { padding: 12px; border: 1px solid #dee2e6; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            .highlight { background: #fff3cd; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo-container'>
                    <img src='" . BASE_URL . "/frontend/assets/images/logo.png" . "' alt='不動産AI名刺' style='max-width: 200px; height: auto;'>
                </div>
                <h1>QRコード発行通知</h1>
            </div>
            <div class='content'>
                <p>新しいQRコードが発行されました。</p>
                <table class='info-table'>
                    <tr>
                        <th>ユーザーID</th>
                        <td>{$userId}</td>
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
                    ($phoneNumber ? "
                    <tr>
                        <th>電話番号</th>
                        <td>{$phoneNumber}</td>
                    </tr>" : "") . "
                    <tr>
                        <th>URLスラッグ</th>
                        <td><span class='highlight'>{$urlSlug}</span></td>
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

    // プレーンテキスト版
    $emailBodyText =
        "新しいQRコードが発行されました。\n\n" .
        "ユーザーID: {$userId}\n" .
        "ユーザー名: {$userName}\n" .
        ($companyName ? "会社名: {$companyName}\n" : "") .
        ($name ? "名前: {$name}\n" : "") .
        ($nameRomaji ? "ローマ字表記: {$nameRomaji}\n" : "") .
        "メールアドレス: {$userEmail}\n" .
        ($phoneNumber ? "電話番号: {$phoneNumber}\n" : "") .
        "URLスラッグ: {$urlSlug}\n" .
        "名刺URL: {$cardFullUrl}\n" .
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
        session_start();
    }
}


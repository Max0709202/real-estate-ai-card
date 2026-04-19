<?php
/**
 * Hardened upload pipeline helpers: validation, quarantine, ClamAV, metadata strip, rate limit, audit log.
 * Loaded by functions.php after Composer autoload.
 */

/**
 * Reject dangerous client-supplied filenames (path traversal, script extensions, double-extension tricks).
 * Stored filenames are always server-generated; this still blocks abusive uploads early.
 *
 * @return string|null Error message, or null if OK
 */
function upload_security_validate_client_filename(string $originalName): ?string {
    $base = basename(str_replace('\\', '/', $originalName));
    if ($base === '' || $base === '.' || $base === '..') {
        return '無効なファイル名です。';
    }
    if (strpos($base, "\0") !== false) {
        return '無効なファイル名です。';
    }
    // Path / traversal
    if (preg_match('/[\\/\\\\]/', $originalName)) {
        return '無効なファイル名です。';
    }
    $lower = strtolower($base);
    // Block script-like extensions anywhere in the name (e.g. evil.php.jpg, x.php.png)
    if (preg_match('/\.(php|phtml|phar|pht|cgi|pl|asp|aspx|jsp|sh|bash|cmd|bat|exe)(\.|$)/i', $lower)) {
        return '許可されていないファイル名です。';
    }
    if (preg_match('/\.php/i', $lower)) {
        return '許可されていないファイル名です。';
    }
    return null;
}

/**
 * Map detected MIME to a single safe extension (never trust client extension).
 *
 * @return string|null Extension without dot, or null
 */
function upload_security_mime_to_extension(string $mimeType): ?string {
    $map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    return $map[$mimeType] ?? null;
}

/**
 * Log structured security/audit events (grep for prefix [upload_security]).
 */
function upload_security_log_event(string $event, array $context, ?int $userId = null): void {
    $row = [
        'ts'       => date('c'),
        'event'    => $event,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_id'  => $userId,
        'context'  => $context,
    ];
    error_log('[upload_security] ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Simple per-IP sliding window rate limit (file-based, no DB).
 */
function upload_security_check_rate_limit(string $ip): bool {
    $maxPerMin = defined('UPLOAD_RATE_LIMIT_PER_MINUTE') ? (int) UPLOAD_RATE_LIMIT_PER_MINUTE : 30;
    if ($maxPerMin <= 0) {
        return true;
    }
    $dir = sys_get_temp_dir();
    $file = $dir . '/ai_fcard_upload_rl_' . hash('sha256', $ip) . '.json';
    $now = time();
    $data = ['window_start' => $now, 'count' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
                $data = $decoded;
            }
        }
    }
    if ($now - (int) $data['window_start'] >= 60) {
        $data['window_start'] = $now;
        $data['count'] = 0;
    }
    $data['count'] = (int) $data['count'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] > $maxPerMin) {
        upload_security_log_event('rate_limit_exceeded', ['count' => $data['count']], null);
        return false;
    }
    return true;
}

/**
 * Validate image dimensions and total pixel count (decompression bomb mitigation).
 *
 * @return array{ok:bool,message?:string}
 */
function upload_security_validate_image_dimensions(string $filePath, string $mimeType): array {
    $maxDim = defined('UPLOAD_MAX_DIMENSION') ? (int) UPLOAD_MAX_DIMENSION : 8000;
    $maxPx  = defined('UPLOAD_MAX_PIXELS') ? (int) UPLOAD_MAX_PIXELS : 25000000;

    $info = @getimagesize($filePath);
    if ($info === false) {
        return ['ok' => false, 'message' => '画像として読み取れませんでした。'];
    }
    $w = (int) $info[0];
    $h = (int) $info[1];
    if ($w <= 0 || $h <= 0) {
        return ['ok' => false, 'message' => '画像の寸法が無効です。'];
    }
    if ($w > $maxDim || $h > $maxDim) {
        return ['ok' => false, 'message' => '画像の解像度が大きすぎます。'];
    }
    $pixels = $w * $h;
    if ($pixels > $maxPx) {
        return ['ok' => false, 'message' => '画像のピクセル数が大きすぎます。'];
    }
    $mimeFromI = $info['mime'] ?? '';
    if ($mimeFromI !== '' && $mimeFromI !== $mimeType) {
        return ['ok' => false, 'message' => 'ファイル内容と形式が一致しません。'];
    }
    return ['ok' => true];
}

/**
 * ClamAV scan. Set CLAMAV_ENABLED=1 and optionally CLAMAV_SCAN_CMD (default: clamscan).
 * Exit codes: 0 = clean, 1 = infected, 2 = error
 *
 * @return array{ok:bool,infected?:bool,message?:string,skipped?:bool}
 */
function upload_security_clamav_scan(string $path): array {
    if (!is_readable($path)) {
        return ['ok' => false, 'message' => 'スキャン対象ファイルを読み取れません。'];
    }
    $enabled = getenv('CLAMAV_ENABLED');
    if ($enabled !== '1' && $enabled !== 'true') {
        return ['ok' => true, 'skipped' => true];
    }
    $cmd = getenv('CLAMAV_SCAN_CMD') ?: 'clamscan';
    $cmdline = $cmd . ' --no-summary ' . escapeshellarg($path) . ' 2>&1';
    $out = [];
    $code = 0;
    @exec($cmdline, $out, $code);
    $summary = implode("\n", $out);
    if ($code === 0) {
        return ['ok' => true, 'skipped' => false];
    }
    if ($code === 1) {
        error_log('[upload_security] ClamAV: infected detected in ' . $path . ' ' . $summary);
        return ['ok' => false, 'infected' => true, 'message' => $summary];
    }
    error_log('[upload_security] ClamAV: scanner error code=' . $code . ' ' . $summary);
    return ['ok' => false, 'infected' => false, 'message' => 'ウイルススキャンに失敗しました。'];
}

/**
 * Decode with GD and re-encode to a temp file, then replace original — strips EXIF/ICC/XMP metadata for raster formats.
 */
function upload_security_reencode_strip_metadata(string $path, string $mimeType, int $quality): bool {
    if (!file_exists($path)) {
        return false;
    }
    $info = @getimagesize($path);
    if ($info === false) {
        return false;
    }
    $im = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $im = @imagecreatefromjpeg($path);
            break;
        case 'image/png':
            $im = @imagecreatefrompng($path);
            if ($im) {
                imagealphablending($im, false);
                imagesavealpha($im, true);
            }
            break;
        case 'image/gif':
            $im = @imagecreatefromgif($path);
            break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
                return upload_security_reencode_strip_metadata_imagick($path, $mimeType, $quality);
            }
            $im = @imagecreatefromwebp($path);
            if ($im) {
                imagealphablending($im, false);
                imagesavealpha($im, true);
            }
            break;
        default:
            return false;
    }
    if (!$im) {
        return upload_security_reencode_strip_metadata_imagick($path, $mimeType, $quality);
    }

    $tmp = $path . '.re.' . bin2hex(random_bytes(4));
    $ok = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $ok = imagejpeg($im, $tmp, max(1, min(100, $quality)));
            break;
        case 'image/png':
            $pngQ = (int) ((100 - $quality) / 11.11);
            $ok = imagepng($im, $tmp, min(9, max(0, $pngQ)));
            break;
        case 'image/gif':
            $ok = imagegif($im, $tmp);
            break;
        case 'image/webp':
            $ok = imagewebp($im, $tmp, max(1, min(100, $quality)));
            break;
    }
    imagedestroy($im);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Fallback: strip metadata with Imagick when GD cannot decode.
 */
function upload_security_reencode_strip_metadata_imagick(string $path, string $mimeType, int $quality): bool {
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        $img = new Imagick($path);
        $img->stripImage();
        $fmt = str_replace('image/', '', $mimeType);
        if ($fmt === 'jpeg') {
            $fmt = 'jpeg';
        }
        $img->setImageFormat($fmt);
        if ($mimeType === 'image/jpeg' || $mimeType === 'image/webp') {
            $img->setImageCompressionQuality(max(1, min(100, $quality)));
        }
        $img->writeImage($path);
        $img->clear();
        $img->destroy();
        return true;
    } catch (Throwable $e) {
        error_log('[upload_security] Imagick strip failed: ' . $e->getMessage());
        return false;
    }
}

<?php
/**
 * Dynamic favicon generator from assets/images/favicon.png
 * Serves PNG at 16x16 and 32x32 for use as favicon.
 */

$size = isset($_GET['size']) ? (int)$_GET['size'] : 32;
if (!in_array($size, [16, 32], true)) {
    http_response_code(404);
    exit('Not Found');
}

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('GD extension is required to generate favicon.');
}

$srcPath = __DIR__ . '/assets/images/favicon.png';
if (!file_exists($srcPath)) {
    http_response_code(404);
    exit('Not Found');
}

$src = @imagecreatefrompng($srcPath);
if (!$src) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Failed to load source image.');
}

$srcW = imagesx($src);
$srcH = imagesy($src);

// Preserve transparency if present
$dst = imagecreatetruecolor($size, $size);
imagealphablending($dst, false);
imagesavealpha($dst, true);
$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
imagefill($dst, 0, 0, $transparent);

// Scale to fit inside square (center)
$scale = min($size / max(1, $srcW), $size / max(1, $srcH));
$newW = (int)max(1, round($srcW * $scale));
$newH = (int)max(1, round($srcH * $scale));
$dstX = (int)floor(($size - $newW) / 2);
$dstY = (int)floor(($size - $newH) / 2);

imagealphablending($dst, true);
imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
imagedestroy($src);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800'); // 7 days
imagepng($dst);
imagedestroy($dst);
exit;

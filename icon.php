<?php
/**
 * Dynamic PWA icon generator.
 * Serves square PNG icons at /icon-192.png and /icon-512.png via .htaccess rewrite.
 *
 * Source: assets/images/logo.png
 */

$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;
if (!in_array($size, [192, 512], true)) {
    http_response_code(404);
    exit('Not Found');
}

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('GD extension is required to generate icons.');
}

$srcPath = __DIR__ . '/assets/images/logo.png';
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

// Create a square white canvas and fit the logo inside (no cropping)
$dst = imagecreatetruecolor($size, $size);
$white = imagecolorallocate($dst, 255, 255, 255);
imagefill($dst, 0, 0, $white);

// Fit within 80% of the square (padding)
$max = (int)floor($size * 0.80);
$scale = min($max / max(1, $srcW), $max / max(1, $srcH));
$newW = (int)max(1, round($srcW * $scale));
$newH = (int)max(1, round($srcH * $scale));
$dstX = (int)floor(($size - $newW) / 2);
$dstY = (int)floor(($size - $newH) / 2);

imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
imagedestroy($src);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800'); // 7 days
imagepng($dst);
imagedestroy($dst);
exit;
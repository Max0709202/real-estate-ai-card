<?php
/**
 * Dynamic Web App Manifest per business card.
 * start_url = this card; name/short_name = card holder for home screen icon.
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header('HTTP/1.0 400 Bad Request');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Bad Request']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("
    SELECT bc.name, bc.url_slug, bc.payment_status, bc.is_published
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    WHERE bc.url_slug = ? AND u.status = 'active'
");
$stmt->execute([$slug]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Not Found']);
    exit;
}
if (!in_array($card['payment_status'], ['CR', 'BANK_PAID', 'ST']) || (int)$card['is_published'] !== 1) {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Not Found']);
    exit;
}

$base = rtrim(BASE_URL, '/');
$startUrl = $base . '/card.php?slug=' . urlencode($card['url_slug']);
$cardName = trim($card['name'] ?? '');
$appName = $cardName !== '' ? $cardName . 'の名刺' : 'AI名刺';
$shortName = $cardName !== '' ? mb_substr($cardName, 0, 12) : 'AI名刺';

$manifest = [
    'name' => $appName,
    'short_name' => $shortName,
    'start_url' => $startUrl,
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#0A84FF',
    'icons' => [
        ['src' => $base . '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $base . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
];

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=300');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

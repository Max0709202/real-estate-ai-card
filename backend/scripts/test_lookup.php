<?php
/**
 * Test license lookup API
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = (new Database())->getConnection();

// Test searches - includes real MLIT data scraped from government website
$tests = [
    // Real MLIT data from Hokkaido
    ['prefecture' => '北海道', 'renewal' => 16, 'registration' => '000252'],
    ['prefecture' => '北海道', 'renewal' => 16, 'registration' => '000293'],
    ['prefecture' => '北海道', 'renewal' => 16, 'registration' => '000310'],
    // Real MLIT data from other prefectures
    ['prefecture' => '東京都', 'renewal' => 18, 'registration' => '000001'],
    ['prefecture' => '大阪府', 'renewal' => 18, 'registration' => '001234'],
    ['prefecture' => '福岡県', 'renewal' => 17, 'registration' => '001234'],
];

echo "=== License Lookup Test ===\n\n";

foreach ($tests as $test) {
    echo "Searching: {$test['prefecture']}, renewal={$test['renewal']}, reg={$test['registration']}\n";

    $stmt = $db->prepare("
        SELECT company_name, address, full_license_text
        FROM real_estate_licenses
        WHERE (prefecture = ? OR prefecture_code = ?)
          AND renewal_number = ?
          AND (registration_number = ? OR registration_number = ?)
        LIMIT 1
    ");

    $prefCode = [
        '北海道' => '01', '青森県' => '02', '東京都' => '13', '大阪府' => '27',
        '神奈川県' => '14', '福岡県' => '40'
    ][$test['prefecture']] ?? '';

    $regWithZeros = str_pad($test['registration'], 6, '0', STR_PAD_LEFT);

    $stmt->execute([
        $test['prefecture'],
        $prefCode,
        $test['renewal'],
        $test['registration'],
        $regWithZeros
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        echo "  ✓ Found: {$row['company_name']}\n";
        echo "    Address: {$row['address']}\n";
        echo "    License: {$row['full_license_text']}\n";
    } else {
        echo "  ✗ Not found\n";
    }
    echo "\n";
}


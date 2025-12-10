<?php
/**
 * Postal Code Lookup API
 * Uses Yahoo! Japan Postal Code API
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'GET' && $method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $postalCode = $_GET['postal_code'] ?? $_POST['postal_code'] ?? '';
    
    if (empty($postalCode)) {
        sendErrorResponse('郵便番号を入力してください', 400);
    }

    // Remove hyphens
    $postalCode = str_replace('-', '', $postalCode);
    
    // Validate format (7 digits)
    if (!preg_match('/^\d{7}$/', $postalCode)) {
        sendErrorResponse('有効な郵便番号を入力してください（7桁）', 400);
    }

    // Use Yahoo! Japan Postal Code API
    $url = "https://zipcloud.ibsnet.co.jp/api/search?zipcode=" . $postalCode;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        sendErrorResponse('住所の取得に失敗しました', 500);
    }

    $data = json_decode($response, true);
    
    if (!$data || $data['status'] !== 200 || empty($data['results'])) {
        sendErrorResponse('該当する住所が見つかりませんでした', 404);
    }

    $result = $data['results'][0];
    
    // Build full address (prefecture + city + street)
    $prefecture = $result['address1'] ?? '';
    $city = $result['address2'] ?? '';
    $street = $result['address3'] ?? '';
    $fullAddress = $prefecture . $city . $street;

    sendSuccessResponse([
        'postal_code' => $postalCode,
        'prefecture' => $prefecture,
        'city' => $city,
        'street' => $street,
        'address' => $fullAddress
    ], '住所を取得しました');

} catch (Exception $e) {
    error_log("Postal Code Lookup Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


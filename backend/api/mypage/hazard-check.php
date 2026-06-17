<?php
/**
 * Address-based hazard check for the my page.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        sendErrorResponse('Method not allowed', 405);
    }
    requireAuth();

    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) $input = [];
    }
    $address = trim((string)($input['address'] ?? ($_GET['address'] ?? '')));
    if ($address === '') {
        sendErrorResponse('住所を入力してください', 400);
    }
    if (mb_strlen($address) > 180) {
        sendErrorResponse('住所が長すぎます', 400);
    }
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') {
        sendErrorResponse('不動産情報ライブラリAPIキーが未設定です', 500);
    }

    $db = (new Database())->getConnection();
    $report = chatHazardAddressReport($db, $address);
    if (!$report) {
        sendErrorResponse('ハザード情報を取得できませんでした', 500);
    }

    sendSuccessResponse($report, $report['message'] ?? 'OK');
} catch (Exception $e) {
    error_log('Hazard check API error: ' . $e->getMessage());
    sendErrorResponse(ENVIRONMENT === 'development' ? $e->getMessage() : 'サーバーエラーが発生しました', 500);
}

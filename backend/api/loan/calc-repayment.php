<?php
/**
 * Loan simulator: monthly repayment amount.
 * POST { "card_slug": "...", "principal": 30000000, "rate_year": 2.5, "term_years": 35 }
 * Plan-gated: requires standard plan.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-helpers.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$cardSlug = trim($input['card_slug'] ?? '');
$principal = isset($input['principal']) ? (float) $input['principal'] : 0;
$rateYear = isset($input['rate_year']) ? (float) $input['rate_year'] : 2.5;
$termYears = isset($input['term_years']) ? (int) $input['term_years'] : 35;

if ($cardSlug === '') {
    sendErrorResponse('card_slug is required', 400);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $card = getCardBySlugForChat($db, $cardSlug);

    if (!$card) {
        sendErrorResponse('名刺が見つかりません', 404);
    }

    if (!canUseLoanSim($card)) {
        sendErrorResponse('この名刺ではローンシミュレーションはご利用いただけません。', 403);
    }

    if ($principal <= 0 || $termYears <= 0) {
        sendErrorResponse('借入金額と返済年数は正の値で指定してください。', 400);
    }

    $termMonths = $termYears * 12;
    $rateMonth = $rateYear / 100 / 12;

    if ($rateMonth <= 0) {
        $monthly = $principal / $termMonths;
    } else {
        $monthly = $principal * ($rateMonth * pow(1 + $rateMonth, $termMonths)) / (pow(1 + $rateMonth, $termMonths) - 1);
    }

    $totalRepayment = $monthly * $termMonths;
    $totalInterest = $totalRepayment - $principal;

    sendSuccessResponse([
        'monthly_payment' => round($monthly),
        'total_repayment' => round($totalRepayment),
        'total_interest' => round($totalInterest),
        'principal' => $principal,
        'rate_year' => $rateYear,
        'term_years' => $termYears,
    ], 'OK');
} catch (Exception $e) {
    error_log('Loan calc repayment error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

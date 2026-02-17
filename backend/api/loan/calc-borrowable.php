<?php
/**
 * Loan simulator: maximum borrowable amount (from income).
 * POST { "card_slug": "...", "annual_income": 5000000, "rate_year": 2.5, "term_years": 35, "dbr_ratio": 0.35 }
 * DBR = Debt Burden Ratio (返済負担率). Default 35%.
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
$annualIncome = isset($input['annual_income']) ? (float) $input['annual_income'] : 0;
$rateYear = isset($input['rate_year']) ? (float) $input['rate_year'] : 2.5;
$termYears = isset($input['term_years']) ? (int) $input['term_years'] : 35;
$dbrRatio = isset($input['dbr_ratio']) ? (float) $input['dbr_ratio'] : 0.35;

if ($dbrRatio <= 0 || $dbrRatio > 1) {
    $dbrRatio = 0.35;
}

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

    if ($annualIncome <= 0 || $termYears <= 0) {
        sendErrorResponse('年収と返済年数は正の値で指定してください。', 400);
    }

    $termMonths = $termYears * 12;
    $rateMonth = $rateYear / 100 / 12;

    $maxMonthlyRepayment = $annualIncome / 12 * $dbrRatio;

    if ($rateMonth <= 0) {
        $maxBorrowable = $maxMonthlyRepayment * $termMonths;
    } else {
        $maxBorrowable = $maxMonthlyRepayment * (pow(1 + $rateMonth, $termMonths) - 1) / ($rateMonth * pow(1 + $rateMonth, $termMonths));
    }

    sendSuccessResponse([
        'max_borrowable' => round($maxBorrowable),
        'annual_income' => $annualIncome,
        'max_monthly_payment' => round($maxMonthlyRepayment),
        'rate_year' => $rateYear,
        'term_years' => $termYears,
        'dbr_ratio' => $dbrRatio,
    ], 'OK');
} catch (Exception $e) {
    error_log('Loan calc borrowable error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

<?php
/**
 * Loan simulator: monthly repayment amount.
 * POST { "card_slug": "..." (optional), "loan_amount": 50000000, "down_payment": 5000000, "rate_year": 2.5, "term_years": 35, "repayment_type": "equal_installment"|"equal_principal" }
 * principal = loan_amount - down_payment. When card_slug omitted, plan check is skipped (standalone simulator).
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
$loanAmount = isset($input['loan_amount']) ? (float) $input['loan_amount'] : (isset($input['principal']) ? (float) $input['principal'] : 0);
$downPayment = isset($input['down_payment']) ? (float) $input['down_payment'] : 0;
$principal = $loanAmount - $downPayment;
if ($principal < 0) {
    $principal = 0;
}
$rateYear = isset($input['rate_year']) ? (float) $input['rate_year'] : 2.5;
$termYears = isset($input['term_years']) ? (int) $input['term_years'] : 35;
$repaymentType = isset($input['repayment_type']) ? trim($input['repayment_type']) : 'equal_installment';
if (!in_array($repaymentType, ['equal_installment', 'equal_principal'], true)) {
    $repaymentType = 'equal_installment';
}

if ($cardSlug !== '') {
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
    } catch (Exception $e) {
        error_log('Loan calc repayment error: ' . $e->getMessage());
        sendErrorResponse('サーバーエラーが発生しました', 500);
    }
}

if ($principal <= 0 || $termYears <= 0) {
    sendErrorResponse('借入金額と返済年数は正の値で指定してください。', 400);
}

$termMonths = $termYears * 12;
$rateMonth = $rateYear / 100 / 12;

if ($repaymentType === 'equal_principal') {
    $principalPerMonth = $principal / $termMonths;
    $totalInterest = 0;
    $balance = $principal;
    for ($i = 0; $i < $termMonths; $i++) {
        $interest = $balance * $rateMonth;
        $totalInterest += $interest;
        $balance -= $principalPerMonth;
    }
    $firstMonthly = $principalPerMonth + ($principal * $rateMonth);
    $lastMonthly = $principalPerMonth + ($principalPerMonth * $rateMonth);
    $totalRepayment = $principal + $totalInterest;
    $monthly = $firstMonthly;
} else {
    if ($rateMonth <= 0) {
        $monthly = $principal / $termMonths;
    } else {
        $monthly = $principal * ($rateMonth * pow(1 + $rateMonth, $termMonths)) / (pow(1 + $rateMonth, $termMonths) - 1);
    }
    $totalRepayment = $monthly * $termMonths;
    $totalInterest = $totalRepayment - $principal;
}

sendSuccessResponse([
    'monthly_payment' => round($monthly),
    'total_repayment' => round($totalRepayment),
    'total_interest' => round($totalInterest),
    'principal' => $principal,
    'loan_amount' => $loanAmount,
    'down_payment' => $downPayment,
    'rate_year' => $rateYear,
    'term_years' => $termYears,
    'repayment_type' => $repaymentType,
], 'OK');

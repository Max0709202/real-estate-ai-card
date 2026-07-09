<?php
/**
 * Loan simulator: maximum borrowable amount.
 * Mode 1 (from income): annual_income, rate_year, term_years, dbr_ratio.
 * Mode 2 (from monthly): desired_monthly_payment, rate_year, term_years.
 * card_slug optional; when omitted, plan check is skipped (standalone simulator).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-helpers.php';
require_once __DIR__ . '/../../includes/loan-simulation-helper.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';

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
$cardSlug = trim($input["card_slug"] ?? "");
$visitorId = loanSimulationNormalizeVisitorId($input["visitor_id"] ?? "");
$sessionId = loanSimulationNormalizeSessionId($input["session_id"] ?? "");
$annualIncome = isset($input['annual_income']) ? (float) $input['annual_income'] : 0;
$desiredMonthly = isset($input['desired_monthly_payment']) ? (float) $input['desired_monthly_payment'] : 0;
$rateYear = isset($input['rate_year']) ? (float) $input['rate_year'] : 2.5;
$termYears = isset($input['term_years']) ? (int) $input['term_years'] : 35;
$dbrRatio = isset($input['dbr_ratio']) ? (float) $input['dbr_ratio'] : 0.35;

if ($dbrRatio <= 0 || $dbrRatio > 1) {
    $dbrRatio = 0.35;
}
if ($rateYear < 0 || $rateYear > 15 || $termYears < 1 || $termYears > 50) {
    sendErrorResponse('金利は0〜15%、返済期間は1〜50年で指定してください。', 400);
}
if ($annualIncome < 0 || $annualIncome > 999990000 || $desiredMonthly < 0 || $desiredMonthly > 10000000) {
    sendErrorResponse('年収または希望月額返済を正しく指定してください。', 400);
}

$card = null;
$db = null;
$canPersistLoanSimulation = false;
if ($cardSlug !== "") {
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
        if ($sessionId !== "" && $visitorId !== "" && chatSessionDeviceAuth($db, $sessionId, $visitorId)) {
            $leadProfile = chatIntakeLoad($db, $sessionId, (int)$card["id"]);
            $canPersistLoanSimulation = !empty($leadProfile["customer_phone_verified"])
                && !empty($leadProfile["customer_last_name"])
                && !empty($leadProfile["customer_first_name"])
                && !empty($leadProfile["customer_email"]);
        }
    } catch (Exception $e) {
        error_log('Loan calc borrowable error: ' . $e->getMessage());
        sendErrorResponse('サーバーエラーが発生しました', 500);
    }
}

$termMonths = $termYears * 12;
$rateMonth = $rateYear / 100 / 12;

if ($desiredMonthly > 0 && $termYears > 0) {
    if ($rateMonth <= 0) {
        $maxBorrowable = $desiredMonthly * $termMonths;
    } else {
        $maxBorrowable = $desiredMonthly * (pow(1 + $rateMonth, $termMonths) - 1) / ($rateMonth * pow(1 + $rateMonth, $termMonths));
    }
    if ($canPersistLoanSimulation && $cardSlug !== "" && $db instanceof PDO && is_array($card)) {
        saveLoanSimulationInput($db, (int)$card["id"], $sessionId, $visitorId, "borrow_monthly", [
            "desired_monthly_payment" => $desiredMonthly,
        ]);
    }
    sendSuccessResponse([
        'max_borrowable' => round($maxBorrowable),
        'desired_monthly_payment' => $desiredMonthly,
        'max_monthly_payment' => $desiredMonthly,
        'rate_year' => $rateYear,
        'term_years' => $termYears,
        'mode' => 'from_monthly',
    ], 'OK');
} elseif ($annualIncome > 0 && $termYears > 0) {
    $maxMonthlyRepayment = $annualIncome / 12 * $dbrRatio;
    if ($rateMonth <= 0) {
        $maxBorrowable = $maxMonthlyRepayment * $termMonths;
    } else {
        $maxBorrowable = $maxMonthlyRepayment * (pow(1 + $rateMonth, $termMonths) - 1) / ($rateMonth * pow(1 + $rateMonth, $termMonths));
    }
    if ($canPersistLoanSimulation && $cardSlug !== "" && $db instanceof PDO && is_array($card)) {
        saveLoanSimulationInput($db, (int)$card["id"], $sessionId, $visitorId, "borrow_income", [
            "annual_income" => $annualIncome,
        ]);
    }
    sendSuccessResponse([
        'max_borrowable' => round($maxBorrowable),
        'annual_income' => $annualIncome,
        'max_monthly_payment' => round($maxMonthlyRepayment),
        'rate_year' => $rateYear,
        'term_years' => $termYears,
        'dbr_ratio' => round($dbrRatio, 4),
        'mode' => 'from_income',
    ], 'OK');
} else {
    sendErrorResponse('年収または希望月額返済のいずれかを正の値で指定してください。', 400);
}

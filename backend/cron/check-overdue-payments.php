<?php
/**
 * Cron Job: Check for Overdue Monthly Payments
 * 
 * This script checks monthly-billing new users who have failed to pay their monthly fees
 * and automatically updates business_cards.payment_status to 'UNUSED' and sets business cards to unpublished.
 * 
 * Run this script daily via cron:
 * 0 0 * * * /usr/bin/php /path/to/backend/cron/check-overdue-payments.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Set time limit for long-running script
set_time_limit(300); // 5 minutes

// Error logging
$logFile = __DIR__ . '/../logs/overdue-payments.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    echo $logMessage;
}

try {
    logMessage("Starting overdue payment check...");
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    $updatedCount = 0;
    $errors = [];
    
    // 1. Check monthly-billing cards whose paid status has passed the next billing date.
    $stmt = $db->prepare(<<<SQL
        SELECT
            s.id,
            s.user_id,
            s.business_card_id,
            s.next_billing_date,
            s.amount,
            s.billing_cycle,
            COALESCE(bc.payment_status, 'UNUSED') AS payment_status
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        JOIN business_cards bc ON s.business_card_id = bc.id
        WHERE u.user_type = 'new'
          AND COALESCE(u.is_era_member, 0) = 0
          AND COALESCE(bc.card_status, 'active') <> 'canceled'
          AND COALESCE(bc.payment_status, 'UNUSED') IN ('CR', 'BANK_PAID', 'ST')
          AND s.status IN ('active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'incomplete_expired')
          AND s.next_billing_date IS NOT NULL
          AND s.next_billing_date <= CURDATE()
          AND NOT EXISTS (
              SELECT 1
              FROM payments p
              WHERE p.business_card_id = s.business_card_id
                AND p.payment_status = 'completed'
                AND p.paid_at >= s.next_billing_date
                AND p.payment_type IN ('new_user', 'renewal')
          )
SQL);
    $stmt->execute();
    $overdueSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    logMessage("Found " . count($overdueSubscriptions) . " monthly-billing cards with expired billing dates");

    foreach ($overdueSubscriptions as $subscription) {
        try {
            $businessCardId = (int) $subscription['business_card_id'];
            $userId = (int) $subscription['user_id'];
            $nextBillingDate = $subscription['next_billing_date'];

            $updateBcStmt = $db->prepare(<<<SQL
                UPDATE business_cards
                SET payment_status = 'UNUSED',
                    is_published = 0,
                    updated_at = NOW()
                WHERE id = ?
                  AND COALESCE(payment_status, 'UNUSED') IN ('CR', 'BANK_PAID', 'ST')
SQL);
            $updateBcStmt->execute([$businessCardId]);

            if ($updateBcStmt->rowCount() < 1) {
                continue;
            }

            $updatedCount++;
            logMessage("Updated business_card_id {$businessCardId} (user_id: {$userId}) - payment_status {$subscription['payment_status']} -> UNUSED, billing date: {$nextBillingDate}");
        } catch (Exception $e) {
            $errors[] = "Error processing subscription {$subscription['id']}: " . $e->getMessage();
            logMessage("ERROR: Subscription {$subscription['id']} - " . $e->getMessage());
        }
    }

    // Commit transaction
    $db->commit();
    
    logMessage("Completed overdue payment check. Updated {$updatedCount} records.");
    if (!empty($errors)) {
        logMessage("Errors encountered: " . count($errors));
        foreach ($errors as $error) {
            logMessage("  - {$error}");
        }
    }
    
    echo json_encode([
        'success' => true,
        'updated_count' => $updatedCount,
        'errors' => $errors
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    logMessage("FATAL ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}


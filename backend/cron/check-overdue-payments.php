<?php
/**
 * Cron Job: Check for Overdue Monthly Payments
 * 
 * This script checks for users (new and existing) who have failed to pay their monthly fees
 * and automatically updates their payment status to 'pending' and sets business cards to unpublished.
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
    
    // 1. Check subscriptions with overdue billing dates
    // Find active subscriptions where next_billing_date has passed
    // and there's no completed payment after the billing date
    $stmt = $db->prepare("
        SELECT s.id, s.user_id, s.business_card_id, s.next_billing_date, s.amount, s.billing_cycle
        FROM subscriptions s
        WHERE s.status = 'active'
        AND s.next_billing_date IS NOT NULL
        AND s.next_billing_date < CURDATE()
        AND NOT EXISTS (
            SELECT 1 
            FROM payments p 
            WHERE p.business_card_id = s.business_card_id 
            AND p.payment_status = 'completed'
            AND p.paid_at >= s.next_billing_date
            AND p.payment_type IN ('new_user', 'existing_user')
        )
    ");
    $stmt->execute();
    $overdueSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Found " . count($overdueSubscriptions) . " overdue subscriptions");
    
    foreach ($overdueSubscriptions as $subscription) {
        try {
            $businessCardId = $subscription['business_card_id'];
            $userId = $subscription['user_id'];
            $nextBillingDate = $subscription['next_billing_date'];
            
            // Update payment status to pending for any completed payments
            // This marks them as needing payment again
            $updateStmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'pending', paid_at = NULL
                WHERE business_card_id = ? 
                AND payment_status = 'completed'
                AND payment_type IN ('new_user', 'existing_user')
                ORDER BY paid_at DESC
                LIMIT 1
            ");
            $updateStmt->execute([$businessCardId]);
            
            // Set business card to unpublished
            $updateBcStmt = $db->prepare("
                UPDATE business_cards 
                SET is_published = 0 
                WHERE id = ?
            ");
            $updateBcStmt->execute([$businessCardId]);
            
            // Update subscription status to expired if billing date is more than 30 days past
            $daysOverdue = (strtotime('now') - strtotime($nextBillingDate)) / 86400;
            if ($daysOverdue > 30) {
                $updateSubStmt = $db->prepare("
                    UPDATE subscriptions 
                    SET status = 'expired' 
                    WHERE id = ?
                ");
                $updateSubStmt->execute([$subscription['id']]);
            }
            
            $updatedCount++;
            logMessage("Updated business_card_id {$businessCardId} (user_id: {$userId}) - billing date: {$nextBillingDate}");
            
        } catch (Exception $e) {
            $errors[] = "Error processing subscription {$subscription['id']}: " . $e->getMessage();
            logMessage("ERROR: Subscription {$subscription['id']} - " . $e->getMessage());
        }
    }
    
    // 2. Check new users who haven't paid monthly fees
    // New users should pay monthly fees. If they haven't paid within 30 days of their last payment,
    // mark them as pending
    $stmt = $db->prepare("
        SELECT bc.id as business_card_id, bc.user_id, 
               MAX(p.paid_at) as last_payment_date,
               u.user_type
        FROM business_cards bc
        INNER JOIN users u ON bc.user_id = u.id
        LEFT JOIN payments p ON bc.id = p.business_card_id AND p.payment_status = 'completed'
        WHERE u.user_type IN ('new', 'existing')
        AND bc.is_published = 1
        AND NOT EXISTS (
            SELECT 1 FROM subscriptions s 
            WHERE s.business_card_id = bc.id 
            AND s.status = 'active'
        )
        AND EXISTS (
            SELECT 1 FROM payments p2 
            WHERE p2.business_card_id = bc.id 
            AND p2.payment_type IN ('new_user', 'existing_user')
        )
        GROUP BY bc.id, bc.user_id, u.user_type
        HAVING last_payment_date IS NULL OR last_payment_date < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $overdueUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    logMessage("Found " . count($overdueUsers) . " users with overdue monthly payments");
    
    foreach ($overdueUsers as $user) {
        try {
            $businessCardId = $user['business_card_id'];
            $userId = $user['user_id'];
            
            // Update the most recent completed payment to pending
            $updateStmt = $db->prepare("
                UPDATE payments 
                SET payment_status = 'pending', paid_at = NULL
                WHERE business_card_id = ? 
                AND payment_status = 'completed'
                AND payment_type IN ('new_user', 'existing_user')
                ORDER BY paid_at DESC
                LIMIT 1
            ");
            $updateStmt->execute([$businessCardId]);
            
            // Set business card to unpublished
            $updateBcStmt = $db->prepare("
                UPDATE business_cards 
                SET is_published = 0 
                WHERE id = ?
            ");
            $updateBcStmt->execute([$businessCardId]);
            
            $updatedCount++;
            logMessage("Updated business_card_id {$businessCardId} (user_id: {$userId}, user_type: {$user['user_type']}) - last payment: {$user['last_payment_date']}");
            
        } catch (Exception $e) {
            $errors[] = "Error processing user {$userId}: " . $e->getMessage();
            logMessage("ERROR: User {$userId} - " . $e->getMessage());
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


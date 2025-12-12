<?php
/**
 * Admin API: Manually Trigger Overdue Payment Check
 * 
 * This endpoint allows admins to manually trigger the overdue payment check
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireAdmin(); // 管理者認証
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    $updatedCount = 0;
    $errors = [];
    $updatedBusinessCards = [];
    
    // 1. Check subscriptions with overdue billing dates
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
    
    foreach ($overdueSubscriptions as $subscription) {
        try {
            $businessCardId = $subscription['business_card_id'];
            $userId = $subscription['user_id'];
            $nextBillingDate = $subscription['next_billing_date'];
            
            // Update payment status to pending
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
            $updatedBusinessCards[] = [
                'business_card_id' => $businessCardId,
                'user_id' => $userId,
                'reason' => 'subscription_overdue',
                'billing_date' => $nextBillingDate
            ];
            
        } catch (Exception $e) {
            $errors[] = "Error processing subscription {$subscription['id']}: " . $e->getMessage();
        }
    }
    
    // 2. Check new/existing users who haven't paid monthly fees within 30 days
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
    
    foreach ($overdueUsers as $user) {
        try {
            $businessCardId = $user['business_card_id'];
            $userId = $user['user_id'];
            
            // Skip if already processed in subscription check
            $alreadyProcessed = false;
            foreach ($updatedBusinessCards as $processed) {
                if ($processed['business_card_id'] == $businessCardId) {
                    $alreadyProcessed = true;
                    break;
                }
            }
            
            if ($alreadyProcessed) {
                continue;
            }
            
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
            $updatedBusinessCards[] = [
                'business_card_id' => $businessCardId,
                'user_id' => $userId,
                'reason' => 'monthly_payment_overdue',
                'last_payment_date' => $user['last_payment_date'],
                'user_type' => $user['user_type']
            ];
            
        } catch (Exception $e) {
            $errors[] = "Error processing user {$userId}: " . $e->getMessage();
        }
    }
    
    // Commit transaction
    $db->commit();
    
    sendJsonResponse([
        'success' => true,
        'message' => "延滞支払いの確認が完了しました。{$updatedCount}件を更新しました。",
        'updated_count' => $updatedCount,
        'updated_business_cards' => $updatedBusinessCards,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    sendErrorResponse('Error checking overdue payments: ' . $e->getMessage(), 500);
}


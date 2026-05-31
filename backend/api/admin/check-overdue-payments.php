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
    requireFullAdminAccess();

    $database = new Database();
    $db = $database->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    $updatedCount = 0;
    $errors = [];
    $updatedBusinessCards = [];
    
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
            $updatedBusinessCards[] = [
                'business_card_id' => $businessCardId,
                'user_id' => $userId,
                'reason' => 'subscription_overdue',
                'billing_date' => $nextBillingDate,
                'previous_payment_status' => $subscription['payment_status']
            ];
        } catch (Exception $e) {
            $errors[] = "Error processing subscription {$subscription['id']}: " . $e->getMessage();
        }
    }

    // Commit transaction
    $db->commit();
    
    sendJsonResponse([
        'success' => true,
        'message' => "延滞支払いの確認が完了しました。{$updatedCount}件の入金状況を「未利用」に更新しました。",
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


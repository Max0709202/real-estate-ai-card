<?php
/**
 * Process Email Queue
 * This script should be run via cron job to process queued emails
 * Example cron: 0,15,30,45 * * * * php /path/to/process-email-queue.php
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Maximum emails to process per run (to avoid hitting Gmail limits)
$maxEmailsPerRun = 10;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get pending emails that haven't exceeded max attempts
    $stmt = $db->prepare("
        SELECT id, recipient_email, subject, html_body, text_body, email_type, user_id, related_id, attempts
        FROM email_queue
        WHERE status = 'pending' 
        AND attempts < max_attempts
        ORDER BY created_at ASC
        LIMIT ?
    ");
    $stmt->execute([$maxEmailsPerRun]);
    $queuedEmails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($queuedEmails)) {
        echo "No emails to process.\n";
        exit(0);
    }
    
    echo "Processing " . count($queuedEmails) . " queued emails...\n";
    
    $successCount = 0;
    $failureCount = 0;
    
    foreach ($queuedEmails as $email) {
        // Update status to processing
        $updateStmt = $db->prepare("
            UPDATE email_queue 
            SET status = 'processing', attempts = attempts + 1, last_attempt_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$email['id']]);
        
        // Try to send the email
        $sent = sendEmail(
            $email['recipient_email'],
            $email['subject'],
            $email['html_body'],
            $email['text_body'],
            $email['email_type'],
            $email['user_id'],
            $email['related_id']
        );
        
        if ($sent) {
            // Mark as sent
            $updateStmt = $db->prepare("
                UPDATE email_queue 
                SET status = 'sent', sent_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$email['id']]);
            $successCount++;
            echo "✓ Sent email #{$email['id']} to {$email['recipient_email']}\n";
        } else {
            // Check if it's still a Gmail limit error
            $errorLog = $db->prepare("
                SELECT error_message 
                FROM email_logs 
                WHERE recipient_email = ? 
                AND email_type = ?
                ORDER BY completed_at DESC 
                LIMIT 1
            ");
            $errorLog->execute([$email['recipient_email'], $email['email_type']]);
            $lastError = $errorLog->fetch(PDO::FETCH_ASSOC);
            
            $isGmailLimit = false;
            if ($lastError && $lastError['error_message']) {
                $isGmailLimit = (
                    strpos($lastError['error_message'], 'Daily user sending limit exceeded') !== false ||
                    (strpos($lastError['error_message'], '550') !== false && strpos($lastError['error_message'], '5.4.5') !== false)
                );
            }
            
            if ($isGmailLimit) {
                // Still hitting limit, keep as pending
                $updateStmt = $db->prepare("
                    UPDATE email_queue 
                    SET status = 'pending'
                    WHERE id = ?
                ");
                $updateStmt->execute([$email['id']]);
                echo "⚠ Gmail limit still exceeded for email #{$email['id']}, will retry later\n";
            } else {
                // Different error, mark as failed if max attempts reached
                if ($email['attempts'] + 1 >= 3) {
                    $updateStmt = $db->prepare("
                        UPDATE email_queue 
                        SET status = 'failed', error_message = ?
                        WHERE id = ?
                    ");
                    $errorMsg = $lastError['error_message'] ?? 'Unknown error';
                    $updateStmt->execute([$errorMsg, $email['id']]);
                    echo "✗ Failed email #{$email['id']} after max attempts\n";
                } else {
                    // Retry later
                    $updateStmt = $db->prepare("
                        UPDATE email_queue 
                        SET status = 'pending'
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$email['id']]);
                    echo "⚠ Email #{$email['id']} failed, will retry (attempt " . ($email['attempts'] + 1) . "/3)\n";
                }
            }
            $failureCount++;
        }
        
        // Small delay to avoid overwhelming SMTP server
        usleep(500000); // 0.5 seconds
    }
    
    echo "\nSummary: {$successCount} sent, {$failureCount} failed/retrying\n";
    
} catch (Exception $e) {
    error_log("Email Queue Processor Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}


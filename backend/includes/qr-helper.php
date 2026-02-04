<?php
/**
 * QR Code Helper Functions
 * Helper functions for generating QR codes for business cards
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/config.php';

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generate QR code for a business card
 * 
 * @param int $businessCardId Business card ID
 * @param PDO $db Database connection
 * @return array Result with success status and QR code info
 */
function generateBusinessCardQRCode($businessCardId, $db) {
    try {
        // Get business card info
        $stmt = $db->prepare("
            SELECT bc.id, bc.url_slug, bc.qr_code, bc.qr_code_issued, bc.payment_status,
                   u.email, u.phone_number
            FROM business_cards bc
            JOIN users u ON bc.user_id = u.id
            WHERE bc.id = ?
        ");
        $stmt->execute([$businessCardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$card) {
            return [
                'success' => false,
                'message' => 'Business card not found'
            ];
        }

        // QR code can only be generated if payment_status is CR or BANK_PAID
        if (!in_array($card['payment_status'], ['CR', 'BANK_PAID'])) {
            return [
                'success' => false,
                'message' => 'Payment not confirmed. QR code can only be generated for CR or BANK_PAID status.'
            ];
        }
        
        // Generate QR code URL (card viewing URL)
        $qrUrl = QR_CODE_BASE_URL . $card['url_slug'];
        
        // Create QR codes directory if it doesn't exist
        if (!is_dir(QR_CODE_DIR)) {
            mkdir(QR_CODE_DIR, 0755, true);
        }
        
        // Generate QR code file name
        $qrCodeFileName = 'qr_' . $card['url_slug'] . '_' . time() . '.png';
        $qrCodePath = QR_CODE_DIR . $qrCodeFileName;
        $qrCodeRelativePath = 'uploads/qr_codes/' . $qrCodeFileName;
        
        // Generate QR code using BaconQrCode
        try {
            $renderer = new ImageRenderer(
                new RendererStyle(400, 2), // Size 400x400, margin 2
                new ImagickImageBackEnd()
            );
            $writer = new Writer($renderer);
            $writer->writeFile($qrUrl, $qrCodePath);
        } catch (Exception $e) {
            // Fallback: Try with GD backend if Imagick is not available
            error_log("Imagick not available, trying GD backend: " . $e->getMessage());
            try {
                require_once __DIR__ . '/../vendor/bacon/bacon-qr-code/src/Renderer/Image/SvgImageBackEnd.php';
                $renderer = new ImageRenderer(
                    new RendererStyle(400, 2),
                    new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
                );
                $writer = new Writer($renderer);
                
                // Generate SVG first, then convert to PNG if needed
                $svgContent = $writer->writeString($qrUrl);
                
                // Save as SVG for now (can be converted to PNG later if needed)
                $qrCodeFileName = 'qr_' . $card['url_slug'] . '_' . time() . '.svg';
                $qrCodePath = QR_CODE_DIR . $qrCodeFileName;
                $qrCodeRelativePath = 'uploads/qr_codes/' . $qrCodeFileName;
                file_put_contents($qrCodePath, $svgContent);
            } catch (Exception $e2) {
                error_log("QR Code generation failed: " . $e2->getMessage());
                return [
                    'success' => false,
                    'message' => 'Failed to generate QR code: ' . $e2->getMessage()
                ];
            }
        }
        
        // Update business card with QR code info
        $stmt = $db->prepare("
            UPDATE business_cards 
            SET qr_code = ?, 
                qr_code_issued = 1, 
                qr_code_issued_at = NOW(),
                is_published = 1
            WHERE id = ?
        ");
        $stmt->execute([$qrCodeRelativePath, $businessCardId]);
        
        error_log("QR code generated successfully for business_card_id: {$businessCardId}, path: {$qrCodeRelativePath}");
        
        // Send email notifications
        try {
            // Get user name for email
            $userName = $card['email'] ?? 'お客様';
            if (!empty($card['phone_number'])) {
                // Try to get full name from database
                $stmt = $db->prepare("SELECT name FROM business_cards WHERE id = ?");
                $stmt->execute([$businessCardId]);
                $bcData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($bcData && !empty($bcData['name'])) {
                    $userName = $bcData['name'];
                }
            }
            
            $qrCodeFullUrl = BASE_URL . '/' . $qrCodeRelativePath;
            $paymentAmount = !empty($card['total_amount']) ? $card['total_amount'] : null;
            
            // Get user info for emails first
            $userId = null;
            $companyName = null;
            $name = null;
            $nameRomaji = null;
            $phoneNumber = $card['phone_number'] ?? null;
            $userType = 'new';
            $isEraMember = 0;
            $paymentType = $card['payment_status'] ?? null;
            
            $stmt = $db->prepare("
                SELECT bc.user_id, bc.company_name, bc.name, bc.name_romaji, bc.payment_status,
                       u.user_type, u.is_era_member
                FROM business_cards bc
                JOIN users u ON bc.user_id = u.id
                WHERE bc.id = ?
            ");
            $stmt->execute([$businessCardId]);
            $bcData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($bcData) {
                $userId = $bcData['user_id'];
                $companyName = $bcData['company_name'] ?? null;
                $name = $bcData['name'] ?? null;
                $nameRomaji = $bcData['name_romaji'] ?? null;
                $userType = $bcData['user_type'] ?? 'new';
                $isEraMember = $bcData['is_era_member'] ?? 0;
                $paymentType = $bcData['payment_status'] ?? $paymentType;
            }
            
            // Send email to user
            if (!empty($card['email'])) {
                $userEmailSent = sendQRCodeIssuedEmailToUser(
                    $card['email'],
                    $userName,
                    $qrUrl,
                    $qrCodeFullUrl,
                    $card['url_slug'],
                    $paymentAmount,
                    $userType,
                    $isEraMember,
                    $paymentType
                );
                
                if ($userEmailSent) {
                    error_log("QR code email sent to user: {$card['email']}");
                } else {
                    error_log("Failed to send QR code email to user: {$card['email']}");
                }
            }
            
            // Send admin email (user info already fetched above)
            $adminEmailSent = sendQRCodeIssuedEmailToAdmin(
                $card['email'] ?? 'Unknown',
                $userName,
                $userId ?? 0,
                $card['url_slug'],
                $paymentAmount,
                $companyName,
                $name,
                $nameRomaji,
                $phoneNumber,
                $userType,
                $isEraMember,
                $paymentType
            );
            
            if ($adminEmailSent) {
                error_log("QR code admin notification sent");
            } else {
                error_log("Failed to send QR code admin notification");
            }
            
        } catch (Exception $emailException) {
            // Don't fail the whole operation if email fails
            error_log("Error sending QR code emails: " . $emailException->getMessage());
        }
        
        return [
            'success' => true,
            'qr_code_url' => BASE_URL . '/' . $qrCodeRelativePath,
            'qr_code_path' => $qrCodeRelativePath,
            'business_card_url' => $qrUrl,
            'url_slug' => $card['url_slug']
        ];
        
    } catch (Exception $e) {
        error_log("QR Code generation error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ];
    }
}

/**
 * Check if QR code file exists
 * 
 * @param string $qrCodePath Relative path to QR code
 * @return bool True if file exists
 */
function qrCodeExists($qrCodePath) {
    if (empty($qrCodePath)) {
        return false;
    }
    
    $fullPath = __DIR__ . '/../../' . $qrCodePath;
    return file_exists($fullPath);
}


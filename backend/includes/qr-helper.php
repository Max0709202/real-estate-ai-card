<?php
/**
 * QR Code Helper Functions
 * Helper functions for generating QR codes for business cards
 */

// Composer autoload は functions.php でも読み込むため、ここでは存在する場合のみ読み込む
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/config.php';

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Generate QR code for a business card
 * 
 * @param int $businessCardId Business card ID
 * @param PDO $db Database connection
 * @param bool $sendEmails Whether to send QR issued emails
 * @return array Result with success status and QR code info
 */
function generateBusinessCardQRCode($businessCardId, $db, $sendEmails = true) {
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

        // QR code can only be generated if payment_status is CR, BANK_PAID, or ST
        if (!in_array($card['payment_status'], ['CR', 'BANK_PAID', 'ST'])) {
            return [
                'success' => false,
                'message' => 'Payment not confirmed. QR code can only be generated for CR, BANK_PAID, or ST status.'
            ];
        }
        
        // Generate QR code URL (card viewing URL) from the configured application base URL.
        $base = rtrim(BASE_URL, '/');
        $qrUrl = $base . '/card.php?slug=' . $card['url_slug'];
        
        // Create QR codes directory if it doesn't exist
        if (!is_dir(QR_CODE_DIR)) {
            mkdir(QR_CODE_DIR, 0755, true);
        }
        
        // Generate QR code file name
        $qrCodeFileName = 'qr_' . $card['url_slug'] . '_' . time() . '.png';
        $qrCodePath = QR_CODE_DIR . $qrCodeFileName;
        $qrCodeRelativePath = 'uploads/qr_codes/' . $qrCodeFileName;
        
        // Generate QR code using GD-backed PNG writer. SVG is a final fallback.
        try {
            if (!class_exists(QrCode::class) || !class_exists(PngWriter::class)) {
                return [
                    "success" => false,
                    "message" => "QR code library (Endroid QR Code) is not installed"
                ];
            }

            $qrCode = QrCode::create($qrUrl)
                ->setEncoding(new Encoding("UTF-8"))
                ->setErrorCorrectionLevel(new ErrorCorrectionLevelLow())
                ->setSize(400)
                ->setMargin(16)
                ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin())
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $writer->write($qrCode)->saveToFile($qrCodePath);

            // saveToFile() は file_put_contents のため書き込み失敗時も例外を投げない。
            // ファイルが作られていない状態で発行済みとして記録しないよう検証する。
            if (!is_file($qrCodePath) || filesize($qrCodePath) === 0) {
                throw new RuntimeException("QR code file could not be written: " . $qrCodePath);
            }
        } catch (Throwable $e) {
            error_log("PNG QR code generation failed, trying SVG fallback: " . $e->getMessage());
            try {
                if (!class_exists(SvgWriter::class)) {
                    return [
                        "success" => false,
                        "message" => "QR fallback backend (SvgWriter) is not available"
                    ];
                }

                if (!isset($qrCode)) {
                    $qrCode = QrCode::create($qrUrl)
                        ->setEncoding(new Encoding("UTF-8"))
                        ->setErrorCorrectionLevel(new ErrorCorrectionLevelLow())
                        ->setSize(400)
                        ->setMargin(16)
                        ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin())
                        ->setForegroundColor(new Color(0, 0, 0))
                        ->setBackgroundColor(new Color(255, 255, 255));
                }

                $qrCodeFileName = "qr_" . $card["url_slug"] . "_" . time() . ".svg";
                $qrCodePath = QR_CODE_DIR . $qrCodeFileName;
                $qrCodeRelativePath = "uploads/qr_codes/" . $qrCodeFileName;
                $writer = new SvgWriter();
                $writer->write($qrCode)->saveToFile($qrCodePath);

                if (!is_file($qrCodePath) || filesize($qrCodePath) === 0) {
                    throw new RuntimeException("QR code file could not be written: " . $qrCodePath);
                }
            } catch (Throwable $e2) {
                error_log("QR Code generation failed: " . $e2->getMessage());
                return [
                    "success" => false,
                    "message" => "Failed to generate QR code: " . $e2->getMessage()
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
        
        if ($sendEmails) {
            // Send email notifications
            try {
                // Get user name for email
                $userName = $card["email"] ?? "お客様";
                if (!empty($card["phone_number"])) {
                    // Try to get full name from database
                    $stmt = $db->prepare("SELECT name FROM business_cards WHERE id = ?");
                    $stmt->execute([$businessCardId]);
                    $bcData = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($bcData && !empty($bcData["name"])) {
                        $userName = $bcData["name"];
                    }
                }
                
                $qrCodeFullUrl = rtrim(BASE_URL, "/") . "/backend/" . $qrCodeRelativePath;
                $paymentAmount = !empty($card["total_amount"]) ? $card["total_amount"] : null;
                
                // Get user info for emails first
                $userId = null;
                $companyName = null;
                $name = null;
                $nameRomaji = null;
                $phoneNumber = $card["phone_number"] ?? null;
                $userType = "new";
                $isEraMember = 0;
                $paymentType = $card["payment_status"] ?? null;
                
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
                    $userId = $bcData["user_id"];
                    $companyName = $bcData["company_name"] ?? null;
                    $name = $bcData["name"] ?? null;
                    $nameRomaji = $bcData["name_romaji"] ?? null;
                    $userType = $bcData["user_type"] ?? "new";
                    $isEraMember = $bcData["is_era_member"] ?? 0;
                    $paymentType = $bcData["payment_status"] ?? $paymentType;
                }
                
                // Send email to user
                if (!empty($card["email"])) {
                    $userEmailSent = sendQRCodeIssuedEmailToUser(
                        $card["email"],
                        $userName,
                        $qrUrl,
                        $qrCodeFullUrl,
                        $card["url_slug"],
                        $paymentAmount,
                        $userType,
                        $isEraMember,
                        $paymentType
                    );
                    
                    if ($userEmailSent) {
                        error_log("QR code email sent to user: " . $card["email"]);
                    } else {
                        error_log("Failed to send QR code email to user: " . $card["email"]);
                    }
                }
                
                // Send admin email (user info already fetched above)
                $adminEmailSent = sendQRCodeIssuedEmailToAdmin(
                    $card["email"] ?? "Unknown",
                    $userName,
                    $userId ?? 0,
                    $card["url_slug"],
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
                // Do not fail the whole operation if email fails
                error_log("Error sending QR code emails: " . $emailException->getMessage());
            }
        }
        
        return [
            'success' => true,
            'qr_code_url' => rtrim(BASE_URL, '/') . '/backend/' . $qrCodeRelativePath,
            'qr_code_path' => $qrCodeRelativePath,
            'business_card_url' => $qrUrl,
            'url_slug' => $card['url_slug']
        ];
        
    } catch (Throwable $e) {
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
    
    $fullPath = __DIR__ . '/../' . $qrCodePath;
    return file_exists($fullPath);
}
/**
 * Build the absolute display URL for a stored QR code path
 *
 * @param string $qrCodePath Value stored in business_cards.qr_code
 * @return string Absolute URL (empty string when the path is empty)
 */
function businessCardQRCodeUrl($qrCodePath) {
    $qrCodePath = trim((string) $qrCodePath);
    if ($qrCodePath === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $qrCodePath)) {
        return $qrCodePath;
    }

    $qrCodePath = ltrim($qrCodePath, '/');
    if (strpos($qrCodePath, 'backend/') !== 0) {
        $qrCodePath = 'backend/' . $qrCodePath;
    }

    return rtrim(BASE_URL, '/') . '/' . $qrCodePath;
}

/**
 * Resolve the local file path for a stored QR code path
 *
 * @param string $qrCodePath Value stored in business_cards.qr_code
 * @return string|null Absolute file path, or null when it is not a local file
 */
function businessCardQRCodeFilePath($qrCodePath) {
    $qrCodePath = ltrim(trim((string) $qrCodePath), '/');
    if ($qrCodePath === '' || preg_match('~^https?://~i', $qrCodePath)) {
        return null;
    }

    if (strpos($qrCodePath, 'backend/') === 0) {
        $qrCodePath = substr($qrCodePath, strlen('backend/'));
    }

    return __DIR__ . '/../' . $qrCodePath;
}

/**
 * Generate a QR code image in memory and return it as a data URI
 *
 * 保存先ディレクトリに書き込めない場合でも名刺ページに QR コードを表示するための最終手段。
 *
 * @param string $slug business_cards.url_slug
 * @return string|null Data URI, or null when the image could not be generated
 */
function buildBusinessCardQRCodeDataUri($slug) {
    $slug = trim((string) $slug);
    if ($slug === '') {
        return null;
    }

    try {
        if (!class_exists(QrCode::class)) {
            return null;
        }

        $qrCode = QrCode::create(rtrim(BASE_URL, '/') . '/card.php?slug=' . $slug)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelLow())
            ->setSize(400)
            ->setMargin(16)
            ->setRoundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        try {
            if (class_exists(PngWriter::class)) {
                return (new PngWriter())->write($qrCode)->getDataUri();
            }
        } catch (Throwable $e) {
            error_log('In-memory PNG QR code generation failed, trying SVG fallback: ' . $e->getMessage());
        }

        if (class_exists(SvgWriter::class)) {
            return (new SvgWriter())->write($qrCode)->getDataUri();
        }
    } catch (Throwable $e) {
        error_log('In-memory QR code generation failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Resolve the QR code image to show on the public business card page
 *
 * 入金確認時の QR 発行が失敗すると qr_code_issued が 0 のまま残り、その後どの処理でも
 * 再発行されないため名刺ページに QR コードが表示されなくなる。画像ファイルが消えている
 * 場合も同様。表示時にここで発行し直すことで、公開中の名刺には必ず QR コードを表示する。
 *
 * @param array $card business_cards row (id, url_slug, qr_code, payment_status)
 * @param PDO $db Database connection
 * @return string|null Image src (absolute URL or data URI), or null when unavailable
 */
function resolveBusinessCardQRCodeSrc(array $card, $db) {
    // 1. 発行済みでファイルも存在する場合はそのまま使う
    $storedPath = trim((string) ($card['qr_code'] ?? ''));
    if ($storedPath !== '') {
        if (preg_match('~^https?://~i', $storedPath)) {
            return $storedPath;
        }

        $filePath = businessCardQRCodeFilePath($storedPath);
        if ($filePath !== null && is_file($filePath)) {
            return businessCardQRCodeUrl($storedPath);
        }
    }

    // 2. 入金確認済み（CR/BANK_PAID/ST）の名刺のみ再発行の対象とする
    if (empty($card['id']) || !in_array($card['payment_status'] ?? '', ['CR', 'BANK_PAID', 'ST'], true)) {
        return null;
    }

    // 3. 未発行・ファイル欠損の場合はこの場で発行し、DB にも保存する（メールは送らない）
    try {
        $result = generateBusinessCardQRCode($card['id'], $db, false);
        if (!empty($result['success']) && !empty($result['qr_code_path'])) {
            $newFilePath = businessCardQRCodeFilePath($result['qr_code_path']);
            if ($newFilePath !== null && is_file($newFilePath)) {
                return businessCardQRCodeUrl($result['qr_code_path']);
            }
            error_log('QR code re-issue on card page saved no file for business_card_id ' . $card['id'] . ': ' . $result['qr_code_path']);
        } else {
            error_log('QR code re-issue on card page failed for business_card_id ' . $card['id'] . ': ' . ($result['message'] ?? 'Unknown error'));
        }
    } catch (Throwable $e) {
        error_log('QR code re-issue on card page error for business_card_id ' . $card['id'] . ': ' . $e->getMessage());
    }

    // 4. 保存に失敗した場合でも、生成した画像を直接埋め込んで表示する
    return buildBusinessCardQRCodeDataUri($card['url_slug'] ?? '');
}

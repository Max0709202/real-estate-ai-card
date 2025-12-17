<?php
/**
 * Import CSV for Email Invitations
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }
    
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        sendErrorResponse('CSVファイルがアップロードされていません', 400);
    }
    
    $file = $_FILES['csv_file'];
    
    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])) {
        sendErrorResponse('CSVファイルをアップロードしてください', 400);
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $adminId = $_SESSION['admin_id'];
    
    // Read CSV file
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        sendErrorResponse('CSVファイルの読み込みに失敗しました', 500);
    }
    
    // Skip BOM if present
    $firstLine = fgets($handle);
    rewind($handle);
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
        fseek($handle, 3);
    }
    
    $imported = 0;
    $skipped = 0;
    $updated = 0;
    $errors = [];
    $lineNumber = 0;
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $lineNumber++;
            
            // Skip header row (assume first row is header)
            if ($lineNumber === 1) {
                continue;
            }
            
            // Skip empty rows
            if (empty(array_filter($data))) {
                continue;
            }
            
            // Expected format: username, email
            $username = trim($data[0] ?? '');
            $email = trim($data[1] ?? '');
            
            // If first column looks like email, swap
            if (filter_var($username, FILTER_VALIDATE_EMAIL) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $temp = $username;
                $username = $email;
                $email = $temp;
            }
            
            // Validate email
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "行 {$lineNumber}: 無効なメールアドレス - " . htmlspecialchars($email);
                $skipped++;
                continue;
            }
            
            // Check if email already exists
            $stmt = $db->prepare("SELECT id, username FROM email_invitations WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update username if provided
                if (!empty($username) && $username !== $existing['username']) {
                    $stmt = $db->prepare("UPDATE email_invitations SET username = ?, updated_at = NOW() WHERE email = ?");
                    $stmt->execute([$username, $email]);
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }
            
            // Insert new record
            $stmt = $db->prepare("
                INSERT INTO email_invitations (username, email, imported_by, role_type, email_sent)
                VALUES (?, ?, ?, 'new', 0)
            ");
            $stmt->execute([$username ?: null, $email, $adminId]);
            $imported++;
        }
        
        $db->commit();
        fclose($handle);
        
        // Log admin change
        logAdminChange($db, $adminId, $_SESSION['admin_email'] ?? '', 'other', 'email_invitations', null, "CSVインポート: {$imported}件登録, {$updated}件更新, {$skipped}件スキップ");
        
        sendSuccessResponse([
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors
        ], "{$imported}件の連絡先をインポートしました");
        
    } catch (Exception $e) {
        $db->rollBack();
        fclose($handle);
        error_log("CSV Import Error: " . $e->getMessage());
        sendErrorResponse('インポート中にエラーが発生しました: ' . $e->getMessage(), 500);
    }
    
} catch (Exception $e) {
    error_log("CSV Import Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}


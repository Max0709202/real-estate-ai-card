<?php
/**
 * Update Business Card API (Optimized Version)
 */
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    // Read JSON input
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    if (!$input) {
        $input = $_POST;
    }

    $db = (new Database())->getConnection();

    // Fetch business card
    $stmt = $db->prepare("SELECT id FROM business_cards WHERE user_id = ?");
    $stmt->execute([$userId]);
    $businessCard = $stmt->fetch();

    if (!$businessCard) {
        sendErrorResponse('Business card not found', 404);
    }

    $bcId = $businessCard['id'];

    // Start transaction
    $db->beginTransaction();

    /**
     * =============== BUSINESS CARD FIELD UPDATE ===============
     */

    $fields = [
        'company_name', 'company_logo', 'profile_photo',
        'real_estate_license_prefecture', 'real_estate_license_renewal_number',
        'real_estate_license_registration_number', 'company_postal_code',
        'company_address', 'company_phone', 'company_website',
        'branch_department', 'position', 'name', 'name_romaji',
        'mobile_phone', 'birth_date', 'current_residence', 'hometown',
        'alma_mater', 'qualifications', 'hobbies', 'free_input'
    ];

    $updateFields = [];
    $updateValues = [];

    foreach ($fields as $field) {
        if (!array_key_exists($field, $input)) continue;

        $value = $input[$field];

        // 🔥 FIX: If image is accidentally sent as array → convert to string
        if (in_array($field, ['profile_photo', 'company_logo']) && is_array($value)) {
            $value = $value[0] ?? null;
        }

        // Convert blanks to NULL (except required fields)
        if ($value === '' || $value === null) {
            if (in_array($field, ['name', 'mobile_phone'])) {
                continue;
            }
            $updateFields[] = "$field = NULL";
            continue;
        }

        // free_input: JSON allowed
        if ($field === 'free_input' && json_decode($value, true) !== null) {
            $updateFields[] = "$field = ?";
            $updateValues[] = $value;
            continue;
        }

        // For text fields, trim whitespace but preserve the exact input (don't add periods)
        if (is_string($value)) {
            $value = trim($value);
        }

        $updateFields[] = "$field = ?";
        $updateValues[] = sanitizeInput($value);
    }

    if (!empty($updateFields)) {
        $sql = "UPDATE business_cards SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateValues[] = $bcId;

        $stmt = $db->prepare($sql);
        $stmt->execute($updateValues);
    }

    /**
     * =============== GREETING MESSAGES UPDATE ===============
     */
    if (isset($input['greetings']) && is_array($input['greetings'])) {
        $db->prepare("DELETE FROM greeting_messages WHERE business_card_id = ?")
           ->execute([$bcId]);

        $stmt = $db->prepare("
            INSERT INTO greeting_messages (business_card_id, title, content, display_order)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($input['greetings'] as $order => $g) {
            if (empty($g['title']) && empty($g['content'])) continue;

            $stmt->execute([
                $bcId,
                sanitizeInput($g['title'] ?? ''),
                sanitizeInput($g['content'] ?? ''),
                (int)$order
            ]);
        }
    }

    /**
     * =============== TECH TOOLS UPDATE ===============
     */
    if (isset($input['tech_tools']) && is_array($input['tech_tools'])) {
        $db->prepare("DELETE FROM tech_tool_selections WHERE business_card_id = ?")
           ->execute([$bcId]);

        $stmt = $db->prepare("
            INSERT INTO tech_tool_selections (business_card_id, tool_type, tool_url, display_order, is_active)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($input['tech_tools'] as $order => $tool) {
            if (empty($tool['tool_type'])) continue;

            $stmt->execute([
                $bcId,
                sanitizeInput($tool['tool_type']),
                sanitizeInput($tool['tool_url'] ?? ''),
                (int)$order,
                isset($tool['is_active']) ? (int)$tool['is_active'] : 1
            ]);
        }
    }

    /**
     * =============== COMMUNICATION TOOLS UPDATE ===============
     */
    if (isset($input['communication_methods']) && is_array($input['communication_methods'])) {
        $db->prepare("DELETE FROM communication_methods WHERE business_card_id = ?")
           ->execute([$bcId]);

        $stmt = $db->prepare("
            INSERT INTO communication_methods (business_card_id, method_type, method_name, method_url, method_id, is_active, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($input['communication_methods'] as $order => $method) {
            if (empty($method['method_type'])) continue;

            $stmt->execute([
                $bcId,
                sanitizeInput($method['method_type']),
                sanitizeInput($method['method_name'] ?? ''),
                sanitizeInput($method['method_url'] ?? ''),
                sanitizeInput($method['method_id'] ?? ''),
                isset($method['is_active']) ? (int)$method['is_active'] : 1,
                (int)$order
            ]);
        }
    }

    /**
     * Commit transaction
     */
    $db->commit();

    sendSuccessResponse(['business_card_id' => $bcId], 'Business card updated successfully.');

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Update Error: " . $e->getMessage());

    sendErrorResponse(
        ENVIRONMENT === 'development' ? $e->getMessage() : 'サーバーエラーが発生しました',
        500
    );
}


<?php
/**
 * Autosave Draft Business Card API
 * Allows saving card data as draft even if payment is not completed
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    // Get or create business card
    $stmt = $db->prepare("SELECT id FROM business_cards WHERE user_id = ?");
    $stmt->execute([$userId]);
    $businessCard = $stmt->fetch();

    $bcId = null;
    if ($businessCard) {
        $bcId = $businessCard['id'];
    } else {
        // Create new business card if doesn't exist
        $urlSlug = 'user_' . $userId . '_' . time();
        $stmt = $db->prepare("
            INSERT INTO business_cards (user_id, url_slug, name, mobile_phone, card_status)
            VALUES (?, ?, 'Draft', '00000000000', 'draft')
        ");
        $stmt->execute([$userId, $urlSlug]);
        $bcId = $db->lastInsertId();
    }

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

        // If image is accidentally sent as array → convert to string
        if (in_array($field, ['profile_photo', 'company_logo']) && is_array($value)) {
            $value = $value[0] ?? null;
        }

        // IMPORTANT: Skip image fields if empty to prevent overwriting existing images
        // This prevents the autosave from clearing images when the beforeunload popup is triggered
        if (in_array($field, ['profile_photo', 'company_logo'])) {
            if ($value === '' || $value === null) {
                continue; // Preserve existing image
            }
        }

        // Convert blanks to NULL (except required fields and image fields)
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

        $updateFields[] = "$field = ?";
        $updateValues[] = sanitizeInput($value);
    }

    // Always set card_status to 'draft' for autosave
    $updateFields[] = "card_status = 'draft'";
    $updateFields[] = "updated_at = NOW()";

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

    $db->commit();

    sendSuccessResponse([
        'business_card_id' => $bcId,
        'status' => 'draft'
    ], 'ドラフトが保存されました');

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Autosave Error: " . $e->getMessage());
    sendErrorResponse('ドラフトの保存に失敗しました', 500);
}


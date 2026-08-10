<?php
/**
 * Update Business Card API (Optimized Version)
 */
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
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

    // 組織階層：免許番号（宅建業者番号）が「初めて」入力された保存かどうかを判定するため、
    // 更新前の値を控えておく。毎回の保存で統括の指名が動かないようにするための条件。
    $licenseKeyBefore = '';
    try {
        $stmt = $db->prepare('
            SELECT real_estate_license_prefecture, real_estate_license_registration_number
            FROM business_cards WHERE id = ? LIMIT 1
        ');
        $stmt->execute([$bcId]);
        $licenseRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $licenseKeyBefore = orgLicenseParts(
            $licenseRow['real_estate_license_prefecture'] ?? '',
            $licenseRow['real_estate_license_registration_number'] ?? ''
        )['key'];
    } catch (Exception $e) {
        error_log('Update Error (license before): ' . $e->getMessage());
    }

    // Start transaction
    $db->beginTransaction();

    /**
     * =============== BUSINESS CARD FIELD UPDATE ===============
     */

    $fields = [
        'company_name', 'company_logo', 'flyer_band', 'profile_photo', 'card_header_bg',
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
        if (in_array($field, ['profile_photo', 'company_logo', 'card_header_bg']) && is_array($value)) {
            $value = $value[0] ?? null;
        }

        // IMPORTANT: Skip image fields if empty to prevent overwriting existing images
        // This prevents clearing images when the beforeunload popup is triggered
        if (in_array($field, ['profile_photo', 'company_logo', 'card_header_bg'])) {
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

    /**
     * =============== 組織階層：最初の登録者を統括（全閲覧）にする ===============
     * 「その免許番号で最初に登録した方が統括、以降の方は担当者（営業）」という運用のため、
     * 免許番号が未入力から入力された保存のときだけ判定する。
     * すでに同じ免許番号に統括がいる場合や、権限・上長が設定済みの方は変更しない。
     */
    if ($licenseKeyBefore === '') {
        try {
            orgAutoAssignFirstAdmin($db, (int)$userId);
        } catch (Exception $e) {
            // 名刺の保存は完了しているため、ここでの失敗はログのみに留める。
            error_log('orgAutoAssignFirstAdmin error: ' . $e->getMessage());
        }
    }

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


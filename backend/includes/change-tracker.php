<?php
/**
 * Change Tracking Helper Functions
 */
require_once __DIR__ . '/../config/database.php';

/**
 * Track a change to a business card
 * @param int $businessCardId
 * @param string $changeType 'create', 'update', 'delete'
 * @param string $fieldName Field name (optional)
 * @param mixed $oldValue Old value (optional)
 * @param mixed $newValue New value (optional)
 * @return bool
 */
function trackBusinessCardChange($businessCardId, $changeType, $fieldName = null, $oldValue = null, $newValue = null) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Determine who made the change
        $changedByType = 'user';
        $changedById = 0;
        $changedByEmail = 'system';
        
        if (!empty($_SESSION['admin_id'])) {
            $changedByType = $_SESSION['admin_role'] ?? 'client';
            $changedById = $_SESSION['admin_id'];
            $changedByEmail = $_SESSION['admin_email'] ?? 'unknown';
        } elseif (!empty($_SESSION['user_id'])) {
            $changedByType = 'user';
            $changedById = $_SESSION['user_id'];
            // Get user email
            $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $changedByEmail = $user['email'] ?? 'unknown';
        }
        
        // Convert values to string for storage
        $oldValueStr = is_array($oldValue) ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : (string)$oldValue;
        $newValueStr = is_array($newValue) ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : (string)$newValue;
        
        // Limit length
        $oldValueStr = mb_substr($oldValueStr, 0, 1000);
        $newValueStr = mb_substr($newValueStr, 0, 1000);
        
        $stmt = $db->prepare("
            INSERT INTO business_card_changes 
            (business_card_id, changed_by_type, changed_by_id, changed_by_email, change_type, field_name, old_value, new_value)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $businessCardId,
            $changedByType,
            $changedById,
            $changedByEmail,
            $changeType,
            $fieldName,
            $oldValueStr ?: null,
            $newValueStr ?: null
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Change tracking error: " . $e->getMessage());
        return false;
    }
}

/**
 * Track multiple field changes at once
 * @param int $businessCardId
 * @param array $changes Array of ['field' => field_name, 'old' => old_value, 'new' => new_value]
 * @return bool
 */
function trackBusinessCardChanges($businessCardId, $changes) {
    foreach ($changes as $change) {
        trackBusinessCardChange(
            $businessCardId,
            'update',
            $change['field'] ?? null,
            $change['old'] ?? null,
            $change['new'] ?? null
        );
    }
    return true;
}




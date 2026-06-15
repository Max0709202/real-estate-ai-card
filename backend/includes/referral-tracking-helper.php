<?php
/**
 * Referral / UTM tracking helpers.
 */

function referralTrackingFields() {
    return ['agent', 'utm_source', 'utm_medium', 'utm_campaign', 'first_accessed_at'];
}

function referralTrackingColumnDefinitions() {
    return [
        'agent' => "VARCHAR(255) NULL",
        'utm_source' => "VARCHAR(255) NULL",
        'utm_medium' => "VARCHAR(255) NULL",
        'utm_campaign' => "VARCHAR(255) NULL",
        'first_accessed_at' => "DATETIME NULL",
    ];
}

function referralTrackingNormalizeValue($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    return mb_substr($value, 0, 255);
}

function referralTrackingNormalizeDate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        $date = new DateTime($value);
        return $date->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function referralTrackingFromInput($input) {
    if (!is_array($input)) return [];
    $source = [];
    if (isset($input['referral_tracking']) && is_array($input['referral_tracking'])) {
        $source = $input['referral_tracking'];
    } else {
        $source = $input;
    }

    $data = [
        'agent' => referralTrackingNormalizeValue($source['agent'] ?? null),
        'utm_source' => referralTrackingNormalizeValue($source['utm_source'] ?? null),
        'utm_medium' => referralTrackingNormalizeValue($source['utm_medium'] ?? null),
        'utm_campaign' => referralTrackingNormalizeValue($source['utm_campaign'] ?? null),
        'first_accessed_at' => referralTrackingNormalizeDate($source['first_accessed_at'] ?? null),
    ];

    return array_filter($data, function ($value) {
        return $value !== null && $value !== '';
    });
}

function ensureReferralTrackingColumns($db, $tables = ['users', 'payments', 'subscriptions']) {
    if (!$db instanceof PDO) return;
    $defs = referralTrackingColumnDefinitions();
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) continue;
        try {
            $stmt = $db->prepare("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
            foreach ($defs as $column => $definition) {
                if (isset($existing[$column])) continue;
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (Throwable $e) {
            error_log("Referral tracking column ensure failed for {$table}: " . $e->getMessage());
        }
    }
}

function referralTrackingForSql($data) {
    $values = [];
    foreach (referralTrackingFields() as $field) {
        $values[$field] = $data[$field] ?? null;
    }
    return $values;
}

function referralTrackingFetchForUser($db, $userId) {
    ensureReferralTrackingColumns($db, ['users']);
    $stmt = $db->prepare("SELECT agent, utm_source, utm_medium, utm_campaign, first_accessed_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function referralTrackingMerge($primary, $fallback) {
    $merged = [];
    foreach (referralTrackingFields() as $field) {
        $merged[$field] = $primary[$field] ?? ($fallback[$field] ?? null);
    }
    return $merged;
}

function referralTrackingMetadata($data) {
    $metadata = [];
    foreach (referralTrackingFields() as $field) {
        if (!empty($data[$field])) $metadata[$field] = (string)$data[$field];
    }
    return $metadata;
}

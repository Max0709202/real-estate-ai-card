<?php
/**
 * Firebase phone authentication and verified chat phone helpers.
 */

function ensureChatVerifiedPhonesTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS chat_verified_phones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_card_id INT NOT NULL,
        phone_e164 VARCHAR(32) NOT NULL,
        phone_normalized VARCHAR(32) NOT NULL,
        display_phone VARCHAR(50) NULL,
        firebase_uid VARCHAR(128) NULL,
        customer_name VARCHAR(255) NULL,
        last_session_id CHAR(36) NULL,
        first_verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_chat_verified_phone_card_phone (business_card_id, phone_normalized),
        INDEX idx_chat_verified_phone_card (business_card_id),
        INDEX idx_chat_verified_phone_session (last_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function chatNormalizePhoneDigits($phone) {
    $phone = mb_convert_kana((string)$phone, 'n');
    return preg_replace('/\D+/', '', $phone);
}

function chatPhoneLookupKey($phone) {
    $digits = chatNormalizePhoneDigits($phone);
    if ($digits === '') return '';
    if (strpos($digits, '81') === 0 && strlen($digits) >= 11) {
        return '0' . substr($digits, 2);
    }
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
        return substr($digits, 1);
    }
    return $digits;
}

function chatPhoneToE164Japan($phone) {
    $raw = trim((string)$phone);
    if ($raw === '') return '';
    if (strpos($raw, '+') === 0) {
        return '+' . chatNormalizePhoneDigits($raw);
    }
    $digits = chatNormalizePhoneDigits($raw);
    if ($digits === '') return '';
    if (strpos($digits, '81') === 0) return '+' . $digits;
    if (strpos($digits, '0') === 0) return '+81' . substr($digits, 1);
    if (strlen($digits) === 10 && preg_match('/^[2-9]/', $digits)) return '+1' . $digits;
    if (strlen($digits) === 11 && strpos($digits, '1') === 0) return '+' . $digits;
    return '+' . $digits;
}

function chatCleanCustomerNameValue($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    return mb_strlen($name) <= 255 ? $name : mb_substr($name, 0, 255);
}

function chatCustomerNameFromStructuredData($structuredData) {
    if (is_string($structuredData)) {
        $decoded = json_decode($structuredData, true);
        $structuredData = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($structuredData)) return '';

    $name = chatCleanCustomerNameValue($structuredData['customer_name'] ?? ($structuredData['customerName'] ?? ''));
    if ($name !== '') return $name;

    $lastName = chatCleanCustomerNameValue($structuredData['customer_last_name'] ?? '');
    $firstName = chatCleanCustomerNameValue($structuredData['customer_first_name'] ?? '');
    return chatCleanCustomerNameValue(trim($lastName . ' ' . $firstName));
}

function chatResolveCustomerNameForSession($db, $sessionId, $businessCardId = null) {
    $sessionId = trim((string)$sessionId);
    if ($sessionId === '') return '';
    $businessCardId = $businessCardId !== null ? (int)$businessCardId : null;

    if (function_exists('ensureChatLeadContactTable')) {
        try {
            ensureChatLeadContactTable($db);
            $sql = "SELECT customer_name FROM chat_lead_contacts WHERE session_id = ? AND customer_name IS NOT NULL AND customer_name <> ''";
            $params = [$sessionId];
            if ($businessCardId) {
                $sql .= " AND business_card_id = ?";
                $params[] = $businessCardId;
            }
            $sql .= " ORDER BY updated_at DESC LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $name = chatCleanCustomerNameValue($stmt->fetchColumn() ?: '');
            if ($name !== '') return $name;
        } catch (Throwable $e) {
        }
    }

    try {
        $sql = "SELECT structured_data FROM chat_leads WHERE session_id = ?";
        $params = [$sessionId];
        if ($businessCardId) {
            $sql .= " AND business_card_id = ?";
            $params[] = $businessCardId;
        }
        $sql .= " LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $name = chatCustomerNameFromStructuredData($stmt->fetchColumn() ?: '');
        if ($name !== '') return $name;
    } catch (Throwable $e) {
    }

    try {
        ensureChatVerifiedPhonesTable($db);
        $sql = "SELECT customer_name FROM chat_verified_phones WHERE last_session_id = ? AND customer_name IS NOT NULL AND customer_name <> ''";
        $params = [$sessionId];
        if ($businessCardId) {
            $sql .= " AND business_card_id = ?";
            $params[] = $businessCardId;
        }
        $sql .= " ORDER BY last_verified_at DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $name = chatCleanCustomerNameValue($stmt->fetchColumn() ?: '');
        if ($name !== '') return $name;
    } catch (Throwable $e) {
    }

    return '';
}

function chatFirebaseConfigPayload() {
    return [
        'apiKey' => defined('FIREBASE_API_KEY') ? FIREBASE_API_KEY : '',
        'authDomain' => defined('FIREBASE_AUTH_DOMAIN') ? FIREBASE_AUTH_DOMAIN : '',
        'projectId' => defined('FIREBASE_PROJECT_ID') ? FIREBASE_PROJECT_ID : '',
        'appId' => defined('FIREBASE_APP_ID') ? FIREBASE_APP_ID : '',
    ];
}

function chatFirebaseConfigured() {
    $config = chatFirebaseConfigPayload();
    return $config['apiKey'] !== '' && $config['authDomain'] !== '' && $config['projectId'] !== '' && $config['appId'] !== '';
}

function chatFirebaseLookupIdToken($idToken) {
    $apiKey = defined('FIREBASE_API_KEY') ? FIREBASE_API_KEY : '';
    if ($apiKey === '') {
        return ['error' => 'Firebase API key is not configured.'];
    }
    $payload = json_encode(['idToken' => $idToken]);
    $ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlError = $response === false ? curl_error($ch) : '';
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['error' => $curlError !== '' ? $curlError : 'Firebase token lookup failed.'];
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['users'][0])) {
        $message = $data['error']['message'] ?? ('Firebase token lookup failed. HTTP ' . $httpCode);
        return ['error' => $message];
    }
    return ['user' => $data['users'][0]];
}

function chatFindSessionByVerifiedPhone($db, $businessCardId, $phone) {
    $lookup = chatPhoneLookupKey($phone);
    if ($lookup === '') return null;

    ensureChatVerifiedPhonesTable($db);
    if (function_exists('ensureChatLeadContactTable')) ensureChatLeadContactTable($db);
    $stmt = $db->prepare("SELECT cvp.last_session_id AS session_id, cvp.customer_name, cs.last_seen_at, cs.created_at
        FROM chat_verified_phones cvp
        LEFT JOIN chat_sessions cs ON cs.id = cvp.last_session_id
        WHERE cvp.business_card_id = ? AND cvp.phone_normalized = ?
        LIMIT 1");
    $stmt->execute([$businessCardId, $lookup]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['session_id'])) {
        $resolvedName = chatResolveCustomerNameForSession($db, $row['session_id'], $businessCardId);
        if ($resolvedName !== '') $row['customer_name'] = $resolvedName;
        return $row;
    }

    $stmt = $db->prepare("SELECT cc.session_id, cc.customer_name, cs.last_seen_at, cs.created_at
        FROM chat_lead_contacts cc
        JOIN chat_sessions cs ON cs.id = cc.session_id
        WHERE cc.business_card_id = ? AND REPLACE(REPLACE(REPLACE(REPLACE(cc.phone, '-', ''), ' ', ''), '　', ''), '+81', '0') = ?
        ORDER BY cs.last_seen_at DESC, cc.updated_at DESC
        LIMIT 1");
    $stmt->execute([$businessCardId, $lookup]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $resolvedName = chatResolveCustomerNameForSession($db, $row['session_id'], $businessCardId);
        if ($resolvedName !== '') $row['customer_name'] = $resolvedName;
    }
    return $row ?: null;
}

function chatRegisterVerifiedPhone($db, $businessCardId, $phone, $firebaseUid = '', $sessionId = null, $customerName = null) {
    $lookup = chatPhoneLookupKey($phone);
    if ($lookup === '') return;
    ensureChatVerifiedPhonesTable($db);
    $e164 = chatPhoneToE164Japan($phone);
    $stmt = $db->prepare("INSERT INTO chat_verified_phones
        (business_card_id, phone_e164, phone_normalized, display_phone, firebase_uid, customer_name, last_session_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            phone_e164 = VALUES(phone_e164),
            display_phone = VALUES(display_phone),
            firebase_uid = COALESCE(NULLIF(VALUES(firebase_uid), ''), firebase_uid),
            customer_name = COALESCE(NULLIF(VALUES(customer_name), ''), customer_name),
            last_session_id = COALESCE(VALUES(last_session_id), last_session_id),
            last_verified_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$businessCardId, $e164, $lookup, $phone, $firebaseUid, $customerName, $sessionId]);
}

function chatCreateSessionForVerifiedPhone($db, $businessCardId, $visitorId = '') {
    $sessionId = generateChatSessionId();
    $stmt = $db->prepare("INSERT INTO chat_sessions (id, business_card_id, visitor_identifier) VALUES (?, ?, ?)");
    $stmt->execute([$sessionId, $businessCardId, $visitorId !== '' ? $visitorId : null]);
    return $sessionId;
}

function chatRegisteredPhonesForCard($db, $businessCardId, $limit = 50) {
    ensureChatVerifiedPhonesTable($db);
    ensureChatLeadContactTable($db);
    $limit = max(1, min(200, (int)$limit));
    $stmt = $db->prepare("SELECT phone_e164, phone_normalized, display_phone, customer_name, last_session_id, last_verified_at
        FROM chat_verified_phones
        WHERE business_card_id = ?
        ORDER BY last_verified_at DESC
        LIMIT {$limit}");
    $stmt->execute([$businessCardId]);
    $phones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT cc.phone, cc.customer_name, cc.session_id, cc.updated_at
        FROM chat_lead_contacts cc
        WHERE cc.business_card_id = ? AND cc.phone IS NOT NULL AND cc.phone <> ''
        ORDER BY cc.updated_at DESC
        LIMIT {$limit}");
    $stmt->execute([$businessCardId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lookup = chatPhoneLookupKey($row['phone'] ?? '');
        if ($lookup === '') continue;
        $exists = false;
        foreach ($phones as $phone) {
            if (($phone['phone_normalized'] ?? '') === $lookup) {
                $exists = true;
                break;
            }
        }
        if ($exists) continue;
        $phones[] = [
            'phone_e164' => chatPhoneToE164Japan($row['phone']),
            'phone_normalized' => $lookup,
            'display_phone' => $row['phone'],
            'customer_name' => $row['customer_name'],
            'last_session_id' => $row['session_id'],
            'last_verified_at' => $row['updated_at'],
        ];
    }

    return array_slice($phones, 0, $limit);
}

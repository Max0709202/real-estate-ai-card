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

/**
 * 同一セッションを共有できる「SMS認証済み端末」の集合を保持するテーブル。
 * chat_sessions.visitor_identifier は単一所有者しか持てず、同じ電話番号で複数端末
 * （PC・スマホ等）からアクセスすると最後に認証した端末以外が 403 になる。
 * このテーブルに認証済み端末の visitor_id を登録し、認可判定で参照することで、
 * 同一電話番号の全端末が同じ相談内容（条件整理・進捗・物件選定・担当連絡等）を共有できる。
 */
function ensureChatSessionDevicesTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS chat_session_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id CHAR(36) NOT NULL,
        visitor_identifier VARCHAR(128) NOT NULL,
        phone_normalized VARCHAR(32) NULL,
        customer_name VARCHAR(255) NULL,
        verified_until DATETIME NULL,
        first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_chat_session_device (session_id, visitor_identifier),
        INDEX idx_chat_session_device_session (session_id),
        INDEX idx_chat_session_device_verified (session_id, visitor_identifier, verified_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'phone_normalized' => "ALTER TABLE chat_session_devices ADD COLUMN phone_normalized VARCHAR(32) NULL AFTER visitor_identifier",
        'customer_name' => "ALTER TABLE chat_session_devices ADD COLUMN customer_name VARCHAR(255) NULL AFTER phone_normalized",
        'verified_until' => "ALTER TABLE chat_session_devices ADD COLUMN verified_until DATETIME NULL AFTER customer_name",
    ];
    // NOTE: `SHOW COLUMNS ... LIKE ?` / `SHOW INDEX ... WHERE ... = ?` throw under native
    // prepares (PDO::ATTR_EMULATE_PREPARES=false, see database.php) on MariaDB, which used to
    // make these migrations fail silently on environments where the table already existed in an
    // older shape. Introspect with a plain SHOW and compare in PHP instead of binding placeholders.
    $existingColumns = [];
    try {
        foreach ($db->query("SHOW COLUMNS FROM chat_session_devices") as $row) {
            $existingColumns[$row['Field']] = true;
        }
    } catch (Throwable $e) {
        error_log('chat_session_devices column introspection failed: ' . $e->getMessage());
    }
    foreach ($columns as $column => $sql) {
        if (isset($existingColumns[$column])) {
            continue;
        }
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            error_log('chat_session_devices schema update failed for ' . $column . ': ' . $e->getMessage());
        }
    }

    $existingIndexes = [];
    try {
        foreach ($db->query("SHOW INDEX FROM chat_session_devices") as $row) {
            $existingIndexes[$row['Key_name']] = true;
        }
    } catch (Throwable $e) {
        error_log('chat_session_devices index introspection failed: ' . $e->getMessage());
    }
    if (!isset($existingIndexes['idx_chat_session_device_verified'])) {
        try {
            $db->exec("ALTER TABLE chat_session_devices ADD INDEX idx_chat_session_device_verified (session_id, visitor_identifier, verified_until)");
        } catch (Throwable $e) {
            error_log('chat_session_devices index update failed: ' . $e->getMessage());
        }
    }
}

function chatIsValidVisitorId($visitorId) {
    return is_string($visitorId) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId);
}

/**
 * SMS認証（または登録済み電話番号の照合）を通過した端末を、当該セッションの
 * 認可済み端末として登録する。既にセッションに単独所有者（visitor_identifier）が
 * 記録されていれば、その所有者もあわせて集合へ引き継ぐ（本機能導入前のセッション救済）。
 */
function chatSessionRegisterDevice($db, $sessionId, $visitorId, $phone = '', $customerName = '', $ttlSeconds = 10800) {
    $sessionId = trim((string)$sessionId);
    $visitorId = trim((string)$visitorId);
    if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) return;
    if (!chatIsValidVisitorId($visitorId)) return;
    try {
        ensureChatSessionDevicesTable($db);
        $phoneLookup = chatPhoneLookupKey($phone);
        $cleanName = chatCleanCustomerNameValue($customerName);
        $ttlSeconds = max(60, (int)$ttlSeconds);
        $stmt = $db->query("SELECT DATE_ADD(NOW(), INTERVAL {$ttlSeconds} SECOND)");
        $verifiedUntil = (string)($stmt ? $stmt->fetchColumn() : '');
        if ($verifiedUntil === '') return;
        $stmt = $db->prepare("INSERT INTO chat_session_devices (session_id, visitor_identifier, phone_normalized, customer_name, verified_until)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE last_seen_at = CURRENT_TIMESTAMP");
        $stmt->execute([$sessionId, $visitorId, $phoneLookup !== '' ? $phoneLookup : null, $cleanName !== '' ? $cleanName : null, $verifiedUntil]);

        $updates = ["last_seen_at = CURRENT_TIMESTAMP"];
        $params = [];
        if ($phoneLookup !== '') {
            $updates[] = "phone_normalized = ?";
            $params[] = $phoneLookup;
        }
        if ($cleanName !== '') {
            $updates[] = "customer_name = ?";
            $params[] = $cleanName;
        }
        $updates[] = "verified_until = ?";
        $params[] = $verifiedUntil;
        $params[] = $sessionId;
        $params[] = $visitorId;
        $stmt = $db->prepare("UPDATE chat_session_devices SET " . implode(', ', $updates) . " WHERE session_id = ? AND visitor_identifier = ?");
        $stmt->execute($params);

        $stmt = $db->prepare("SELECT visitor_identifier FROM chat_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $owner = trim((string)($stmt->fetchColumn() ?: ''));
        if ($owner !== '' && $owner !== $visitorId && chatIsValidVisitorId($owner)) {
            $stmt = $db->prepare("INSERT INTO chat_session_devices (session_id, visitor_identifier)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE last_seen_at = CURRENT_TIMESTAMP");
            $stmt->execute([$sessionId, $owner]);
        }
    } catch (Throwable $e) {
        error_log('chatSessionRegisterDevice failed: ' . $e->getMessage());
    }
}

function chatSessionDeviceAuth($db, $sessionId, $visitorId) {
    $sessionId = trim((string)$sessionId);
    $visitorId = trim((string)$visitorId);
    if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) return null;
    if (!chatIsValidVisitorId($visitorId)) return null;
    try {
        ensureChatSessionDevicesTable($db);
        $stmt = $db->prepare("SELECT phone_normalized, customer_name, verified_until
            FROM chat_session_devices
            WHERE session_id = ? AND visitor_identifier = ? AND verified_until > NOW()
            LIMIT 1");
        $stmt->execute([$sessionId, $visitorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('chatSessionDeviceAuth failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * 顧客端末（visitor_id）が当該セッションへアクセス可能かを判定する。
 *  - visitor_id 未提示、またはセッションに所有者が未登録なら従来通り許可。
 *  - セッションの単独所有者（visitor_identifier）と一致すれば許可。
 *  - SMS認証済みの別端末として認可集合に登録済みなら許可（同一電話番号の複数端末共有）。
 * $storedVisitorIdentifier を渡せば chat_sessions の追加照会を省略できる。
 */
function chatSessionVisitorAuthorized($db, $sessionId, $visitorId, $storedVisitorIdentifier = null) {
    $sessionId = trim((string)$sessionId);
    $visitorId = trim((string)$visitorId);
    if ($visitorId === '') return true;

    $stored = $storedVisitorIdentifier;
    if ($stored === null) {
        $stmt = $db->prepare("SELECT visitor_identifier FROM chat_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $stored = $stmt->fetchColumn();
    }
    $stored = trim((string)($stored ?? ''));
    if ($stored === '') return true;
    if ($stored === $visitorId) return true;

    // 認可集合を突合。高頻度ポーリングでの無駄な DDL を避けるため、
    // テーブル未作成などで失敗したときだけ遅延生成を試みる。
    try {
        $stmt = $db->prepare("SELECT 1 FROM chat_session_devices WHERE session_id = ? AND visitor_identifier = ? LIMIT 1");
        $stmt->execute([$sessionId, $visitorId]);
        if ($stmt->fetchColumn()) return true;
    } catch (Throwable $e) {
        try { ensureChatSessionDevicesTable($db); } catch (Throwable $e2) {}
    }
    return false;
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
    // INNER JOIN で「実在するセッション」だけを返す。
    // last_session_id が削除済みセッションを指している（デッドポインタ）場合に、
    // 存在しない session_id をクライアントへ渡してしまうと、以降 crm/get・物件・担当連絡が
    // すべて 404 になり「読み込みに失敗しました」から復帰できなくなるため。
    // デッドポインタ時は下の chat_lead_contacts フォールバック、または新規セッション作成に委ねる。
    $stmt = $db->prepare("SELECT cvp.last_session_id AS session_id, cvp.customer_name, cs.last_seen_at, cs.created_at
        FROM chat_verified_phones cvp
        JOIN chat_sessions cs ON cs.id = cvp.last_session_id
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

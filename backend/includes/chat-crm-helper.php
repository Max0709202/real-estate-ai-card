<?php
/**
 * Shared real-estate CRM state for chat sessions.
 * Stores structured feature data for:
 * - conditions
 * - progress
 * - property selection
 * - schedules
 * - contact / reply drafting
 */

function ensureChatCrmCasesTable($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS chat_crm_cases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id CHAR(36) NOT NULL UNIQUE,
        business_card_id INT NOT NULL,
        deal_type ENUM('purchase', 'sale', 'both') NOT NULL DEFAULT 'purchase',
        customer_name VARCHAR(255) NULL,
        ai_summary TEXT NULL,
        conditions_json JSON NULL,
        progress_json JSON NULL,
        properties_json JSON NULL,
        schedules_json JSON NULL,
        contact_json JSON NULL,
        reply_draft_json JSON NULL,
        last_condition_reminder_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
        INDEX idx_chat_crm_cases_card (business_card_id),
        INDEX idx_chat_crm_cases_deal_type (deal_type),
        INDEX idx_chat_crm_cases_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function chatCrmDecodeJsonValue($value, $fallback) {
    if ($value === null || $value === '') return $fallback;
    if (is_array($value)) return $value;
    if (is_object($value)) return (array)$value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function chatCrmNowIso() {
    return date('c');
}

function chatCrmDefaultConditions($dealType = 'purchase') {
    $isSale = $dealType === 'sale';
    return [
        'deal_type' => $dealType,
        'buyer' => [
            'purchase_timing' => null,
            'move_in_date' => null,
            'budget_max' => null,
            'areas' => [],
            'stations' => [],
            'walk_minutes' => null,
            'property_type' => 'マンション',
            'layout' => null,
            'area_min' => null,
            'building_age' => null,
            'renovation_preference' => null,
            'purchase_reason' => null,
        ],
        'seller' => [
            'sale_reason' => null,
            'sale_timing' => null,
            'closing_date' => null,
            'sale_price' => null,
            'minimum_price' => null,
            'loan_balance' => null,
            'relocation_plan' => null,
            'post_sale_home' => null,
            'viewing_availability' => null,
            'appeal_points' => [],
        ],
        'notes' => '',
        'updated_at' => chatCrmNowIso(),
        'active_section' => $isSale ? 'seller' : 'buyer',
    ];
}

function chatCrmDefaultProgress($dealType = 'purchase') {
    return [
        'deal_type' => $dealType,
        'target_date' => null,
        'current_stage' => null,
        'progress_percent' => 0,
        'manual_overrides' => [],
        'stages' => [],
        'ai_comment' => '',
        'updated_at' => chatCrmNowIso(),
    ];
}

function chatCrmDefaultProperties() {
    return [
        'items' => [],
        'updated_at' => chatCrmNowIso(),
    ];
}

function chatCrmDefaultSchedules($dealType = 'purchase') {
    return [
        'deal_type' => $dealType,
        'normal' => [],
        'viewings' => [],
        'seller_viewing_availability' => [],
        'updated_at' => chatCrmNowIso(),
    ];
}

function chatCrmDefaultContact() {
    return [
        'auto_reply_enabled' => true,
        'reply_draft' => '',
        'reply_history' => [],
        'updated_at' => chatCrmNowIso(),
    ];
}

function chatCrmNormalizeDealType($value) {
    $value = strtolower(trim((string)$value));
    if (in_array($value, ['sale', 'both'], true)) return $value;
    return 'purchase';
}

function chatCrmDefaultCase($dealType = 'purchase') {
    return [
        'deal_type' => chatCrmNormalizeDealType($dealType),
        'customer_name' => '',
        'ai_summary' => '',
        'conditions' => chatCrmDefaultConditions($dealType),
        'progress' => chatCrmDefaultProgress($dealType),
        'properties' => chatCrmDefaultProperties(),
        'schedules' => chatCrmDefaultSchedules($dealType),
        'contact' => chatCrmDefaultContact(),
        'reply_draft' => [
            'source_message' => '',
            'draft' => '',
            'mode' => 'manual',
            'updated_at' => chatCrmNowIso(),
        ],
        'last_condition_reminder_at' => null,
        'updated_at' => chatCrmNowIso(),
    ];
}

function chatCrmDateOrNull($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

function chatCrmAddDate($date, $modifier) {
    if (!$date) return null;
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->modify($modifier)->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function chatCrmFormatDateLabel($value) {
    $date = chatCrmDateOrNull($value);
    if (!$date) return '';
    $ts = strtotime($date);
    return date('Y/m/d', $ts);
}

function chatCrmCalculatePurchaseStages($targetDate, $manualOverrides = []) {
    $targetDate = chatCrmDateOrNull($targetDate);
    if (!$targetDate) return [];
    $stages = [
        ['key' => 'conditions', 'label' => '条件整理・資金計画', 'offset' => '-90 days'],
        ['key' => 'viewing', 'label' => '物件内覧', 'offset' => '-75 days'],
        ['key' => 'offer', 'label' => '買付申込', 'offset' => '-60 days'],
        ['key' => 'contract', 'label' => '重要事項説明・売買契約', 'offset' => '-50 days'],
        ['key' => 'application', 'label' => '本審査申込', 'offset' => '-40 days'],
        ['key' => 'approval', 'label' => '融資承認', 'offset' => '-19 days'],
        ['key' => 'loan_contract', 'label' => '金銭消費貸借契約', 'offset' => '-10 days'],
        ['key' => 'handover', 'label' => '引き渡し', 'offset' => '0 days'],
    ];
    $result = [];
    foreach ($stages as $stage) {
        $date = $manualOverrides[$stage['key']] ?? null;
        $date = chatCrmDateOrNull($date) ?: chatCrmAddDate($targetDate, $stage['offset']);
        $result[] = [
            'key' => $stage['key'],
            'label' => $stage['label'],
            'date' => $date,
        ];
    }
    return $result;
}

function chatCrmCalculateSaleStages($targetDate, $manualOverrides = []) {
    $targetDate = chatCrmDateOrNull($targetDate);
    if (!$targetDate) return [];
    $listingStart = chatCrmDateOrNull($manualOverrides['listing_start'] ?? null) ?: chatCrmAddDate($targetDate, '-105 days');
    $contract = chatCrmDateOrNull($manualOverrides['contract'] ?? null) ?: chatCrmAddDate($targetDate, '-28 days');
    $offer = chatCrmDateOrNull($manualOverrides['offer'] ?? null) ?: chatCrmAddDate($contract, '-10 days');
    $brokerage = chatCrmDateOrNull($manualOverrides['brokerage'] ?? null) ?: chatCrmAddDate($listingStart, '-7 days');
    $appraisal = chatCrmDateOrNull($manualOverrides['appraisal'] ?? null) ?: chatCrmAddDate($brokerage, '-14 days');
    $viewingEnd = chatCrmDateOrNull($manualOverrides['viewing_end'] ?? null) ?: chatCrmAddDate($listingStart, '+60 days');

    return [
        ['key' => 'appraisal', 'label' => '査定・価格方針決定', 'date' => $appraisal],
        ['key' => 'brokerage', 'label' => '媒介契約', 'date' => $brokerage],
        ['key' => 'listing_start', 'label' => '募集活動開始', 'date' => $listingStart],
        ['key' => 'viewing_period', 'label' => '購入希望者の内覧', 'date' => $listingStart . '〜' . $viewingEnd],
        ['key' => 'offer', 'label' => '買付申込', 'date' => $offer],
        ['key' => 'contract', 'label' => '売買契約', 'date' => $contract],
        ['key' => 'closing', 'label' => '決済（引き渡し）', 'date' => $targetDate],
    ];
}

function chatCrmProgressPercent($stages, $targetDate) {
    if (empty($stages) || !$targetDate) return 0;
    $total = count($stages);
    $done = 0;
    $today = date('Y-m-d');
    foreach ($stages as $stage) {
        $date = $stage['date'] ?? null;
        if (!$date) continue;
        if (strpos((string)$date, '〜') !== false) {
            $parts = explode('〜', (string)$date);
            $date = end($parts);
        }
        if ($date <= $today) $done++;
    }
    return (int)round(($done / max(1, $total)) * 100);
}

function chatCrmSummarizeConditions($caseData) {
    $conditions = $caseData['conditions'] ?? [];
    $dealType = $caseData['deal_type'] ?? 'purchase';
    $parts = [];
    if ($dealType === 'sale' || $dealType === 'both') {
        $seller = $conditions['seller'] ?? [];
        foreach (['sale_reason', 'sale_timing', 'sale_price', 'minimum_price', 'loan_balance', 'relocation_plan'] as $key) {
            if (!empty($seller[$key])) $parts[] = $seller[$key];
        }
    } else {
        $buyer = $conditions['buyer'] ?? [];
        foreach (['purchase_timing', 'budget_max', 'areas', 'stations', 'walk_minutes', 'property_type', 'layout', 'area_min', 'building_age'] as $key) {
            $value = $buyer[$key] ?? null;
            if (is_array($value)) $value = implode('・', array_filter($value));
            if (!empty($value)) $parts[] = $value;
        }
    }
    return implode(' / ', $parts);
}

function chatCrmNormalizeCasePayload($payload, $baseCase = null) {
    $baseCase = is_array($baseCase) ? $baseCase : chatCrmDefaultCase();
    $dealType = chatCrmNormalizeDealType($payload['deal_type'] ?? ($baseCase['deal_type'] ?? 'purchase'));
    $case = $baseCase;
    $case['deal_type'] = $dealType;
    $case['customer_name'] = trim((string)($payload['customer_name'] ?? ($case['customer_name'] ?? '')));
    $case['ai_summary'] = trim((string)($payload['ai_summary'] ?? ($case['ai_summary'] ?? '')));

    $conditions = chatCrmDecodeJsonValue($payload['conditions'] ?? null, $case['conditions']);
    $progress = chatCrmDecodeJsonValue($payload['progress'] ?? null, $case['progress']);
    $properties = chatCrmDecodeJsonValue($payload['properties'] ?? null, $case['properties']);
    $schedules = chatCrmDecodeJsonValue($payload['schedules'] ?? null, $case['schedules']);
    $contact = chatCrmDecodeJsonValue($payload['contact'] ?? null, $case['contact']);
    $replyDraft = chatCrmDecodeJsonValue($payload['reply_draft'] ?? null, $case['reply_draft']);

    $case['conditions'] = $conditions ?: chatCrmDefaultConditions($dealType);
    $case['progress'] = $progress ?: chatCrmDefaultProgress($dealType);
    $case['properties'] = $properties ?: chatCrmDefaultProperties();
    $case['schedules'] = $schedules ?: chatCrmDefaultSchedules($dealType);
    $case['contact'] = $contact ?: chatCrmDefaultContact();
    $case['reply_draft'] = $replyDraft ?: [
        'source_message' => '',
        'draft' => '',
        'mode' => 'manual',
        'updated_at' => chatCrmNowIso(),
    ];
    $case['last_condition_reminder_at'] = $payload['last_condition_reminder_at'] ?? ($case['last_condition_reminder_at'] ?? null);
    $case['updated_at'] = chatCrmNowIso();
    return $case;
}

function chatCrmUpsertCase($db, $sessionId, $businessCardId, $payload) {
    if (!$db instanceof PDO || !preg_match('/^[A-Fa-f0-9-]{36}$/', (string)$sessionId) || (int)$businessCardId <= 0) return false;
    ensureChatCrmCasesTable($db);
    $current = chatCrmLoadCase($db, $sessionId, $businessCardId);
    $case = chatCrmNormalizeCasePayload($payload, $current ?: null);

    $conditions = json_encode($case['conditions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $progress = json_encode($case['progress'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $properties = json_encode($case['properties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $schedules = json_encode($case['schedules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $contact = json_encode($case['contact'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $replyDraft = json_encode($case['reply_draft'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare("
        INSERT INTO chat_crm_cases
            (session_id, business_card_id, deal_type, customer_name, ai_summary, conditions_json, progress_json, properties_json, schedules_json, contact_json, reply_draft_json, last_condition_reminder_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            deal_type = VALUES(deal_type),
            customer_name = VALUES(customer_name),
            ai_summary = VALUES(ai_summary),
            conditions_json = VALUES(conditions_json),
            progress_json = VALUES(progress_json),
            properties_json = VALUES(properties_json),
            schedules_json = VALUES(schedules_json),
            contact_json = VALUES(contact_json),
            reply_draft_json = VALUES(reply_draft_json),
            last_condition_reminder_at = VALUES(last_condition_reminder_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    return $stmt->execute([
        $sessionId,
        (int)$businessCardId,
        $case['deal_type'],
        $case['customer_name'] !== '' ? $case['customer_name'] : null,
        $case['ai_summary'] !== '' ? $case['ai_summary'] : null,
        $conditions,
        $progress,
        $properties,
        $schedules,
        $contact,
        $replyDraft,
        $case['last_condition_reminder_at'] ?: null,
    ]);
}

function chatCrmLoadCase($db, $sessionId, $businessCardId = null) {
    if (!$db instanceof PDO || !preg_match('/^[A-Fa-f0-9-]{36}$/', (string)$sessionId)) return null;
    try {
        ensureChatCrmCasesTable($db);
        $params = [$sessionId];
        $sql = "SELECT * FROM chat_crm_cases WHERE session_id = ? LIMIT 1";
        if ($businessCardId !== null) {
            $sql = "SELECT * FROM chat_crm_cases WHERE session_id = ? AND business_card_id = ? LIMIT 1";
            array_push($params, (int)$businessCardId);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['conditions'] = chatCrmDecodeJsonValue($row['conditions_json'] ?? null, chatCrmDefaultConditions($row['deal_type'] ?? 'purchase'));
        $row['progress'] = chatCrmDecodeJsonValue($row['progress_json'] ?? null, chatCrmDefaultProgress($row['deal_type'] ?? 'purchase'));
        $row['properties'] = chatCrmDecodeJsonValue($row['properties_json'] ?? null, chatCrmDefaultProperties());
        $row['schedules'] = chatCrmDecodeJsonValue($row['schedules_json'] ?? null, chatCrmDefaultSchedules($row['deal_type'] ?? 'purchase'));
        $row['contact'] = chatCrmDecodeJsonValue($row['contact_json'] ?? null, chatCrmDefaultContact());
        $row['reply_draft'] = chatCrmDecodeJsonValue($row['reply_draft_json'] ?? null, ['source_message' => '', 'draft' => '', 'mode' => 'manual', 'updated_at' => chatCrmNowIso()]);
        return $row;
    } catch (Throwable $e) {
        error_log('chat CRM load error: ' . $e->getMessage());
        return null;
    }
}

function chatCrmSyncFromChatSession($db, $sessionId, $businessCardId) {
    if (!$db instanceof PDO || !preg_match('/^[A-Fa-f0-9-]{36}$/', (string)$sessionId) || (int)$businessCardId <= 0) return null;
    try {
        ensureChatCrmCasesTable($db);
        $stmt = $db->prepare("SELECT structured_data, consent_given FROM chat_leads WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $structured = [];
        if (!empty($lead['structured_data'])) {
            $structured = json_decode($lead['structured_data'], true);
            if (!is_array($structured)) $structured = [];
        }

        $stmt = $db->prepare("SELECT memory_json, last_summary FROM chat_session_memory WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $memory = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $memoryData = [];
        if (!empty($memory['memory_json'])) {
            $memoryData = json_decode($memory['memory_json'], true);
            if (!is_array($memoryData)) $memoryData = [];
        }

        $current = chatCrmLoadCase($db, $sessionId, $businessCardId) ?: chatCrmDefaultCase();
        $conditions = $current['conditions'];
        $dealType = $current['deal_type'];

        if (($structured['customer_type'] ?? '') === 'sale' || !empty($structured['selling_timing']) || !empty($structured['loan_balance'])) {
            $dealType = 'sale';
            $conditions['seller']['sale_reason'] = $structured['selling_reason'] ?? $conditions['seller']['sale_reason'];
            $conditions['seller']['sale_timing'] = $structured['selling_timing'] ?? $conditions['seller']['sale_timing'];
            $conditions['seller']['closing_date'] = $structured['move_date'] ?? $conditions['seller']['closing_date'];
            $conditions['seller']['sale_price'] = $structured['desired_sale_price'] ?? $conditions['seller']['sale_price'];
            $conditions['seller']['minimum_price'] = $structured['minimum_price'] ?? $conditions['seller']['minimum_price'];
            $conditions['seller']['loan_balance'] = $structured['loan_balance'] ?? $conditions['seller']['loan_balance'];
            $conditions['seller']['relocation_plan'] = $structured['replacement_plan'] ?? $conditions['seller']['relocation_plan'];
            $conditions['seller']['post_sale_home'] = $structured['temporary_housing'] ?? $conditions['seller']['post_sale_home'];
            $conditions['seller']['appeal_points'] = $structured['appeal_points'] ?? $conditions['seller']['appeal_points'];
        } else {
            $dealType = 'purchase';
            $conditions['buyer']['purchase_timing'] = $structured['purchase_timing'] ?? $conditions['buyer']['purchase_timing'];
            $conditions['buyer']['move_in_date'] = $structured['move_date'] ?? $conditions['buyer']['move_in_date'];
            $conditions['buyer']['budget_max'] = $structured['budget_max'] ?? ($structured['desired_loan_amount'] ?? $conditions['buyer']['budget_max']);
            $conditions['buyer']['areas'] = array_values(array_filter(array_merge($conditions['buyer']['areas'] ?? [], (array)($structured['preferred_area'] ?? []))));
            $conditions['buyer']['stations'] = array_values(array_filter(array_merge($conditions['buyer']['stations'] ?? [], (array)($structured['preferred_station'] ?? []))));
            $conditions['buyer']['walk_minutes'] = $structured['station_walk_minutes'] ?? $conditions['buyer']['walk_minutes'];
            $conditions['buyer']['property_type'] = $structured['property_type'][0] ?? ($structured['property_type'] ?? $conditions['buyer']['property_type']);
            $conditions['buyer']['layout'] = $structured['layout'] ?? $conditions['buyer']['layout'];
            $conditions['buyer']['area_min'] = $structured['preferred_area_size'] ?? $conditions['buyer']['area_min'];
            $conditions['buyer']['building_age'] = $structured['building_age'] ?? $conditions['buyer']['building_age'];
            $conditions['buyer']['renovation_preference'] = $structured['renovation_preference'] ?? $conditions['buyer']['renovation_preference'];
            $conditions['buyer']['purchase_reason'] = $structured['reason_for_move'] ?? $conditions['buyer']['purchase_reason'];
        }

        $summary = trim((string)($current['ai_summary'] ?? ''));
        if (!empty($memory['last_summary'])) {
            $summary = (string)$memory['last_summary'];
        } elseif (!empty($structured['summary_for_sales'])) {
            $summary = (string)$structured['summary_for_sales'];
        }

        $progress = $current['progress'];
        $progress['deal_type'] = $dealType;
        if ($dealType === 'sale') {
            $progress['stages'] = chatCrmCalculateSaleStages($progress['target_date'] ?? null, $progress['manual_overrides'] ?? []);
        } else {
            $progress['stages'] = chatCrmCalculatePurchaseStages($progress['target_date'] ?? null, $progress['manual_overrides'] ?? []);
        }
        $progress['progress_percent'] = chatCrmProgressPercent($progress['stages'], $progress['target_date'] ?? null);
        $progress['current_stage'] = '';
        foreach ($progress['stages'] as $stage) {
            $date = $stage['date'] ?? '';
            if ($date && strpos((string)$date, '〜') === false && $date <= date('Y-m-d')) {
                $progress['current_stage'] = $stage['label'];
            }
        }
        $progress['ai_comment'] = !empty($memory['last_summary']) ? 'AI要約を元に最新状況を更新しました。' : ($progress['ai_comment'] ?? '');

        $case = [
            'deal_type' => $dealType,
            'customer_name' => trim((string)($current['customer_name'] ?? '')),
            'ai_summary' => $summary,
            'conditions' => $conditions,
            'progress' => $progress,
            'properties' => $current['properties'],
            'schedules' => $current['schedules'],
            'contact' => $current['contact'],
            'reply_draft' => $current['reply_draft'],
            'last_condition_reminder_at' => $current['last_condition_reminder_at'] ?? null,
            'updated_at' => chatCrmNowIso(),
        ];
        chatCrmUpsertCase($db, $sessionId, $businessCardId, $case);
        return chatCrmLoadCase($db, $sessionId, $businessCardId);
    } catch (Throwable $e) {
        error_log('chat CRM sync error: ' . $e->getMessage());
        return null;
    }
}

function chatCrmArrayFromMaybeValue($value) {
    if (is_array($value)) return array_values($value);
    $value = trim((string)$value);
    if ($value === '') return [];
    if (strpos($value, ',') !== false) {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
    if (strpos($value, '・') !== false) {
        return array_values(array_filter(array_map('trim', explode('・', $value))));
    }
    return [$value];
}


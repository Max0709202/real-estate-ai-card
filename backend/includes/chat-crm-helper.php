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
        deal_type ENUM('purchase', 'sale', 'both', 'rent') NOT NULL DEFAULT 'purchase',
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
    // 既存テーブルには 'rent'（賃貸）が無いため、ENUM を後方互換で拡張する。
    try {
        $db->exec("ALTER TABLE chat_crm_cases MODIFY deal_type ENUM('purchase', 'sale', 'both', 'rent') NOT NULL DEFAULT 'purchase'");
    } catch (Throwable $e) {
        // 既に拡張済みか、権限が無い場合は黙って続行（新規CREATE側で担保）。
    }
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

function chatCrmToolName($toolType) {
    $names = [
        'mdb' => '全国マンションデータベース',
        'rlp' => '物件提案ロボ',
        'llp' => '土地情報ロボ',
        'ai' => 'AIマンション査定',
        'slp' => 'セルフィン',
        'olp' => 'オーナーコネクト',
        'alp' => '統合LP',
    ];
    return $names[$toolType] ?? 'テックツール';
}

function chatCrmToolDescription($toolType) {
    $descriptions = [
        'mdb' => '国内最大規模のマンションデータベース。マンションの基礎情報・販売履歴をはじめ、口コミなども閲覧できます。',
        'rlp' => '希望条件に合う新着の売却情報を売り出しから24時間以内にAI評価付きで毎日配信します。希望物件の見落としが無くなります。',
        'llp' => '建てたい工務店は決まっているのに、土地情報を探しているお客様向け。新着の土地売却情報を売り出しから24時間以内に毎日配信します。',
        'ai' => '個人情報不要で、膨大な販売履歴より瞬時にマンションの価格を査定します。いつでも自分のマンションの査定が可能です。',
        'slp' => '物件の良し悪しを自動でしかも一瞬で判定するセルフインスペクションWEBアプリ。ネガティブ情報の発見にご活用ください。',
        'olp' => '今日の自宅の価格、今日の残債、今日売ったらいくら手元に残るかなど、登録すると1週間に1回配信されます。他住戸の売り出し情報が出たら直ちに情報を配信します。',
        'alp' => '全国マンションデータベース、物件提案ロボ、土地情報ロボ、AIマンション査定、セルフィン、オーナーコネクトをご紹介するページです。',
    ];
    return $descriptions[$toolType] ?? '';
}

function chatCrmToolImageUrl($toolType) {
    $file = preg_replace('/[^a-z0-9_-]/i', '', (string)$toolType);
    if ($file === '') $file = 'default';
    return rtrim(BASE_URL, '/') . '/assets/images/lp_icon/' . $file . '.png';
}

function chatCrmToolButtonLabel($toolType) {
    $labels = [
        'mdb' => '売却・購入',
        'rlp' => '購入',
        'llp' => '購入',
        'ai' => '売却',
        'slp' => '売却・購入',
        'olp' => 'マンション所有者',
        'alp' => '詳細',
    ];
    return $labels[$toolType] ?? '利用';
}

function chatCrmBuildToolUrl($toolType, $card, $savedUrl = '') {
    $isEraMember = (int)($card['is_era_member'] ?? 0) === 1;
    $selfInBase = $isEraMember ? 'https://era.self-in.com/' : 'https://self-in.com/';
    $selfInNetBase = $isEraMember ? 'https://era.self-in.net/' : 'https://self-in.net/';
    $cardSlug = trim((string)($card['url_slug'] ?? ''));
    $toolSlug = trim((string)($card['company_slug'] ?? ''));
    if ($toolSlug === '') $toolSlug = $cardSlug;
    if ($toolSlug === '') return $savedUrl;

    switch ($toolType) {
        case 'mdb':
            return $selfInBase . $toolSlug . '/mdb/';
        case 'ai':
            return $selfInBase . $toolSlug . '/ai/';
        case 'rlp':
            return $selfInNetBase . 'rlp/index.php?id=' . $toolSlug . '/';
        case 'llp':
            return $selfInNetBase . 'llp/index.php?id=' . $toolSlug . '/';
        case 'slp':
            return $selfInNetBase . 'slp/index.php?id=' . $toolSlug . '/';
        case 'olp':
            return $selfInNetBase . 'olp/index.php?id=' . $toolSlug . '/';
        case 'alp':
            return $selfInNetBase . 'alp/index.php?id=' . $toolSlug . '/';
        default:
            return $savedUrl;
    }
}

function chatCrmLoadToolsForCard($db, $businessCardId) {
    if (!$db instanceof PDO || (int)$businessCardId <= 0) return [];
    try {
        $stmt = $db->prepare("
            SELECT bc.id, bc.url_slug, bc.company_slug, COALESCE(u.is_era_member, 0) AS is_era_member
            FROM business_cards bc
            JOIN users u ON u.id = bc.user_id
            WHERE bc.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$businessCardId]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$card) return [];

        $stmt = $db->prepare("SELECT tool_type, tool_url, display_order, is_active FROM tech_tool_selections WHERE business_card_id = ? AND is_active = 1 ORDER BY display_order ASC");
        $stmt->execute([(int)$businessCardId]);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($tools as &$tool) {
            $toolType = trim((string)($tool['tool_type'] ?? ''));
            $tool['tool_name'] = chatCrmToolName($toolType);
            $tool['tool_url'] = chatCrmBuildToolUrl($toolType, $card, trim((string)($tool['tool_url'] ?? '')));
            $tool['description'] = chatCrmToolDescription($toolType);
            $tool['image_url'] = chatCrmToolImageUrl($toolType);
            $tool['button_label'] = chatCrmToolButtonLabel($toolType);
        }
        unset($tool);
        return $tools;
    } catch (Throwable $e) {
        error_log('chat CRM load tools error: ' . $e->getMessage());
        return [];
    }
}

function chatCrmDefaultConditions($dealType = 'purchase') {
    $isSale = $dealType === 'sale';
    $activeSection = 'buyer';
    if ($dealType === 'sale') $activeSection = 'seller';
    elseif ($dealType === 'rent') $activeSection = 'renter';
    return [
        'deal_type' => $dealType,
        'buyer' => [
            'purchase_timing' => null,
            'move_in_date' => null,
            'budget_max' => null,
            'areas' => [],
            'station_lines' => [],
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
        'renter' => [
            'move_in_timing' => null,
            'move_date' => null,
            'rent_max' => null,
            'areas' => [],
            'station_lines' => [],
            'stations' => [],
            'walk_minutes' => null,
            'property_type' => null,
            'layouts' => [],
            'area_min' => null,
            'building_age' => null,
            'features' => [],
            'move_reason' => null,
        ],
        'notes' => '',
        'updated_at' => chatCrmNowIso(),
        'active_section' => $activeSection,
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
    if (in_array($value, ['sale', 'both', 'rent'], true)) return $value;
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

function chatCrmSummarizeSellerParts($seller) {
    $parts = [];
    if (!empty($seller['sale_reason'])) $parts[] = '売却理由: ' . $seller['sale_reason'];
    if (!empty($seller['sale_timing'])) $parts[] = '売却希望時期: ' . $seller['sale_timing'];
    if (!empty($seller['closing_date'])) $parts[] = '決済希望日: ' . $seller['closing_date'];
    if (!empty($seller['sale_price'])) $parts[] = '希望価格: ' . $seller['sale_price'];
    if (!empty($seller['minimum_price'])) $parts[] = '最低価格: ' . $seller['minimum_price'];
    if (!empty($seller['loan_balance'])) $parts[] = 'ローン残債: ' . $seller['loan_balance'];
    if (!empty($seller['relocation_plan'])) $parts[] = '住み替え予定: ' . $seller['relocation_plan'];
    if (!empty($seller['post_sale_home'])) $parts[] = '売却後の住まい: ' . $seller['post_sale_home'];
    if (!empty($seller['viewing_availability'])) $parts[] = '内覧対応: ' . $seller['viewing_availability'];
    return $parts;
}

function chatCrmSummarizeBuyerParts($buyer) {
    $parts = [];
    if (!empty($buyer['purchase_timing'])) $parts[] = '購入時期: ' . $buyer['purchase_timing'];
    if (!empty($buyer['move_in_date'])) $parts[] = '引越希望日: ' . $buyer['move_in_date'];
    if (!empty($buyer['budget_max'])) $parts[] = '予算: ' . $buyer['budget_max'];
    if (!empty($buyer['areas'])) $parts[] = 'エリア: ' . implode('・', array_filter((array)$buyer['areas']));
    if (!empty($buyer['station_lines'])) $parts[] = '沿線: ' . implode('・', array_filter((array)$buyer['station_lines']));
    if (!empty($buyer['stations'])) $parts[] = '駅: ' . implode('・', array_filter((array)$buyer['stations']));
    if (!empty($buyer['walk_minutes'])) $parts[] = '駅徒歩: ' . $buyer['walk_minutes'];
    if (!empty($buyer['property_type'])) $parts[] = '種別: ' . $buyer['property_type'];
    if (!empty($buyer['layout'])) $parts[] = '間取り: ' . $buyer['layout'];
    if (!empty($buyer['area_min'])) $parts[] = '面積: ' . $buyer['area_min'];
    if (!empty($buyer['building_age'])) $parts[] = '築年数: ' . $buyer['building_age'];
    if (!empty($buyer['renovation_preference'])) $parts[] = 'リノベーション希望: ' . $buyer['renovation_preference'];
    if (!empty($buyer['purchase_reason'])) $parts[] = '購入理由: ' . $buyer['purchase_reason'];
    return $parts;
}

function chatCrmSummarizeRenterParts($renter) {
    $parts = [];
    if (!empty($renter['move_in_timing'])) $parts[] = '入居希望時期: ' . $renter['move_in_timing'];
    if (!empty($renter['move_date'])) $parts[] = '引越希望日: ' . $renter['move_date'];
    if (!empty($renter['rent_max'])) $parts[] = '家賃上限: ' . $renter['rent_max'];
    if (!empty($renter['areas'])) $parts[] = 'エリア: ' . implode('・', array_filter((array)$renter['areas']));
    if (!empty($renter['station_lines'])) $parts[] = '沿線: ' . implode('・', array_filter((array)$renter['station_lines']));
    if (!empty($renter['stations'])) $parts[] = '駅: ' . implode('・', array_filter((array)$renter['stations']));
    if (!empty($renter['walk_minutes'])) $parts[] = '駅徒歩: ' . $renter['walk_minutes'];
    if (!empty($renter['property_type'])) $parts[] = '種別: ' . $renter['property_type'];
    if (!empty($renter['layouts'])) $parts[] = '間取り: ' . implode('・', array_filter((array)$renter['layouts']));
    if (!empty($renter['area_min'])) $parts[] = '専有面積: ' . $renter['area_min'];
    if (!empty($renter['building_age'])) $parts[] = '築年数: ' . $renter['building_age'];
    if (!empty($renter['features'])) $parts[] = 'こだわり条件: ' . implode('・', array_filter((array)$renter['features']));
    if (!empty($renter['move_reason'])) $parts[] = '引越し理由: ' . $renter['move_reason'];
    return $parts;
}

function chatCrmSummarizeConditions($caseData) {
    $conditions = $caseData['conditions'] ?? [];
    $dealType = $caseData['deal_type'] ?? 'purchase';
    if ($dealType === 'rent') {
        return implode(' / ', chatCrmSummarizeRenterParts($conditions['renter'] ?? []));
    }
    if ($dealType === 'sale') {
        return implode(' / ', chatCrmSummarizeSellerParts($conditions['seller'] ?? []));
    }
    if ($dealType === 'both') {
        // 買い替え: 売却条件（上）と購入条件（下）の両方を整理結果に含める。
        $sellerParts = chatCrmSummarizeSellerParts($conditions['seller'] ?? []);
        $buyerParts = chatCrmSummarizeBuyerParts($conditions['buyer'] ?? []);
        $sections = [];
        if (!empty($sellerParts)) $sections[] = '【売却】' . implode(' / ', $sellerParts);
        if (!empty($buyerParts)) $sections[] = '【購入】' . implode(' / ', $buyerParts);
        return implode(' ／ ', $sections);
    }
    return implode(' / ', chatCrmSummarizeBuyerParts($conditions['buyer'] ?? []));
}

function chatCrmSplitListText($value) {
    if (is_array($value)) return array_values(array_filter(array_map('trim', $value)));
    $value = trim((string)$value);
    if ($value === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/[、,，\n\r]+/u', $value))));
}

function chatCrmFirstRegex($text, $pattern) {
    if (preg_match($pattern, (string)$text, $m)) return trim((string)($m[1] ?? ''));
    return '';
}

function chatCrmMergeUnique($old, $new) {
    $merged = array_merge((array)$old, (array)$new);
    $seen = [];
    $out = [];
    foreach ($merged as $value) {
        $value = trim((string)$value);
        if ($value === '' || isset($seen[$value])) continue;
        $seen[$value] = true;
        $out[] = $value;
    }
    return $out;
}

function chatCrmExtractConditionsFromText($text) {
    $text = trim((string)$text);
    $result = [
        'deal_type' => null,
        'buyer' => [],
        'seller' => [],
    ];
    if ($text === '') return $result;

    if (preg_match('/売却|売りたい|査定|媒介|売主|相続/u', $text)) {
        $result['deal_type'] = 'sale';
    } elseif (preg_match('/購入|買いたい|物件探し|内覧|住宅ローン|マンション|戸建/u', $text)) {
        $result['deal_type'] = 'purchase';
    }

    $timingChoices = ['できるだけ早く', '3か月以内', '6か月以内', '半年以内', '1年以内', '未定'];
    foreach ($timingChoices as $choice) {
        if (mb_strpos($text, $choice) !== false) {
            if ($result['deal_type'] === 'sale') $result['seller']['sale_timing'] = $choice === '半年以内' ? '半年以内' : $choice;
            else $result['buyer']['purchase_timing'] = $choice === '半年以内' ? '6か月以内' : $choice;
            break;
        }
    }

    $date = chatCrmFirstRegex($text, '/(\d{4}[\/年-]\s*\d{1,2}[\/月-]\s*\d{1,2}日?)/u');
    if ($date !== '') {
        $normalizedDate = chatCrmDateOrNull(str_replace(['年', '月', '日'], ['-', '-', ''], $date));
        if ($result['deal_type'] === 'sale') $result['seller']['closing_date'] = $normalizedDate ?: $date;
        else $result['buyer']['move_in_date'] = $normalizedDate ?: $date;
    }

    if (preg_match('/(?:予算|購入予算|上限|価格)[^\d]*(\d{3,5}\s*万?円?)/u', $text, $m)) {
        $result['buyer']['budget_max'] = trim($m[1]);
    }
    if (preg_match('/(?:希望価格|売却希望価格)[^\d]*(\d{3,5}\s*万?円?)/u', $text, $m)) {
        $result['seller']['sale_price'] = trim($m[1]);
        $result['deal_type'] = 'sale';
    }
    if (preg_match('/(?:最低価格|最低売却価格)[^\d]*(\d{3,5}\s*万?円?)/u', $text, $m)) {
        $result['seller']['minimum_price'] = trim($m[1]);
        $result['deal_type'] = 'sale';
    }
    if (preg_match('/(?:ローン残債|残債)[^\d]*(\d{3,5}\s*万?円?)/u', $text, $m)) {
        $result['seller']['loan_balance'] = trim($m[1]);
        $result['deal_type'] = 'sale';
    }

    if (preg_match_all('/([一-龥ぁ-んァ-ヶA-Za-z0-9]+(?:区|市|町|村))/u', $text, $m)) {
        $result['buyer']['areas'] = $m[1];
    }
    if (preg_match_all('/([一-龥ぁ-んァ-ヶA-Za-z0-9]+線)/u', $text, $m)) {
        $result['buyer']['station_lines'] = $m[1];
    }
    if (preg_match_all('/([一-龥ぁ-んァ-ヶA-Za-z0-9]+駅)/u', $text, $m)) {
        $result['buyer']['stations'] = $m[1];
    }
    if (preg_match('/(5分以内|10分以内|15分以内|こだわらない)/u', $text, $m)) {
        $result['buyer']['walk_minutes'] = $m[1];
    }
    if (preg_match('/(マンション|戸建|戸建て|どちらでも可)/u', $text, $m)) {
        $result['buyer']['property_type'] = $m[1] === '戸建て' ? '戸建' : $m[1];
    }
    if (preg_match('/(ワンルーム|1K|1LDK|2LDK|3LDK|4LDK|5LDK以上|こだわらない)/iu', $text, $m)) {
        $result['buyer']['layout'] = strtoupper($m[1]);
    }
    if (preg_match('/(\d{1,3}\s*(?:㎡|m2|平米)以上)/iu', $text, $m)) {
        $result['buyer']['area_min'] = str_replace(['m2', '平米'], '㎡', trim($m[1]));
    }
    if (preg_match('/(新築|10年以内|20年以内|30年以内|築年数こだわらない)/u', $text, $m)) {
        $result['buyer']['building_age'] = $m[1] === '築年数こだわらない' ? 'こだわらない' : $m[1];
    }
    if (preg_match('/(リノベーション済み希望|自らリフォームする予定|リフォーム済希望|自分でリフォーム)/u', $text, $m)) {
        $result['buyer']['renovation_preference'] = in_array($m[1], ['リフォーム済希望'], true) ? 'リノベーション済み希望' : $m[1];
    }

    foreach (['家賃がもったいない', '結婚', '出産', '子供の進学', '住み替え', '投資', 'その他'] as $reason) {
        if (mb_strpos($text, $reason) !== false) $result['buyer']['purchase_reason'] = $reason;
    }
    foreach (['住み替え', '相続', '離婚', '転勤', '資産整理', '投資売却', 'その他'] as $reason) {
        if (mb_strpos($text, $reason) !== false && $result['deal_type'] === 'sale') $result['seller']['sale_reason'] = $reason;
    }
    foreach (['あり', 'なし', '未定'] as $value) {
        if (preg_match('/住み替え予定.*' . preg_quote($value, '/') . '/u', $text)) {
            $result['seller']['relocation_plan'] = $value;
        }
    }
    foreach (['購入予定', '賃貸予定', '実家', '未定'] as $value) {
        if (mb_strpos($text, $value) !== false && $result['deal_type'] === 'sale') $result['seller']['post_sale_home'] = $value;
    }
    foreach (['土日可', '平日可', 'いつでも可', '要相談'] as $value) {
        if (mb_strpos($text, $value) !== false) $result['seller']['viewing_availability'] = $value;
    }
    if (preg_match_all('/(日当たりが良い|管理状態が良い|角部屋|駅近)/u', $text, $m)) {
        $result['seller']['appeal_points'] = $m[1];
    }

    return $result;
}

function chatCrmHasMeaningfulConditions($caseData) {
    $summary = trim(chatCrmSummarizeConditions($caseData));
    if ($summary === '') return false;
    return $summary !== '種別: マンション' && $summary !== 'マンション';
}

function chatCrmConditionReminderDue($caseData) {
    if (!chatCrmHasMeaningfulConditions($caseData)) return false;
    $last = $caseData['last_condition_reminder_at'] ?? null;
    if (empty($last)) return true;
    $lastTs = strtotime((string)$last);
    if ($lastTs === false) return true;
    return $lastTs <= strtotime('-3 days');
}

function chatCrmDealTypeLabel($dealType) {
    switch ($dealType) {
        case 'sale': return '売却';
        case 'both': return '買い替え';
        case 'rent': return '賃貸';
        default: return '購入';
    }
}

/**
 * 顧客が「条件整理」に直接入力した希望条件を、顧客向けAIチャットへ最優先の前提として渡す文脈を作る。
 * ここに含まれるのは本人が入力（または回答）した希望であり、会話の自由発言よりも優先して扱う。
 */
function chatCrmBuildManualPriorityContext($db, $sessionId, $businessCardId) {
    if (!$db instanceof PDO || (int)$businessCardId <= 0) return '';
    if (!preg_match('/^[A-Fa-f0-9-]{36}$/', (string)$sessionId)) return '';
    try {
        $case = chatCrmLoadCase($db, $sessionId, (int)$businessCardId);
        if (!$case) return '';
        $dealType = $case['deal_type'] ?? 'purchase';
        $conditions = $case['conditions'] ?? [];
        $sections = [];
        if ($dealType === 'rent') {
            $sections[] = ['賃貸希望条件', chatCrmSummarizeRenterParts($conditions['renter'] ?? [])];
        } elseif ($dealType === 'sale') {
            $sections[] = ['売却条件', chatCrmSummarizeSellerParts($conditions['seller'] ?? [])];
        } elseif ($dealType === 'both') {
            $sections[] = ['売却条件', chatCrmSummarizeSellerParts($conditions['seller'] ?? [])];
            $sections[] = ['購入条件', chatCrmSummarizeBuyerParts($conditions['buyer'] ?? [])];
        } else {
            $sections[] = ['購入条件', chatCrmSummarizeBuyerParts($conditions['buyer'] ?? [])];
        }
        $lines = [];
        foreach ($sections as $section) {
            if (empty($section[1])) continue;
            $lines[] = '■ ' . $section[0];
            foreach ($section[1] as $part) {
                $lines[] = '・' . $part;
            }
        }
        if (empty($lines)) return '';
        return "【お客様が条件整理に直接入力された希望条件（最優先）】\n"
            . "相談種別：" . chatCrmDealTypeLabel($dealType) . "\n"
            . implode("\n", $lines) . "\n"
            . "上記はお客様ご自身が入力・確認された最優先の希望条件です。会話中の他の発言と食い違う場合は、原則この入力内容を最優先の前提として扱ってください。ただし会話の中でお客様が明確に変更を申し出た場合は、その最新の希望を優先してください。";
    } catch (Throwable $e) {
        error_log('chat CRM manual priority context error: ' . $e->getMessage());
        return '';
    }
}

function chatCrmBuildConditionReminder($caseData) {
    if (!chatCrmHasMeaningfulConditions($caseData)) return '';
    $dealType = $caseData['deal_type'] ?? 'purchase';
    $conditions = $caseData['conditions'] ?? [];
    $lines = [];

    if ($dealType === 'sale' || $dealType === 'both') {
        $seller = $conditions['seller'] ?? [];
        $labels = [
            'sale_reason' => '売却理由',
            'sale_price' => '希望価格',
            'minimum_price' => '最低価格',
            'sale_timing' => '売却希望時期',
            'closing_date' => '決済希望日',
            'loan_balance' => 'ローン残債',
            'relocation_plan' => '住み替え予定',
            'post_sale_home' => '売却後の住まい',
            'viewing_availability' => '内覧対応',
        ];
        foreach ($labels as $key => $label) {
            if (!empty($seller[$key])) $lines[] = '・' . $label . '：' . $seller[$key];
        }
        if (!empty($seller['appeal_points'])) $lines[] = '・アピールポイント：' . implode('・', (array)$seller['appeal_points']);
        if (empty($lines)) return '';
        return "現在把握している売却条件です。\n\n" . implode("\n", $lines) . "\n\n変更があれば教えてください。";
    }

    $buyer = $conditions['buyer'] ?? [];
    $labels = [
        'purchase_timing' => '購入時期',
        'move_in_date' => '引越希望日',
        'budget_max' => '購入予算上限',
        'walk_minutes' => '駅徒歩',
        'property_type' => '種別',
        'layout' => '間取り',
        'area_min' => '面積',
        'building_age' => '築年数',
        'renovation_preference' => 'リノベーション希望',
        'purchase_reason' => '購入理由',
    ];
    foreach ($labels as $key => $label) {
        if (!empty($buyer[$key])) $lines[] = '・' . $label . '：' . $buyer[$key];
    }
    if (!empty($buyer['areas'])) $lines[] = '・希望エリア：' . implode('・', (array)$buyer['areas']);
    if (!empty($buyer['station_lines'])) $lines[] = '・希望沿線：' . implode('・', (array)$buyer['station_lines']);
    if (!empty($buyer['stations'])) $lines[] = '・希望駅：' . implode('・', (array)$buyer['stations']);
    if (empty($lines)) return '';
    return "現在の希望条件はこちらです。\n\n" . implode("\n", $lines) . "\n\n変更があれば教えてください。";
}

function chatCrmMarkConditionReminderShown($db, $sessionId, $businessCardId) {
    if (!$db instanceof PDO || !preg_match('/^[A-Fa-f0-9-]{36}$/', (string)$sessionId) || (int)$businessCardId <= 0) return false;
    try {
        ensureChatCrmCasesTable($db);
        $stmt = $db->prepare("UPDATE chat_crm_cases SET last_condition_reminder_at = CURRENT_TIMESTAMP WHERE session_id = ? AND business_card_id = ?");
        return $stmt->execute([$sessionId, (int)$businessCardId]);
    } catch (Throwable $e) {
        error_log('chat CRM reminder mark error: ' . $e->getMessage());
        return false;
    }
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

        // 【自動取込ポリシー】
        // 条件整理へ自動反映するのは「チャット側から質問して回答された内容（＝structured_data）」のみ。
        // 会話中の自由発言テキストからは取り込まない（＝以前の chat_messages 全文抽出は廃止）。
        // また、条件整理に手入力された値は本人の最優先希望とみなし、上書きしない（fill-only）。
        $fill = function ($current, $incoming) {
            if ($current !== null && $current !== '' && $current !== []) return $current;
            if ($incoming === null || $incoming === '') return $current;
            return $incoming;
        };

        // 賃貸は現状チャット側の質問フローが未対応のため、structured取込はスキップし手入力を保持する。
        if ($dealType !== 'rent') {
            $structuredIsSale = ($structured['customer_type'] ?? '') === 'sale'
                || !empty($structured['selling_timing'])
                || !empty($structured['loan_balance']);
            // 相談種別は手入力を優先。デフォルト（purchase）のままで、チャット回答が売却を示す場合のみ切替。
            if ($dealType === 'purchase' && $structuredIsSale) {
                $dealType = 'sale';
            }

            if ($dealType === 'sale' || $dealType === 'both') {
                $conditions['seller']['sale_reason'] = $fill($conditions['seller']['sale_reason'] ?? '', $structured['selling_reason'] ?? '');
                $conditions['seller']['sale_timing'] = $fill($conditions['seller']['sale_timing'] ?? '', $structured['selling_timing'] ?? '');
                $conditions['seller']['closing_date'] = $fill($conditions['seller']['closing_date'] ?? '', $structured['move_date'] ?? '');
                $conditions['seller']['sale_price'] = $fill($conditions['seller']['sale_price'] ?? '', $structured['desired_sale_price'] ?? '');
                $conditions['seller']['minimum_price'] = $fill($conditions['seller']['minimum_price'] ?? '', $structured['minimum_price'] ?? '');
                $conditions['seller']['loan_balance'] = $fill($conditions['seller']['loan_balance'] ?? '', $structured['loan_balance'] ?? '');
                $conditions['seller']['relocation_plan'] = $fill($conditions['seller']['relocation_plan'] ?? '', $structured['replacement_plan'] ?? '');
                $conditions['seller']['post_sale_home'] = $fill($conditions['seller']['post_sale_home'] ?? '', $structured['temporary_housing'] ?? '');
                if (empty($conditions['seller']['appeal_points']) && !empty($structured['appeal_points'])) {
                    $conditions['seller']['appeal_points'] = $structured['appeal_points'];
                }
            }

            if ($dealType === 'purchase' || $dealType === 'both') {
                $conditions['buyer']['purchase_timing'] = $fill($conditions['buyer']['purchase_timing'] ?? '', $structured['purchase_timing'] ?? '');
                $conditions['buyer']['move_in_date'] = $fill($conditions['buyer']['move_in_date'] ?? '', $structured['move_date'] ?? '');
                $conditions['buyer']['budget_max'] = $fill($conditions['buyer']['budget_max'] ?? '', $structured['budget_max'] ?? ($structured['desired_loan_amount'] ?? ''));
                $structuredAreas = [];
                $structuredStations = (array)($structured['preferred_station'] ?? []);
                $structuredLines = array_merge((array)($structured['preferred_station_line'] ?? []), (array)($structured['preferred_line'] ?? []));
                foreach ((array)($structured['preferred_area'] ?? []) as $areaValue) {
                    $areaValue = trim((string)$areaValue);
                    if ($areaValue === '') continue;
                    if (preg_match('/駅$/u', $areaValue)) $structuredStations[] = $areaValue;
                    elseif (preg_match('/線$/u', $areaValue)) $structuredLines[] = $areaValue;
                    else $structuredAreas[] = $areaValue;
                }
                $conditions['buyer']['areas'] = chatCrmMergeUnique($conditions['buyer']['areas'] ?? [], $structuredAreas);
                $conditions['buyer']['station_lines'] = chatCrmMergeUnique($conditions['buyer']['station_lines'] ?? [], $structuredLines);
                $conditions['buyer']['stations'] = chatCrmMergeUnique($conditions['buyer']['stations'] ?? [], $structuredStations);
                $conditions['buyer']['areas'] = array_values(array_filter((array)($conditions['buyer']['areas'] ?? []), function ($value) {
                    return !preg_match('/(?:駅|線)$/u', (string)$value);
                }));
                $conditions['buyer']['walk_minutes'] = $fill($conditions['buyer']['walk_minutes'] ?? '', $structured['station_walk_minutes'] ?? '');
                $conditions['buyer']['property_type'] = $fill($conditions['buyer']['property_type'] ?? '', $structured['property_type'][0] ?? ($structured['property_type'] ?? ''));
                $conditions['buyer']['layout'] = $fill($conditions['buyer']['layout'] ?? '', $structured['layout'] ?? '');
                $conditions['buyer']['area_min'] = $fill($conditions['buyer']['area_min'] ?? '', $structured['preferred_area_size'] ?? '');
                $conditions['buyer']['building_age'] = $fill($conditions['buyer']['building_age'] ?? '', $structured['building_age'] ?? '');
                $conditions['buyer']['renovation_preference'] = $fill($conditions['buyer']['renovation_preference'] ?? '', $structured['renovation_preference'] ?? '');
                $conditions['buyer']['purchase_reason'] = $fill($conditions['buyer']['purchase_reason'] ?? '', $structured['reason_for_move'] ?? '');
            }
        }

        $summary = trim((string)($current['ai_summary'] ?? ''));
        if (!empty($memory['last_summary'])) {
            $summary = (string)$memory['last_summary'];
        } elseif (!empty($structured['summary_for_sales'])) {
            $summary = (string)$structured['summary_for_sales'];
        }

        $progress = $current['progress'];
        $progress['deal_type'] = $dealType;
        if ($dealType === 'sale' && !empty($conditions['seller']['closing_date'])) {
            $progress['target_date'] = $conditions['seller']['closing_date'];
        } elseif ($dealType !== 'sale' && $dealType !== 'rent' && !empty($conditions['buyer']['move_in_date'])) {
            $progress['target_date'] = $conditions['buyer']['move_in_date'];
        }
        if ($dealType === 'rent') {
            // 賃貸は購入/売却スケジュールの対象外。進捗ステージは持たない。
            $progress['stages'] = [];
        } elseif ($dealType === 'sale') {
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

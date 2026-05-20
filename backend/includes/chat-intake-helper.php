<?php
/**
 * Scenario-based intake/hearing engine for real estate chat.
 * Saves structured data into chat_leads.structured_data while keeping normal chat logs.
 */

function chatIntakeDefaultData() {
    return [
        'customer_type' => null,
        'temperature' => 'low',
        'preferred_area' => [],
        'preferred_station' => [],
        'preferred_line' => [],
        'commute_destination' => null,
        'budget_min' => null,
        'budget_max' => null,
        'competitor_viewing_status' => null,
        'viewed_property_count' => null,
        'preferred_area_size' => null,
        'layout' => null,
        'property_type' => null,
        'purchase_timing' => null,
        'selling_timing' => null,
        'loan_status' => null,
        'loan_balance' => null,
        'desired_sale_price' => null,
        'minimum_price' => null,
        'sublease_status' => null,
        'sublease_cancelable' => null,
        'loan_simulation_used' => null,
        'simulation_loan_amount' => null,
        'simulation_monthly_payment' => null,
        'simulation_interest_type' => null,
        'competitor_status' => null,
        'disclosure_flags' => [],
        'priority' => [],
        'summary_for_sales' => null,
        'next_action' => null,
        'missing_fields' => [],
        'customer_name' => null,
        'customer_phone' => null,
        'customer_email' => null,
        'customer_contact_raw' => null,
        'customer_line' => null,
        'preferred_contact_method' => null,
        'preferred_contact_value' => null,
        'contact_status' => 'anonymous',
        'contact_consent' => false,
        'move_date' => null,
        'schedule_progress' => null,
        'delay_alert' => null,
        'next_task' => null,
        'completed_tasks' => [],
        '_current_field' => 'customer_type',
        '_asked_fields' => [],
        '_button_selection_archive' => [],
        '_updated_at' => date('c'),
    ];
}

function chatIntakeTypeChoices() {
    return [
        ['label' => '家を買いたい', 'value' => 'purchase'],
        ['label' => '住み替えを検討', 'value' => 'replacement'],
        ['label' => '家を売りたい', 'value' => 'sale'],
        ['label' => '投資物件を買いたい', 'value' => 'investment_buy'],
        ['label' => '投資物件を売りたい', 'value' => 'investment_sale'],
        ['label' => '相場を知りたい', 'value' => 'market'],
        ['label' => '住宅ローン相談', 'value' => 'loan'],
        ['label' => '相続関連の相談', 'value' => 'inheritance'],
        ['label' => 'その他', 'value' => 'other'],
    ];
}

function chatIntakeMultiSelectFields() {
    return ['priority', 'property_type', 'disclosure_flags', 'other_debts', 'loan_concern', 'preferred_contact'];
}

function chatIntakeIsMultiSelectField($field) {
    return in_array($field, chatIntakeMultiSelectFields(), true);
}

function chatIntakeQuickRepliesForField($field) {
    $defs = chatIntakeFieldDefinitions();
    $choices = $defs[$field]['choices'] ?? [];
    if (!chatIntakeIsMultiSelectField($field)) return $choices;
    return array_map(function ($choice) use ($field) {
        $choice['multi_select'] = true;
        $choice['field'] = $field;
        return $choice;
    }, $choices);
}

function chatIntakeFieldDefinitions() {
    return [
        'customer_type' => ['question' => 'まず、今回のご相談内容に近いものを教えてください。', 'choices' => chatIntakeTypeChoices()],
        'preferred_area' => ['question' => 'ご希望のエリアは決まっていますか。例: 世田谷区、横浜市、中野坂上周辺など、分かる範囲で大丈夫です。', 'choices' => [['label' => 'まだ未定', 'value' => '未定']]],
        'preferred_station_line' => ['question' => '最寄り駅や沿線の希望はありますか。複数あればそのまま入力してください。', 'choices' => [['label' => 'まだ未定', 'value' => '未定']]],
        'commute_destination' => ['question' => '通勤・通学で重視したい場所はありますか。勤務先駅、学校、実家などでも大丈夫です。', 'choices' => [['label' => '特になし', 'value' => '特になし']]],
        'budget' => ['question' => 'ご予算のイメージを教えてください。例: 6,000万〜7,000万円、まだ未定など。', 'choices' => [['label' => 'まだ未定', 'value' => '未定']]],
        'competitor_viewing_status' => ['question' => 'すでに他社で物件の内覧をされていますか。', 'choices' => [['label' => 'はい', 'value' => 'yes'], ['label' => 'いいえ', 'value' => 'no']]],
        'viewed_property_count' => ['question' => 'これまでに内覧した物件数を教えてください。', 'choices' => [['label' => '1〜2件', 'value' => '1-2'], ['label' => '3〜5件', 'value' => '3-5'], ['label' => '6〜10件', 'value' => '6-10'], ['label' => '10件以上', 'value' => '10+']]],
        'preferred_area_size' => ['question' => 'ご希望の広さ（㎡数）の範囲はありますか。', 'choices' => [['label' => '50㎡未満', 'value' => '<50'], ['label' => '50〜70㎡', 'value' => '50-70'], ['label' => '70〜90㎡', 'value' => '70-90'], ['label' => '90〜120㎡', 'value' => '90-120'], ['label' => '120㎡以上', 'value' => '120+'], ['label' => '未定', 'value' => '未定']]],
        'layout' => ['question' => 'ご希望の間取りを教えてください。', 'choices' => [['label' => '1LDK', 'value' => '1LDK'], ['label' => '2LDK', 'value' => '2LDK'], ['label' => '3LDK', 'value' => '3LDK'], ['label' => '4LDK以上', 'value' => '4LDK以上'], ['label' => '未定', 'value' => '未定']]],
        'property_type' => ['question' => '物件種別の希望はありますか。複数選択できます。', 'choices' => [['label' => 'マンション', 'value' => 'マンション'], ['label' => '戸建て', 'value' => '戸建て'], ['label' => 'どちらも検討', 'value' => 'どちらも検討'], ['label' => '土地', 'value' => '土地']]],
        'station_walk_minutes' => ['question' => '駅からの距離はどの程度まで許容できますか。', 'choices' => [['label' => '5分以内', 'value' => '5'], ['label' => '10分以内', 'value' => '10'], ['label' => '15分以内', 'value' => '15'], ['label' => 'バス可', 'value' => 'bus'], ['label' => '未定', 'value' => '未定']]],
        'priority' => ['question' => '住まい選びで重視したいことは何ですか。複数選択できます。', 'choices' => [['label' => '利便性', 'value' => '利便性'], ['label' => '広さ', 'value' => '広さ'], ['label' => '学区', 'value' => '学区'], ['label' => '資産価値', 'value' => '資産価値'], ['label' => '静かな環境', 'value' => '静かな環境'], ['label' => '価格', 'value' => '価格']]],
        'family_structure' => ['question' => 'ご家族構成を差し支えない範囲で教えてください。', 'choices' => [['label' => '単身', 'value' => '単身'], ['label' => '夫婦', 'value' => '夫婦'], ['label' => '子どもあり', 'value' => '子どもあり'], ['label' => '親と同居予定', 'value' => '親と同居予定'], ['label' => '回答しない', 'value' => '未回答']]],
        'purchase_timing' => ['question' => 'いつ頃までにお引越しを希望されていますか。', 'choices' => [['label' => 'すぐ', 'value' => 'すぐ'], ['label' => '3か月以内', 'value' => '3か月以内'], ['label' => '半年以内', 'value' => '半年以内'], ['label' => '1年以内', 'value' => '1年以内'], ['label' => '未定', 'value' => '未定']]],
        'loan_status' => ['question' => '住宅ローンの状況を教えてください。', 'choices' => [['label' => '事前審査済', 'value' => '事前審査済'], ['label' => 'これから', 'value' => 'これから'], ['label' => '現金購入', 'value' => '現金購入'], ['label' => 'わからない', 'value' => 'わからない']]],
        'renovation_preference' => ['question' => 'リフォームについての希望はありますか。', 'choices' => [['label' => 'リフォーム済希望', 'value' => 'リフォーム済希望'], ['label' => '自分でリフォームしたい', 'value' => '自分でリフォームしたい'], ['label' => 'どちらでもよい', 'value' => 'どちらでもよい']]],
        'current_property_type' => ['question' => '現在のお住まいはどのような物件ですか。', 'choices' => [['label' => 'マンション', 'value' => 'マンション'], ['label' => '戸建て', 'value' => '戸建て'], ['label' => '土地', 'value' => '土地'], ['label' => 'その他', 'value' => 'その他']]],
        'selling_strategy' => ['question' => '買い替えの進め方はどちらを希望されていますか。', 'choices' => [['label' => '売却先行', 'value' => '売却先行'], ['label' => '購入先行', 'value' => '購入先行'], ['label' => '同時進行', 'value' => '同時進行'], ['label' => '未定', 'value' => '未定']]],
        'property_location' => ['question' => '物件の所在地を教えてください。住所に抵抗があれば、市区町村やマンション名まででも大丈夫です。', 'choices' => []],
        'selling_reason' => ['question' => '売却理由に近いものを教えてください。', 'choices' => [['label' => '住み替え', 'value' => '住み替え'], ['label' => '相続', 'value' => '相続'], ['label' => '資産整理', 'value' => '資産整理'], ['label' => '高齢者施設', 'value' => '高齢者施設'], ['label' => '離婚', 'value' => '離婚'], ['label' => 'その他', 'value' => 'その他']]],
        'selling_timing' => ['question' => '売却希望時期はありますか。', 'choices' => [['label' => 'すぐ', 'value' => 'すぐ'], ['label' => '3か月以内', 'value' => '3か月以内'], ['label' => '半年以内', 'value' => '半年以内'], ['label' => '1年以内', 'value' => '1年以内'], ['label' => '未定', 'value' => '未定']]],
        'loan_balance' => ['question' => '住宅ローン残債はありますか。金額が分からなければ「あり」「なし」「不明」だけで大丈夫です。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => 'わからない', 'value' => 'わからない']]],
        'minimum_price' => ['question' => '最低希望価格や売却希望価格はありますか。未定でも大丈夫です。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'appraisal_status' => ['question' => '過去に査定を受けたことはありますか。', 'choices' => [['label' => '初めて', 'value' => '初めて'], ['label' => '1社', 'value' => '1社'], ['label' => '複数社', 'value' => '複数社'], ['label' => '媒介契約中', 'value' => '媒介契約中']]],
        'appraisal_request' => ['question' => '無料査定を希望されますか。', 'choices' => [['label' => '希望する', 'value' => '希望する'], ['label' => 'まず相場だけ', 'value' => 'まず相場だけ'], ['label' => 'まだ検討中', 'value' => 'まだ検討中']]],
        'disclosure_flags' => ['question' => '告知事項として気になる点はありますか。複数選択できます。', 'choices' => [['label' => '雨漏り', 'value' => '雨漏り'], ['label' => 'シロアリ', 'value' => 'シロアリ'], ['label' => '事故・孤独死', 'value' => '事故・孤独死'], ['label' => '騒音', 'value' => '騒音'], ['label' => '越境', 'value' => '越境'], ['label' => '特になし', 'value' => '特になし'], ['label' => '不明', 'value' => '不明']]],
        'preferred_contact' => ['question' => '連絡希望方法を教えてください。複数選択できます。', 'choices' => [['label' => '電話', 'value' => '電話'], ['label' => 'メール', 'value' => 'メール'], ['label' => 'LINE', 'value' => 'LINE'], ['label' => 'チャット継続', 'value' => 'チャット継続']]],
        'investment_type' => ['question' => '希望する投資物件の種別を教えてください。', 'choices' => [['label' => '区分マンション', 'value' => '区分マンション'], ['label' => '一棟アパート', 'value' => '一棟アパート'], ['label' => '一棟マンション', 'value' => '一棟マンション'], ['label' => '未定', 'value' => '未定']]],
        'owner_change_ok' => ['question' => 'オーナーチェンジ物件も検討対象ですか。', 'choices' => [['label' => '対象', 'value' => '対象'], ['label' => '対象外', 'value' => '対象外'], ['label' => '内容による', 'value' => '内容による']]],
        'target_yield' => ['question' => '希望利回りはありますか。未定でも大丈夫です。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'sublease_status' => ['question' => '現在、サブリース契約はありますか。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => '不明', 'value' => '不明']]],
        'sublease_cancelable' => ['question' => '売却にあたって、サブリース契約を外すことは可能ですか。', 'choices' => [['label' => '可能', 'value' => '可能'], ['label' => '不可', 'value' => '不可'], ['label' => '要確認', 'value' => '要確認'], ['label' => '不明', 'value' => '不明']]],
        'rent_income' => ['question' => '現在の年間賃料または月額賃料は分かりますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'income' => ['question' => 'ご年収の目安を教えてください。概算で大丈夫です。', 'choices' => [['label' => '答えたくない', 'value' => '未回答']]],
        'employment_type' => ['question' => 'ご勤務形態を教えてください。', 'choices' => [['label' => '会社員', 'value' => '会社員'], ['label' => '公務員', 'value' => '公務員'], ['label' => '自営業', 'value' => '自営業'], ['label' => '会社経営者', 'value' => '会社経営者'], ['label' => '契約社員', 'value' => '契約社員'], ['label' => 'その他', 'value' => 'その他']]],
        'years_employed' => ['question' => '勤続年数を教えてください。', 'choices' => [['label' => '1年未満', 'value' => '1年未満'], ['label' => '1〜3年', 'value' => '1〜3年'], ['label' => '3年以上', 'value' => '3年以上']]],
        'down_payment' => ['question' => '自己資金の目安はありますか。未定でも大丈夫です。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'desired_loan_amount' => ['question' => '希望借入額はありますか。未定でも大丈夫です。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'other_debts' => ['question' => '他のお借入れはありますか。複数選択できます。', 'choices' => [['label' => '車', 'value' => '車'], ['label' => 'カードローン', 'value' => 'カードローン'], ['label' => '教育ローン', 'value' => '教育ローン'], ['label' => 'なし', 'value' => 'なし'], ['label' => '答えたくない', 'value' => '未回答']]],
        'pre_approval_status' => ['question' => '事前審査は済んでいますか。', 'choices' => [['label' => '済', 'value' => '済'], ['label' => 'これから', 'value' => 'これから'], ['label' => '否決経験あり', 'value' => '否決経験あり'], ['label' => 'わからない', 'value' => 'わからない']]],
        'loan_concern' => ['question' => '住宅ローンで気になる点は何ですか。複数選択できます。', 'choices' => [['label' => '借入可能額', 'value' => '借入可能額'], ['label' => '月々返済', 'value' => '月々返済'], ['label' => '金利', 'value' => '金利'], ['label' => 'ペアローン', 'value' => 'ペアローン'], ['label' => '団信', 'value' => '団信'], ['label' => '転職後', 'value' => '転職後']]],
        'loan_simulation_used' => ['question' => '住宅ローンシミュレーターで月々返済額などを試算してみますか。', 'choices' => [['label' => '利用する', 'value' => 'yes'], ['label' => 'あとで利用する', 'value' => 'later'], ['label' => '不要', 'value' => 'no']]],
        'consultation_reason' => ['question' => '相場を知りたい理由に近いものを教えてください。', 'choices' => [['label' => '売却検討', 'value' => '売却検討'], ['label' => '相続', 'value' => '相続'], ['label' => '資産把握', 'value' => '資産把握'], ['label' => '住み替え', 'value' => '住み替え'], ['label' => '興味本位', 'value' => '興味本位']]],
        'report_request' => ['question' => '今後、相場変動レポートを受け取りたいですか。', 'choices' => [['label' => '希望する', 'value' => '希望する'], ['label' => '検討する', 'value' => '検討する'], ['label' => '不要', 'value' => '不要']]],
        'move_date' => ['question' => '引っ越し希望日や決済・引渡希望日はありますか。例: 2026-09-30 のように入力できます。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'contact_request' => ['question' => 'ここまでで主な内容は整理できました。担当者から具体的なご案内を希望される場合は、お名前とご希望の連絡方法を自由に入力してください。
例：山田太郎／メール yamada@example.com、電話 090-xxxx-xxxx、LINE ID xxxx など。
もちろん、匿名のままチャットを続けることもできます。', 'choices' => [['label' => '今回は匿名のまま', 'value' => 'anonymous']]],
    ];
}

function chatIntakeScenarioFields($customerType) {
    $map = [
        'purchase' => ['preferred_area', 'preferred_station_line', 'commute_destination', 'budget', 'competitor_viewing_status', 'viewed_property_count', 'preferred_area_size', 'layout', 'property_type', 'station_walk_minutes', 'priority', 'family_structure', 'purchase_timing', 'loan_status', 'renovation_preference', 'move_date', 'contact_request'],
        'replacement' => ['current_property_type', 'selling_strategy', 'property_location', 'loan_balance', 'minimum_price', 'preferred_area', 'budget', 'selling_reason', 'purchase_timing', 'competitor_status', 'move_date', 'contact_request'],
        'sale' => ['property_location', 'property_type', 'selling_reason', 'selling_timing', 'loan_balance', 'minimum_price', 'appraisal_status', 'appraisal_request', 'disclosure_flags', 'preferred_contact', 'move_date', 'contact_request'],
        'investment_buy' => ['investment_type', 'owner_change_ok', 'preferred_area', 'budget', 'target_yield', 'loan_status', 'purchase_timing', 'competitor_status', 'contact_request'],
        'investment_sale' => ['property_location', 'property_type', 'sublease_status', 'sublease_cancelable', 'rent_income', 'loan_balance', 'minimum_price', 'selling_reason', 'competitor_status', 'move_date', 'contact_request'],
        'loan' => ['income', 'employment_type', 'years_employed', 'down_payment', 'desired_loan_amount', 'other_debts', 'pre_approval_status', 'loan_concern', 'loan_simulation_used', 'contact_request'],
        'market' => ['property_location', 'property_type', 'consultation_reason', 'selling_timing', 'report_request', 'contact_request'],
        'inheritance' => ['property_location', 'property_type', 'consultation_reason', 'selling_timing', 'report_request', 'contact_request'],
        'other' => ['property_location', 'property_type', 'preferred_contact', 'contact_request'],
    ];
    return $map[$customerType] ?? [];
}

function chatIntakeLoad($db, $sessionId, $businessCardId) {
    $stmt = $db->prepare('SELECT structured_data FROM chat_leads WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $data = chatIntakeDefaultData();
    if ($row && !empty($row['structured_data'])) {
        $decoded = json_decode($row['structured_data'], true);
        if (is_array($decoded)) $data = array_merge($data, $decoded);
    }
    return $data;
}

function ensureChatLeadContactTable($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS chat_lead_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id CHAR(36) NOT NULL UNIQUE,
        business_card_id INT NOT NULL,
        customer_name VARCHAR(255) NULL,
        contact_method VARCHAR(50) NULL,
        contact_value VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(255) NULL,
        line_id VARCHAR(255) NULL,
        raw_contact TEXT NULL,
        consent_given TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
        INDEX idx_chat_lead_contacts_card (business_card_id),
        INDEX idx_chat_lead_contacts_method (contact_method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function chatIntakeSaveContact($db, $sessionId, $businessCardId, $data) {
    if (empty($data['contact_consent']) || empty($data['customer_contact_raw'])) return;
    ensureChatLeadContactTable($db);
    $method = $data['preferred_contact_method'] ?? null;
    $value = $data['preferred_contact_value'] ?? $data['customer_contact_raw'];
    $stmt = $db->prepare("INSERT INTO chat_lead_contacts
        (session_id, business_card_id, customer_name, contact_method, contact_value, phone, email, line_id, raw_contact, consent_given)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            business_card_id = VALUES(business_card_id),
            customer_name = VALUES(customer_name),
            contact_method = VALUES(contact_method),
            contact_value = VALUES(contact_value),
            phone = VALUES(phone),
            email = VALUES(email),
            line_id = VALUES(line_id),
            raw_contact = VALUES(raw_contact),
            consent_given = 1,
            updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([
        $sessionId,
        $businessCardId,
        $data['customer_name'] ?? null,
        $method,
        $value,
        $data['customer_phone'] ?? null,
        $data['customer_email'] ?? null,
        $data['customer_line'] ?? null,
        $data['customer_contact_raw'] ?? null,
    ]);
}

function chatIntakeSave($db, $sessionId, $businessCardId, $data) {
    $data['_updated_at'] = date('c');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $consent = !empty($data['contact_consent']) ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO chat_leads (session_id, business_card_id, structured_data, consent_given)
                          VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE structured_data = VALUES(structured_data), consent_given = VALUES(consent_given), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$sessionId, $businessCardId, $json, $consent]);
    chatIntakeSaveContact($db, $sessionId, $businessCardId, $data);
}

function chatIntakeArchiveButtonSelection($db, $sessionId, $businessCardId, $selection, $message = '') {
    if (!is_array($selection)) return;

    $cleanText = function ($value, $limit = 200) {
        $value = trim((string)$value);
        if ($value === '') return null;
        return mb_substr($value, 0, $limit);
    };

    $entry = [
        'label' => $cleanText($selection['label'] ?? $message),
        'value' => $cleanText($selection['value'] ?? ''),
        'field' => $cleanText($selection['field'] ?? '', 80),
        'multi_select' => !empty($selection['multi_select']),
        'message' => $cleanText($message, 500),
        'created_at' => date('c'),
    ];

    if (!empty($selection['selected']) && is_array($selection['selected'])) {
        $entry['selected'] = [];
        foreach (array_slice($selection['selected'], 0, 20) as $item) {
            if (!is_array($item)) continue;
            $label = $cleanText($item['label'] ?? '');
            if ($label === null) continue;
            $entry['selected'][] = [
                'label' => $label,
                'value' => $cleanText($item['value'] ?? '') ?: $label,
                'field' => $cleanText($item['field'] ?? '', 80),
            ];
        }
    }

    if ($entry['label'] === null && empty($entry['selected'])) return;

    $data = chatIntakeLoad($db, $sessionId, $businessCardId);
    $archive = $data['_button_selection_archive'] ?? [];
    if (!is_array($archive)) $archive = [];
    $archive[] = $entry;
    $data['_button_selection_archive'] = array_slice($archive, -100);
    chatIntakeSave($db, $sessionId, $businessCardId, $data);
}

function chatIntakeInitialPayload($agentName) {
    return [
        'initial_message' => "こんにちは。24時間365日、担当「{$agentName}」に代わって、AI{$agentName}が不動産のご相談を承ります。\n\nご希望条件の整理や今後の進め方をサポートするため、必要に応じて少しずつご質問します。答えられる範囲だけで大丈夫です。\n\nまず、今回のご相談内容に近いものを教えてください。",
        'quick_replies' => chatIntakeTypeChoices(),
    ];
}

function chatIntakeNormalizeChoiceValue($field, $message) {
    $defs = chatIntakeFieldDefinitions();
    $choices = $defs[$field]['choices'] ?? [];
    $mapOne = function ($raw) use ($choices) {
        $raw = trim((string)$raw);
        foreach ($choices as $choice) {
            if ($raw === $choice['label'] || $raw === $choice['value']) return $choice['value'];
        }
        return $raw;
    };

    if (chatIntakeIsMultiSelectField($field)) {
        $parts = preg_split('/\s*(?:、|,|，|\/|・|\n)+\s*/u', (string)$message, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts || count($parts) <= 1) {
            $parts = [(string)$message];
        }
        $values = array_map($mapOne, $parts);
        return array_values(array_unique(array_filter($values, function ($v) { return trim((string)$v) !== ''; })));
    }

    return $mapOne($message);
}

function chatIntakeSetContact(&$data, $value) {
    $value = trim((string)$value);
    if ($value === '' || $value === 'anonymous' || $value === '今回は匿名のまま' || $value === '匿名') {
        $data['contact_status'] = 'anonymous';
        $data['contact_consent'] = false;
        return;
    }

    $data['customer_contact_raw'] = $value;
    $data['contact_status'] = 'provided';
    $data['contact_consent'] = true;

    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $value, $m)) {
        $data['customer_email'] = $m[0];
    }
    if (preg_match('/(?:\+81[-\s]?)?0\d{1,4}[-\s]?\d{1,4}[-\s]?\d{3,4}/u', $value, $m)) {
        $data['customer_phone'] = preg_replace('/\s+/u', '', $m[0]);
    }
    if (preg_match('/(?:LINE|ライン)\s*(?:ID|id)?\s*[:：]?\s*([A-Za-z0-9_.\-@]{3,})/u', $value, $m)) {
        $data['customer_line'] = $m[1];
    }

    if (!empty($data['customer_email'])) {
        $data['preferred_contact_method'] = 'email';
        $data['preferred_contact_value'] = $data['customer_email'];
    } elseif (!empty($data['customer_phone'])) {
        $data['preferred_contact_method'] = 'phone';
        $data['preferred_contact_value'] = $data['customer_phone'];
    } elseif (!empty($data['customer_line'])) {
        $data['preferred_contact_method'] = 'line';
        $data['preferred_contact_value'] = $data['customer_line'];
    } else {
        $data['preferred_contact_method'] = 'other';
        $data['preferred_contact_value'] = $value;
    }

    $name = $value;
    if (!empty($data['customer_email'])) $name = str_replace($data['customer_email'], '', $name);
    if (!empty($data['customer_phone'])) $name = str_replace($data['customer_phone'], '', $name);
    if (!empty($data['customer_line'])) $name = str_replace($data['customer_line'], '', $name);
    $name = preg_replace('/(?:電話|TEL|tel|メール|mail|LINE|ライン|連絡先|[:：])/u', ' ', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name !== '' && mb_strlen($name) <= 60) {
        $data['customer_name'] = $name;
    }
}

function chatIntakeSetField(&$data, $field, $value) {
    if ($field === '') return;
    if (is_array($value)) {
        $values = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $value)), function ($v) { return $v !== ''; })));
        if (empty($values)) return;
    } else {
        $value = trim((string)$value);
        if ($value === '') return;
        $values = [$value];
    }

    if ($field === 'preferred_area') $data['preferred_area'] = $value === '未定' ? [] : array_values(array_unique(array_merge($data['preferred_area'] ?? [], [$value])));
    elseif ($field === 'preferred_station_line') $data['preferred_station'] = $value === '未定' ? [] : array_values(array_unique(array_merge($data['preferred_station'] ?? [], [$value])));
    elseif ($field === 'budget') {
        if (preg_match_all('/([0-9０-９,\.]+)\s*(億|万|万円|円)?/u', $value, $m) && count($m[1]) >= 1) {
            $nums = array_map(function ($raw) { return (int)str_replace([',', '，'], '', mb_convert_kana($raw, 'n')); }, $m[1]);
            if (count($nums) >= 2) { $data['budget_min'] = min($nums); $data['budget_max'] = max($nums); }
            else { $data['budget_max'] = $nums[0]; }
        } else $data['budget_note'] = $value;
    } elseif (chatIntakeIsMultiSelectField($field)) {
        if (!isset($data[$field]) || !is_array($data[$field])) $data[$field] = [];
        $clearValues = ['特になし', '不明', 'なし', '未回答', 'チャット継続'];
        $selectedClear = array_values(array_intersect($values, $clearValues));
        if (!empty($selectedClear)) {
            $data[$field] = [$selectedClear[0]];
        } else {
            foreach ($values as $item) {
                if (!in_array($item, $data[$field], true)) $data[$field][] = $item;
            }
            $data[$field] = array_values(array_unique($data[$field]));
        }
    } elseif ($field === 'move_date') {
        $data['move_date'] = chatIntakeParseDate($value) ?: ($value === '未定' ? null : $value);
    } elseif ($field === 'contact_request') {
        chatIntakeSetContact($data, $value);
    } else {
        $data[$field] = $value;
    }
    $asked = $data['_asked_fields'] ?? [];
    $asked[] = $field;
    $data['_asked_fields'] = array_values(array_unique($asked));
}

function chatIntakeParseDate($value) {
    $v = mb_convert_kana($value, 'n');
    if (preg_match('/(20\d{2})[-\/年](\d{1,2})[-\/月](\d{1,2})/u', $v, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (preg_match('/(\d{1,2})月(\d{1,2})日/u', $v, $m)) return sprintf('%04d-%02d-%02d', (int)date('Y'), $m[1], $m[2]);
    return null;
}

function chatIntakeNextField($data) {
    if (empty($data['customer_type'])) return 'customer_type';
    $asked = $data['_asked_fields'] ?? [];
    foreach (chatIntakeScenarioFields($data['customer_type']) as $field) {
        if (!in_array($field, $asked, true)) return $field;
    }
    return null;
}

function chatIntakeDisplayValue($value) {
    return is_array($value) ? implode('、', $value) : (string)$value;
}

function chatIntakeScenarioIntro($customerType) {
    $map = [
        'purchase' => '購入のご相談ですね。最初は条件を完璧に固めるより、「エリア・予算・広さ・時期」の優先順位を少しずつ整理すると、物件探しが進めやすくなります。',
        'replacement' => '住み替えのご相談ですね。買い替えは購入条件だけでなく、今のお住まいの売却価格や残債、売却先行か購入先行かで進め方が大きく変わります。',
        'sale' => '売却のご相談ですね。まずは「どの物件を、いつ頃、どのくらいの価格感で」動かしたいかを整理できると、査定や販売戦略につなげやすくなります。',
        'investment_buy' => '投資物件の購入ですね。利回りだけでなく、空室リスク・修繕リスク・融資条件・出口戦略まで見ると判断しやすくなります。',
        'investment_sale' => '投資物件の売却ですね。居住用と違い、買主は収益性や賃貸状況を重視するため、賃料・空室・サブリースの有無が大切です。',
        'loan' => '住宅ローンのご相談ですね。借入可能額だけで決めるより、毎月返済・金利タイプ・自己資金とのバランスで見ると安心です。',
        'market' => '相場のご相談ですね。すぐ売る前提でなくても、現状価格を知っておくと今後の購入・売却・相続の判断材料になります。',
        'inheritance' => '相続関連のご相談ですね。名義や共有者、売却時期によって進め方が変わるため、まずは分かる範囲で状況整理から始めましょう。',
        'other' => '承知しました。内容に合わせて、必要なことだけを少しずつ確認していきます。',
    ];
    return $map[$customerType] ?? 'ありがとうございます。ご相談内容に合わせて、必要な条件を少しずつ整理していきます。';
}

function chatIntakeAdvice($field, $value, $data) {
    $displayValue = chatIntakeDisplayValue($value);
    if ($field === 'customer_type') return chatIntakeScenarioIntro(is_array($value) ? '' : $value);
    if ($field === 'preferred_area') return $displayValue === '未定'
        ? 'エリアがまだ未定でも大丈夫です。最初は「通勤しやすさ」「実家や学校との距離」「予算とのバランス」から候補を広げる方が進めやすいです。'
        : '「' . $displayValue . '」周辺でお考えですね。エリアが見えてくると、価格帯や広さ、駅距離の現実的なバランスもかなり整理しやすくなります。';
    if ($field === 'preferred_station_line') return $displayValue === '未定'
        ? '駅や沿線は後から絞っても問題ありません。生活圏や通勤時間から逆算すると、意外な候補エリアが見つかることもあります。'
        : '駅・沿線の希望も分かりました。駅距離を少し広げるだけで、同じ予算でも広さや築年数の選択肢が増えることがあります。';
    if ($field === 'budget') return 'ご予算感を共有いただきありがとうございます。物件価格だけでなく、諸費用・リフォーム費・毎月返済まで含めて見ると、無理のないラインが見えやすくなります。';
    if ($field === 'competitor_viewing_status' && ($value === 'yes' || $value === 'はい')) return 'すでに内覧を始めているのですね。ここからは条件整理とスピード感がかなり大切です。物件探しでは、最初の数件を比較用として見て、その後に「今までで一番良い」と思える物件が出たら前向きに判断する、という考え方も役立ちます。';
    if ($field === 'competitor_viewing_status') return 'まだ内覧前なのですね。この段階では、先に条件の優先順位を整理しておくと、実際に見学した時に迷いにくくなります。';
    if ($field === 'viewed_property_count') return '内覧件数が分かると、条件が固まり始めている段階か、まだ比較軸を作る段階かを判断しやすくなります。見た物件の「良かった点・合わなかった点」を整理すると、次の提案精度が上がります。';
    if ($field === 'preferred_area_size') return '広さの希望を先に整理しておくと、間取りだけで判断するよりミスマッチを減らせます。同じ3LDKでも、㎡数で暮らしやすさはかなり変わります。';
    if ($field === 'layout') return '間取りの希望も分かりました。実際には、同じ間取りでも収納量やリビングの形で使いやすさが変わるため、広さとセットで見るのがおすすめです。';
    if ($field === 'station_walk_minutes') return '駅距離の許容範囲は、価格と資産性のバランスに直結します。徒歩分数を少し広げられると、広さや築年数で条件が良くなるケースもあります。';
    if (chatIntakeIsMultiSelectField($field)) return 'ありがとうございます。「' . $displayValue . '」を条件整理に反映しました。複数の希望が分かると、何を優先して提案すべきか見えやすくなります。';
    if ($field === 'family_structure') return 'ご家族構成ありがとうございます。人数や将来の変化によって、必要な広さ・学区・収納・駅距離の優先度が変わってきます。';
    if ($field === 'purchase_timing' || $field === 'selling_timing') return '時期感が分かると、今すぐ動くべきことと、少し準備してからでよいことを分けやすくなります。特に3か月以内の場合は、ローンや査定も早めに並行した方が安心です。';
    if ($field === 'loan_status' || $field === 'pre_approval_status') return 'ローン状況も大切な判断材料です。事前審査が済んでいると、良い物件が出た時に申込みまで進めやすくなります。これからの場合も、早めに目安を見ておくと安心です。';
    if ($field === 'renovation_preference') return 'リフォームの考え方も分かりました。中古物件は、リフォーム済みを選ぶか、自分好みに直すかで、総額や入居時期が変わります。';
    if ($field === 'current_property_type') return '現在のお住まいの種別が分かると、売却査定や買い替えスケジュールを組み立てやすくなります。';
    if ($field === 'selling_strategy') return '買い替えは、売却先行なら資金面は安全ですが仮住まいが必要になることがあり、購入先行なら気に入った物件を逃しにくい一方で資金計画の確認が重要です。';
    if ($field === 'property_location') return '所在地ありがとうございます。正確な住所でなくても、市区町村やマンション名が分かるだけで、相場や査定の入り口としてかなり役立ちます。';
    if ($field === 'selling_reason') return '売却理由が分かると、急いだ方がよいのか、価格重視でじっくり進めるべきか、提案の方向性を合わせやすくなります。';
    if ($field === 'loan_balance') return '残債の有無は、売却後に手元資金が残るか、住み替え資金に回せるかを見るうえで重要です。金額が曖昧でも、まず有無だけ分かれば十分です。';
    if ($field === 'minimum_price') return '希望価格がある場合は大切にしつつ、周辺相場との距離も見ておくと販売戦略を立てやすくなります。未定でも問題ありません。';
    if ($field === 'appraisal_status') return '査定経験が分かると、相場把握の段階か、すでに会社比較の段階かが見えます。複数社査定済みの場合は、価格差の理由を見ることが大切です。';
    if ($field === 'appraisal_request') return '査定は「今すぐ売る」ためだけでなく、現状把握としても使えます。まず概算相場を知るだけでも、売却時期や価格戦略を考えやすくなります。';
    if ($field === 'sublease_status') return 'サブリース契約は、買主層や利回り評価、売却価格に影響することがあります。解除可否まで確認できると、売却戦略を立てやすくなります。';
    if ($field === 'sublease_cancelable') return '解除可否まで分かると、投資家にどう見せるか、どの買主層に提案するかを考えやすくなります。';
    if ($field === 'rent_income' || $field === 'target_yield') return '収益情報は投資判断の中心です。ただし利回りだけでなく、空室リスクや修繕費、将来売却しやすいかも一緒に見るのが安全です。';
    if ($field === 'loan_simulation_used') return '月々返済額の目安を把握すると、購入予算を現実的に整理しやすくなります。固定金利と変動金利の差も比較しておくと安心です。';
    if ($field === 'move_date' && !empty($data['move_date'])) return '引っ越し希望日が分かると、内覧・ローン審査・契約・決済までの逆算スケジュールを作りやすくなります。「進捗を見せて」と入力すると現在のタスク確認もできます。';
    if ($field === 'contact_request') return !empty($data['contact_consent']) ? 'ありがとうございます。お名前・ご連絡先を担当者が確認できるように保存しました。いただいた条件とあわせて、次のご案内につなげます。' : '承知しました。匿名のままでも、条件整理や一般的なご相談はこのまま続けられます。';
    return 'ありがとうございます。いただいた内容を条件整理に反映しました。';
}

function chatIntakeNaturalQuestion($field, $data) {
    if (!$field) return '主要な条件はかなり整理できました。担当者が確認できる形で相談内容を保存しました。追加で気になることがあれば、そのまま自由に入力してください。';
    $defs = chatIntakeFieldDefinitions();
    $question = $defs[$field]['question'] ?? '';
    $prefixMap = [
        'preferred_area' => 'まずは物件探しの軸になるエリアから整理したいです。',
        'preferred_station_line' => 'エリアに加えて、駅や沿線の希望があると提案の精度が上がります。',
        'commute_destination' => '日々の移動も住み心地に大きく関わります。',
        'budget' => '次に、無理のない範囲を見立てるために予算感を伺います。',
        'competitor_viewing_status' => '検討の進み具合も把握しておくと、急ぐべきか整理しやすいです。',
        'viewed_property_count' => '見学済みの場合は、比較の進み具合を確認させてください。',
        'preferred_area_size' => '暮らしやすさを考えるうえで、間取りより先に広さの感覚も見ておきたいです。',
        'layout' => '広さのイメージに合わせて、間取りも確認します。',
        'property_type' => '提案対象を絞るために、物件種別も確認させてください。',
        'station_walk_minutes' => '価格と利便性のバランスを見るため、駅距離の許容範囲も伺います。',
        'priority' => '条件の優先順位が分かると、提案がかなり現実的になります。',
        'family_structure' => '差し支えない範囲で、暮らす人数のイメージも教えてください。',
        'purchase_timing' => 'スケジュール感によって、今やるべきことが変わります。',
        'loan_status' => '資金計画の進み具合も、早めに軽く確認しておくと安心です。',
        'renovation_preference' => '中古も視野に入る場合は、リフォームの考え方で候補が変わります。',
        'current_property_type' => '住み替えは、今のお住まいの状況から整理すると分かりやすいです。',
        'selling_strategy' => '買い替えでは、売却と購入の順番がとても大切です。',
        'property_location' => '相場や査定の起点になるため、分かる範囲で場所を確認します。',
        'selling_reason' => '売却理由により、価格重視かスピード重視かが変わります。',
        'selling_timing' => '売却の急ぎ具合も、販売戦略に関わります。',
        'loan_balance' => '手残りや住み替え資金を見るために、残債の有無も確認します。',
        'minimum_price' => '価格面の希望があれば、販売戦略の前提として大切にします。',
        'appraisal_status' => '査定状況が分かると、今の検討段階を把握しやすいです。',
        'appraisal_request' => '必要であれば、査定につなげられる状態まで整理できます。',
        'disclosure_flags' => '売却では、気になる点を早めに整理しておくと後のトラブル予防になります。',
        'preferred_contact' => '担当者につなぐ場合に備えて、ご希望の連絡方法も確認します。',
        'income' => 'ローン相談では、まず概算の年収から無理のない借入目安を見ます。',
        'employment_type' => '審査では勤務形態も見られるため、差し支えない範囲で確認します。',
        'years_employed' => '勤続年数も審査傾向を見る材料になります。',
        'down_payment' => '自己資金が分かると、借入額と諸費用のバランスを見やすくなります。',
        'desired_loan_amount' => '希望借入額がある場合は、返済額の目安と一緒に見ていきます。',
        'other_debts' => '他のお借入れは返済比率に関わるため、答えられる範囲で大丈夫です。',
        'loan_concern' => 'ローンで一番気になる点を先に押さえると、説明が的確になります。',
        'loan_simulation_used' => '必要であれば、シミュレーターで月々返済も整理できます。',
        'move_date' => '希望日があると、逆算スケジュールを作りやすくなります。',
        'contact_request' => 'ここまでの内容を担当者が引き継げるようにする場合だけで大丈夫です。',
    ];
    $prefix = $prefixMap[$field] ?? '続けて、もう少しだけ確認させてください。';
    return $prefix . "\n" . $question;
}

function chatIntakeBuildReply($field, $value, $nextField, $data) {
    return chatIntakeAdvice($field, $value, $data) . "\n\n" . chatIntakeNaturalQuestion($nextField, $data);
}

function chatIntakeEvaluateTemperature(&$data) {
    $score = 0;
    foreach (['purchase_timing', 'selling_timing'] as $f) {
        if (in_array($data[$f] ?? null, ['すぐ', '3か月以内'], true)) $score += 35;
        elseif (($data[$f] ?? null) === '半年以内') $score += 20;
    }
    if (($data['appraisal_request'] ?? null) === '希望する') $score += 35;
    if (($data['loan_status'] ?? null) === '事前審査済' || ($data['pre_approval_status'] ?? null) === '済') $score += 25;
    if (in_array($data['viewed_property_count'] ?? null, ['3-5', '6-10', '10+'], true)) $score += 25;
    if (($data['competitor_viewing_status'] ?? null) === 'yes') $score += 15;
    if (($data['loan_simulation_used'] ?? null) === 'yes') $score += 20;
    if (!empty($data['move_date'])) $score += 15;
    if (!empty($data['contact_consent'])) $score += 20;
    $data['temperature_score'] = min(100, $score);
    $data['temperature'] = $score >= 60 ? 'high' : ($score >= 30 ? 'middle' : 'low');
}

function chatIntakeBuildSummary(&$data) {
    $parts = [];
    if (!empty($data['customer_type'])) $parts[] = '種別: ' . $data['customer_type'];
    if (!empty($data['preferred_area'])) $parts[] = '希望エリア: ' . implode('、', $data['preferred_area']);
    if (!empty($data['budget_min']) || !empty($data['budget_max'])) $parts[] = '予算: ' . ($data['budget_min'] ?? '') . '〜' . ($data['budget_max'] ?? '') . '万円目安';
    if (!empty($data['property_type'])) $parts[] = '物件種別: ' . (is_array($data['property_type']) ? implode('、', $data['property_type']) : $data['property_type']);
    if (!empty($data['purchase_timing'])) $parts[] = '購入時期: ' . $data['purchase_timing'];
    if (!empty($data['selling_timing'])) $parts[] = '売却時期: ' . $data['selling_timing'];
    if (!empty($data['loan_status'])) $parts[] = 'ローン: ' . $data['loan_status'];
    if (!empty($data['move_date'])) $parts[] = '引越/引渡希望: ' . $data['move_date'];
    if (!empty($data['customer_name'])) $parts[] = '顧客名: ' . $data['customer_name'];
    if (!empty($data['customer_phone']) || !empty($data['customer_email'])) $parts[] = '連絡先取得済み';
    $data['summary_for_sales'] = implode(' / ', $parts);
    $data['missing_fields'] = [];
    foreach (chatIntakeScenarioFields($data['customer_type'] ?? '') as $field) {
        if (!in_array($field, $data['_asked_fields'] ?? [], true)) $data['missing_fields'][] = $field;
    }
    $data['next_action'] = chatIntakeNextAction($data);
}

function chatIntakeNextAction($data) {
    if (($data['temperature'] ?? 'low') === 'high') return '担当者から早期連絡。面談・査定・内覧・ローン相談を提案。';
    if (($data['temperature'] ?? 'low') === 'middle') return '条件整理を継続し、相場情報・ローン相談・候補物件提案へつなげる。';
    return '負担の少ない追加質問で接点維持。希望条件と時期を少しずつ確認。';
}

function chatIntakeProgressMessage($data) {
    if (mb_strpos(($data['last_user_message'] ?? ''), '進捗') === false && mb_strpos(($data['last_user_message'] ?? ''), 'スケジュール') === false) return null;
    if (empty($data['move_date']) || $data['move_date'] === '未定') {
        return "進捗管理を作るには、まず引っ越し希望日または決済・引渡希望日が必要です。\n例: 2026-09-30 のように入力してください。";
    }
    $move = new DateTime($data['move_date']);
    $tasks = [
        ['希望条件整理', -120], ['物件内覧開始', -82], ['ローン事前審査', -82], ['買付申込み', -55], ['売買契約', -45], ['住宅ローン本審査', -40], ['金銭消費貸借契約', -15], ['お引渡し・決済', -15], ['お引越し', 0]
    ];
    $done = $data['completed_tasks'] ?? [];
    $lines = ['現在の進捗一覧です。'];
    foreach ($tasks as $task) {
        $d = clone $move; $d->modify($task[1] . ' days');
        $mark = in_array($task[0], $done, true) ? '✅' : '⬜';
        $lines[] = $mark . ' ' . $task[0] . '：' . $d->format('Y-m-d') . '目安';
    }
    $lines[] = '完了したタスクがあれば「' . $tasks[0][0] . ' 完了」のように送ってください。';
    return implode("\n", $lines);
}

function processChatIntakeMessage($db, $sessionId, $businessCardId, $message) {
    $data = chatIntakeLoad($db, $sessionId, $businessCardId);
    $data['last_user_message'] = $message;

    if (preg_match('/(.+?)\s*完了/u', $message, $m)) {
        $task = trim($m[1]);
        if (!isset($data['completed_tasks']) || !is_array($data['completed_tasks'])) $data['completed_tasks'] = [];
        $data['completed_tasks'][] = $task;
        $data['completed_tasks'] = array_values(array_unique($data['completed_tasks']));
    }

    $progress = chatIntakeProgressMessage($data);
    if ($progress !== null) {
        chatIntakeEvaluateTemperature($data);
        chatIntakeBuildSummary($data);
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
        return ['handled' => true, 'reply' => $progress, 'quick_replies' => [], 'data' => $data];
    }

    $field = $data['_current_field'] ?? 'customer_type';
    if ($field === null || $field === '') {
        return ['handled' => false, 'data' => $data];
    }
    $value = chatIntakeNormalizeChoiceValue($field, $message);
    if ($field === 'customer_type') {
        $validTypes = array_map(function ($choice) { return $choice['value']; }, chatIntakeTypeChoices());
        if (is_array($value) || !in_array($value, $validTypes, true)) {
            return ['handled' => false, 'data' => $data];
        }
    }
    chatIntakeSetField($data, $field, $value);
    if ($field === 'customer_type') $data['customer_type'] = $value;
    chatIntakeEvaluateTemperature($data);
    chatIntakeBuildSummary($data);
    $nextField = chatIntakeNextField($data);
    $data['_current_field'] = $nextField;
    chatIntakeSave($db, $sessionId, $businessCardId, $data);

    $defs = chatIntakeFieldDefinitions();
    $reply = chatIntakeBuildReply($field, $value, $nextField, $data);
    $quick = $nextField && isset($defs[$nextField]) ? chatIntakeQuickRepliesForField($nextField) : [];
    return [
        'handled' => true,
        'reply' => $reply,
        'quick_replies' => $quick,
        'data' => $data,
    ];
}


function buildChatLeadContext($data) {
    if (!$data || !is_array($data)) return '';
    $labels = [
        'customer_type' => '相談種別', 'temperature' => '温度感', 'preferred_area' => '希望エリア', 'preferred_station' => '希望駅',
        'commute_destination' => '通勤・通学先', 'budget_min' => '予算下限', 'budget_max' => '予算上限',
        'competitor_viewing_status' => '他社内覧有無', 'viewed_property_count' => '内覧件数', 'preferred_area_size' => '希望㎡数',
        'layout' => '間取り', 'property_type' => '物件種別', 'purchase_timing' => '購入時期', 'selling_timing' => '売却時期',
        'loan_status' => 'ローン状況', 'loan_balance' => 'ローン残債', 'sublease_status' => 'サブリース有無',
        'sublease_cancelable' => 'サブリース解除可否', 'summary_for_sales' => '営業向け要約', 'next_action' => '次アクション',
        'move_date' => '引越/引渡希望日', 'customer_name' => '顧客名', 'customer_phone' => '電話番号',
        'customer_email' => 'メールアドレス', 'customer_line' => 'LINE', 'preferred_contact_method' => '希望連絡方法',
        'preferred_contact' => '希望連絡方法', 'contact_status' => '連絡先状態'
    ];
    $lines = [];
    foreach ($labels as $key => $label) {
        if (!isset($data[$key]) || $data[$key] === null || $data[$key] === '' || $data[$key] === []) continue;
        $value = is_array($data[$key]) ? implode('、', $data[$key]) : $data[$key];
        $lines[] = $label . ': ' . $value;
    }
    if (!empty($data['missing_fields']) && is_array($data['missing_fields'])) {
        $lines[] = '未取得項目: ' . implode('、', array_slice($data['missing_fields'], 0, 8));
    }
    return empty($lines) ? '' : "【構造化ヒアリング情報】\n" . implode("\n", $lines);
}

function getChatLeadContextForPrompt($db, $sessionId) {
    if (!$db || $sessionId === '') return '';
    try {
        $stmt = $db->prepare('SELECT structured_data FROM chat_leads WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $json = $stmt->fetchColumn();
        if (!$json) return '';
        $data = json_decode($json, true);
        return is_array($data) ? buildChatLeadContext($data) : '';
    } catch (Throwable $e) {
        error_log('Chat lead prompt context error: ' . $e->getMessage());
        return '';
    }
}

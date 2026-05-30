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
        'preferred_station_line' => [],
        'preferred_line' => [],
        'commute_destination' => null,
        'purchase_area' => [],
        'budget_min' => null,
        'budget_max' => null,
        'purchase_budget_min' => null,
        'purchase_budget_max' => null,
        'competitor_viewing_status' => null,
        'viewed_property_count' => null,
        'preferred_area_size' => null,
        'layout' => null,
        'property_type' => null,
        'station_walk_minutes' => null,
        'purchase_timing' => null,
        'selling_timing' => null,
        'move_completion_timing' => null,
        'rent_timing' => null,
        'loan_status' => null,
        'loan_balance' => null,
        'desired_sale_price' => null,
        'desired_price' => null,
        'minimum_price' => null,
        'sublease_status' => null,
        'sublease_cancelable' => null,
        'loan_simulation_used' => null,
        'simulation_loan_amount' => null,
        'simulation_monthly_payment' => null,
        'simulation_interest_type' => null,
        'competitor_status' => null,
        'current_property_location' => null,
        'repair_history' => null,
        'appeal_points' => [],
        'building_name' => null,
        'exclusive_area' => null,
        'floor_info' => null,
        'direction' => null,
        'monthly_cost' => null,
        'renovation_history' => null,
        'facility_notes' => null,
        'land_area' => null,
        'building_area' => null,
        'building_age' => null,
        'road_info' => null,
        'coverage_ratio' => null,
        'floor_area_ratio' => null,
        'land_status' => null,
        'boundary_issue' => null,
        'occupancy_status' => null,
        'gross_yield' => null,
        'replacement_plan' => null,
        'age_tolerance' => null,
        'finance_plan' => null,
        'equity' => null,
        'reason_for_move' => null,
        'temporary_housing' => null,
        'tax_consideration' => null,
        'ownership_status' => null,
        'consultation_type' => null,
        'disclosure_flags' => [],
        'priority' => [],
        'summary_for_sales' => null,
        'next_action' => null,
        'missing_fields' => [],
        'customer_name' => null,
        'customer_last_name' => null,
        'customer_first_name' => null,
        'customer_phone' => null,
        'customer_phone_verified' => false,
        'customer_email' => null,
        'customer_contact_raw' => null,
        'customer_line' => null,
        'preferred_contact_method' => null,
        'preferred_contact_value' => null,
        'contact_status' => 'not_requested',
        'contact_consent' => false,
        'move_date' => null,
        'schedule_progress' => null,
        'delay_alert' => null,
        'next_task' => null,
        'completed_tasks' => [],
        '_current_field' => 'customer_type',
        '_intake_mode' => 'guided',
        '_asked_fields' => [],
        '_field_meta' => [],
        '_invalid_inputs' => [],
        '_button_selection_archive' => [],
        '_updated_at' => date('c'),
    ];
}

function chatIntakeTypeChoices() {
    return [
        ['label' => '家を買いたい', 'value' => 'purchase'],
        ['label' => '家を借りたい', 'value' => 'rent'],
        ['label' => '住み替えを検討', 'value' => 'replacement'],
        ['label' => '家を売りたい', 'value' => 'sale'],
        ['label' => '相談だけしたい', 'value' => 'consultation'],
        ['label' => '投資物件を買いたい', 'value' => 'investment_buy'],
        ['label' => '投資物件を売りたい', 'value' => 'investment_sale'],
        ['label' => '相場を知りたい', 'value' => 'market'],
        ['label' => '住宅ローン相談', 'value' => 'loan'],
        ['label' => '相続関連の相談', 'value' => 'inheritance'],
        ['label' => 'その他', 'value' => 'other'],
    ];
}

function chatIntakeMultiSelectFields() {
    return ['priority', 'property_type', 'disclosure_flags', 'other_debts', 'loan_concern', 'preferred_contact', 'appeal_points'];
}

function chatIntakeIsMultiSelectField($field) {
    return in_array($field, chatIntakeMultiSelectFields(), true);
}

function chatIntakeRecommendedChoice($field, $data) {
    $map = [
        'family_structure' => 'family_structure',
        'property_type' => 'property_type',
        'preferred_area_size' => 'preferred_area_size',
        'layout' => 'layout',
        'purchase_timing' => 'purchase_timing',
        'selling_timing' => 'selling_timing',
        'loan_status' => 'loan_status',
        'preferred_contact' => 'preferred_contact',
    ];
    $sourceKey = $map[$field] ?? null;
    if ($sourceKey === null || empty($data[$sourceKey])) return null;
    return chatIntakeDisplayValue($data[$sourceKey]);
}

function chatIntakeQuickRepliesForField($field, $data = null) {
    $defs = chatIntakeFieldDefinitions();
    $choices = $defs[$field]['choices'] ?? [];
    if (is_array($data)) {
        $recommended = chatIntakeRecommendedChoice($field, $data);
        if ($recommended !== null) {
            array_unshift($choices, [
                'label' => '前回の内容: ' . $recommended,
                'value' => $recommended,
                'field' => $field,
                'recommended' => true,
            ]);
        }
    }
    if (!chatIntakeIsMultiSelectField($field)) {
        return array_merge($choices, chatIntakeConversationControlChoices($field));
    }
    return array_map(function ($choice) use ($field) {
        $choice['multi_select'] = true;
        $choice['field'] = $field;
        return $choice;
    }, $choices);
}

function chatIntakeConversationControlChoices($field) {
    if (in_array($field, ['contact_name', 'contact_email', 'contact_phone', 'contact_request'], true)) return [];
    $choices = [];
    if ($field !== 'customer_type') {
        $choices[] = ['label' => 'あとで答える', 'value' => 'あとで答える'];
    }
    $choices[] = ['label' => '自由に質問する', 'value' => '自由に質問する'];
    return $choices;
}

function chatIntakeFieldDefinitions() {
    return [
        'customer_type' => ['question' => 'まず、今回のご相談内容に近いものを選べます。選びにくい場合は「自由に質問する」を押すか、そのまま文章でご相談ください。', 'choices' => chatIntakeTypeChoices()],
        'preferred_area' => ['question' => '担当者に共有する条件として、まず希望エリアを1つだけ確認させてください。駅名・市区町村名・沿線名など、分かる範囲で大丈夫です。', 'choices' => [['label' => '駅名で決めたい', 'value' => '駅名で決めたい'], ['label' => '市区町村で決めたい', 'value' => '市区町村で決めたい'], ['label' => '通勤時間で決めたい', 'value' => '通勤時間で決めたい'], ['label' => 'まだ全く決まっていない', 'value' => 'まだ全く決まっていない']]],
        'preferred_station_line' => ['question' => '最寄り駅や沿線の希望はありますか。複数あればそのまま入力してください。', 'choices' => [['label' => 'まだ未定', 'value' => '未定']]],
        'commute_destination' => ['question' => '通勤・通学で重視したい場所はありますか。勤務先駅、学校、実家などでも大丈夫です。', 'choices' => [['label' => '特になし', 'value' => '特になし']]],
        'budget' => ['question' => '次に、予算感だけ教えてください。例: 6,000万〜7,000万円、月々返済10万円台、まだ未定など。', 'choices' => [['label' => 'まだ未定', 'value' => '未定']]],
        'competitor_viewing_status' => ['question' => 'すでに他社で物件の内覧をされていますか。', 'choices' => [['label' => 'はい', 'value' => 'yes'], ['label' => 'いいえ', 'value' => 'no']]],
        'viewed_property_count' => ['question' => 'これまでに内覧した物件数を教えてください。', 'choices' => [['label' => '1〜2件', 'value' => '1-2'], ['label' => '3〜5件', 'value' => '3-5'], ['label' => '6〜10件', 'value' => '6-10'], ['label' => '10件以上', 'value' => '10+']]],
        'preferred_area_size' => ['question' => 'ご希望の広さ（㎡数）の範囲はありますか。', 'choices' => [['label' => '50㎡未満', 'value' => '<50'], ['label' => '50〜70㎡未満', 'value' => '50-70'], ['label' => '70〜90㎡未満', 'value' => '70-90'], ['label' => '90〜120㎡未満', 'value' => '90-120'], ['label' => '120㎡以上', 'value' => '120+'], ['label' => '未定', 'value' => '未定']]],
        'layout' => ['question' => 'ご希望の間取りを教えてください。', 'choices' => [['label' => '1LDK', 'value' => '1LDK'], ['label' => '2LDK', 'value' => '2LDK'], ['label' => '3LDK', 'value' => '3LDK'], ['label' => '4LDK以上', 'value' => '4LDK以上'], ['label' => '未定', 'value' => '未定']]],
        'property_type' => ['question' => '物件種別の希望はありますか。複数選択できます。', 'choices' => [['label' => 'マンション', 'value' => 'マンション'], ['label' => '戸建て', 'value' => '戸建て'], ['label' => '土地', 'value' => '土地'], ['label' => '投資用', 'value' => '投資用']]],
        'station_walk_minutes' => ['question' => '駅からの距離はどの程度まで許容できますか。', 'choices' => [['label' => '5分以内', 'value' => '5分'], ['label' => '10分以内', 'value' => '10分'], ['label' => '15分以内', 'value' => '15分'], ['label' => 'バス可', 'value' => 'bus'], ['label' => '未定', 'value' => '未定']]],
        'priority' => ['question' => '住まい選びで最も重視したいことは何ですか。複数選択できます。', 'choices' => [['label' => '利便性', 'value' => '利便性'], ['label' => '広さ', 'value' => '広さ'], ['label' => '学区', 'value' => '学区'], ['label' => '資産価値', 'value' => '資産価値'], ['label' => '静かな環境', 'value' => '静かな環境'], ['label' => '価格', 'value' => '価格'], ['label' => 'その他', 'value' => 'その他']]],
        'family_structure' => ['question' => 'ご家族構成を差し支えない範囲で教えてください。', 'choices' => [['label' => '単身', 'value' => '単身'], ['label' => '夫婦', 'value' => '夫婦'], ['label' => '子どもあり', 'value' => '子どもあり'], ['label' => '親と同居予定', 'value' => '親と同居予定'], ['label' => 'その他', 'value' => 'その他'], ['label' => '回答しない', 'value' => '未回答']]],
        'purchase_timing' => ['question' => '検討時期はいつ頃のイメージですか。', 'choices' => [['label' => 'すぐ', 'value' => 'すぐ'], ['label' => '3か月以内', 'value' => '3か月以内'], ['label' => '半年以内', 'value' => '半年以内'], ['label' => '1年以内', 'value' => '1年以内'], ['label' => '未定', 'value' => '未定']]],
        'loan_status' => ['question' => '住宅ローンの状況を教えてください。', 'choices' => [['label' => '事前審査済', 'value' => '事前審査済'], ['label' => 'これから', 'value' => 'これから'], ['label' => '現金購入', 'value' => '現金購入'], ['label' => 'わからない', 'value' => 'わからない']]],
        'renovation_preference' => ['question' => 'リフォームについての希望はありますか。', 'choices' => [['label' => 'リフォーム済希望', 'value' => 'リフォーム済希望'], ['label' => '自分でリフォームしたい', 'value' => '自分でリフォームしたい'], ['label' => 'どちらでもよい', 'value' => 'どちらでもよい']]],
        'current_property_type' => ['question' => '現在のお住まいはどのような物件ですか。', 'choices' => [['label' => 'マンション', 'value' => 'マンション'], ['label' => '戸建て', 'value' => '戸建て'], ['label' => '土地', 'value' => '土地'], ['label' => 'その他', 'value' => 'その他']]],
        'current_property_location' => ['question' => '現在のお住まいの所在地を教えてください。住所に抵抗があれば、市区町村やマンション名まででも大丈夫です。', 'choices' => [['label' => '市区町村まで', 'value' => '市区町村まで']]],
        'selling_strategy' => ['question' => '買い替えの進め方はどちらを希望されていますか。', 'choices' => [['label' => '売却先行', 'value' => '売却先行'], ['label' => '購入先行', 'value' => '購入先行'], ['label' => '同時進行', 'value' => '同時進行'], ['label' => '未定', 'value' => '未定']]],
        'property_location' => ['question' => '物件の所在地を教えてください。住所に抵抗があれば、市区町村やマンション名まででも大丈夫です。', 'choices' => [['label' => '市区町村まで', 'value' => '市区町村まで'], ['label' => '今は不明', 'value' => '不明']]],
        'selling_reason' => ['question' => '売却理由に近いものを教えてください。', 'choices' => [['label' => '住み替え', 'value' => '住み替え'], ['label' => '相続', 'value' => '相続'], ['label' => '資産整理', 'value' => '資産整理'], ['label' => '高齢者施設', 'value' => '高齢者施設'], ['label' => '離婚', 'value' => '離婚'], ['label' => '賃貸へ転居', 'value' => '賃貸へ転居'], ['label' => 'その他', 'value' => 'その他']]],
        'selling_timing' => ['question' => '売却希望時期はありますか。', 'choices' => [['label' => 'すぐ', 'value' => 'すぐ'], ['label' => '3か月以内', 'value' => '3か月以内'], ['label' => '半年以内', 'value' => '半年以内'], ['label' => '1年以内', 'value' => '1年以内'], ['label' => '未定', 'value' => '未定']]],
        'loan_balance' => ['question' => '住宅ローン残債はありますか。金額が分からなければ「あり」「なし」「不明」だけで大丈夫です。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => 'わからない', 'value' => 'わからない']]],
        'minimum_price' => ['question' => '最低希望価格や売却希望価格はありますか。未定でも大丈夫です。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'appraisal_status' => ['question' => '過去に査定を受けたことはありますか。', 'choices' => [['label' => '初めて', 'value' => '初めて'], ['label' => '1社', 'value' => '1社'], ['label' => '複数社', 'value' => '複数社'], ['label' => '媒介契約中', 'value' => '媒介契約中']]],
        'competitor_status' => ['question' => '他社に相談されていますか。', 'choices' => [['label' => '未相談', 'value' => '未相談'], ['label' => '査定済', 'value' => '査定済'], ['label' => '媒介契約中', 'value' => '媒介契約中'], ['label' => '物件見学中', 'value' => '物件見学中'], ['label' => '数件見た', 'value' => '数件見た'], ['label' => '継続的に見ている', 'value' => '継続的に見ている']]],
        'appraisal_request' => ['question' => '無料査定を希望されますか。', 'choices' => [['label' => '希望する', 'value' => '希望する'], ['label' => 'まず相場だけ知りたい', 'value' => 'まず相場だけ'], ['label' => 'まだ検討中', 'value' => 'まだ検討中']]],
        'disclosure_flags' => ['question' => '告知事項として気になる点はありますか。複数選択できます。', 'choices' => [['label' => '雨漏り', 'value' => '雨漏り'], ['label' => 'シロアリ', 'value' => 'シロアリ'], ['label' => '事故・孤独死', 'value' => '事故・孤独死'], ['label' => '騒音', 'value' => '騒音'], ['label' => '越境', 'value' => '越境'], ['label' => '違反建築', 'value' => '違反建築'], ['label' => '特になし', 'value' => '特になし'], ['label' => '不明', 'value' => '不明']]],
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
        'reason_for_move' => ['question' => '買い替え理由に近いものを教えてください。', 'choices' => [['label' => '手狭', 'value' => '手狭'], ['label' => '通勤', 'value' => '通勤'], ['label' => '子育て', 'value' => '子育て'], ['label' => '住環境', 'value' => '住環境'], ['label' => '老後', 'value' => '老後'], ['label' => '資産整理', 'value' => '資産整理'], ['label' => 'その他', 'value' => 'その他']]],
        'temporary_housing' => ['question' => '仮住まいは可能ですか。', 'choices' => [['label' => '可能', 'value' => '可能'], ['label' => 'できれば避けたい', 'value' => 'できれば避けたい'], ['label' => '不可', 'value' => '不可'], ['label' => '未定', 'value' => '未定']]],
        'tax_consideration' => ['question' => '3,000万円特別控除の利用を検討していますか。', 'choices' => [['label' => '検討中', 'value' => '検討中'], ['label' => '使う予定', 'value' => '使う予定'], ['label' => 'わからない', 'value' => 'わからない']]],
        'move_completion_timing' => ['question' => 'いつまでに買い替えを完了したいですか。', 'choices' => [['label' => '3か月以内', 'value' => '3か月以内'], ['label' => '半年以内', 'value' => '半年以内'], ['label' => '1年以内', 'value' => '1年以内'], ['label' => '未定', 'value' => '未定']]],
        'repair_history' => ['question' => 'リフォーム・修繕履歴はありますか。分かる範囲で大丈夫です。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => '不明', 'value' => '不明']]],
        'appeal_points' => ['question' => '物件の良い点を教えてください。複数選択できます。', 'choices' => [['label' => '眺望', 'value' => '眺望'], ['label' => '日当たり', 'value' => '日当たり'], ['label' => '管理状態', 'value' => '管理状態'], ['label' => '駅近', 'value' => '駅近'], ['label' => '静か', 'value' => '静か'], ['label' => 'リフォーム済', 'value' => 'リフォーム済']]],
        'building_name' => ['question' => 'マンション名を教えてください。分かる範囲で大丈夫です。', 'choices' => [['label' => '今は不明', 'value' => '不明']]],
        'exclusive_area' => ['question' => '専有面積は分かりますか。平方メートルで分かる範囲で大丈夫です。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'floor_info' => ['question' => '所在階と総階数を教えてください。例: 5階 / 12階建', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'direction' => ['question' => '方位を教えてください。', 'choices' => [['label' => '南', 'value' => '南'], ['label' => '東', 'value' => '東'], ['label' => '西', 'value' => '西'], ['label' => '北', 'value' => '北'], ['label' => '角部屋', 'value' => '角部屋'], ['label' => '不明', 'value' => '不明']]],
        'monthly_cost' => ['question' => '管理費・修繕積立金は分かりますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'renovation_history' => ['question' => '室内リフォーム履歴はありますか。時期や内容が分かれば教えてください。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => '不明', 'value' => '不明']]],
        'facility_notes' => ['question' => 'ペット可否や駐車場など、特徴はありますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'land_area' => ['question' => '土地面積は分かりますか。㎡または坪で大丈夫です。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'building_area' => ['question' => '建物面積は分かりますか。戸建ての場合のみ、㎡または坪で大丈夫です。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'building_age' => ['question' => '築年月は分かりますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'road_info' => ['question' => '前面道路について分かる範囲で教えてください。幅員、方位、接道状況などです。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'coverage_ratio' => ['question' => '建ぺい率は分かりますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'floor_area_ratio' => ['question' => '容積率は分かりますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'land_status' => ['question' => '土地の状態を教えてください。', 'choices' => [['label' => '更地', 'value' => '更地'], ['label' => '古家あり', 'value' => '古家あり'], ['label' => '居住中', 'value' => '居住中'], ['label' => '賃貸中', 'value' => '賃貸中']]],
        'boundary_issue' => ['question' => '境界・越境で気になる点はありますか。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => '不明', 'value' => '不明']]],
        'occupancy_status' => ['question' => '現在の賃貸状況を教えてください。', 'choices' => [['label' => '賃貸中', 'value' => '賃貸中'], ['label' => '空室', 'value' => '空室'], ['label' => '一部空室', 'value' => '一部空室'], ['label' => '自用', 'value' => '自用'], ['label' => '不明', 'value' => '不明']]],
        'gross_yield' => ['question' => '表面利回りは分かりますか。', 'choices' => [['label' => '不明', 'value' => '不明']]],
        'replacement_plan' => ['question' => '買い替え予定はありますか。', 'choices' => [['label' => 'あり', 'value' => 'あり'], ['label' => 'なし', 'value' => 'なし'], ['label' => '未定', 'value' => '未定']]],
        'age_tolerance' => ['question' => '築年数の許容範囲はありますか。', 'choices' => [['label' => '10年以内', 'value' => '10年以内'], ['label' => '20年以内', 'value' => '20年以内'], ['label' => '築古可', 'value' => '築古可'], ['label' => '未定', 'value' => '未定']]],
        'finance_plan' => ['question' => '融資利用予定はありますか。', 'choices' => [['label' => '利用予定', 'value' => '利用予定'], ['label' => '現金', 'value' => '現金'], ['label' => '未定', 'value' => '未定']]],
        'equity' => ['question' => '自己資金の目安を教えてください。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'ownership_status' => ['question' => '相続の場合、名義や共有者の状況は分かりますか。', 'choices' => [['label' => '単独名義', 'value' => '単独名義'], ['label' => '共有', 'value' => '共有'], ['label' => '相続登記前', 'value' => '相続登記前'], ['label' => '不明', 'value' => '不明']]],
        'simulation_save_consent' => ['question' => 'シミュレーターで試算した借入希望額や毎月返済額を保存してもよろしいですか。', 'choices' => [['label' => '保存する', 'value' => '保存する'], ['label' => '保存しない', 'value' => '保存しない'], ['label' => 'あとで確認', 'value' => 'あとで確認']]],
        'rent_timing' => ['question' => '賃貸のお引越し時期はいつ頃をお考えですか。', 'choices' => [['label' => 'すぐ', 'value' => 'すぐ'], ['label' => '1〜3か月以内', 'value' => '1〜3か月以内'], ['label' => '半年以内', 'value' => '半年以内'], ['label' => '未定', 'value' => '未定']]],
        'move_date' => ['question' => '引っ越し希望日や決済・引渡希望日はありますか。例: 2026-09-30 のように入力できます。', 'choices' => [['label' => '未定', 'value' => '未定']]],
        'contact_name' => ['question' => "前回のご相談内容を引き継ぎ、次回以降も続きからスムーズにご案内できるよう、まずはお名前のご登録をお願いいたします。\n\n【姓】\n【名】\n\n※苗字とお名前は分けてご入力ください。", 'choices' => []],
        'contact_email' => ['question' => "続いて、メールアドレスをご入力ください。\nご登録いただくことで、\n・ご相談内容の引継ぎ\n・別デバイスからのログイン\n・重要なお知らせのお受け取り\nなどが可能になります。\n\n【メールアドレス】\n「　　　　　@　　　　　　　」", 'choices' => []],
        'contact_phone' => ['question' => "最後に、携帯電話番号をご入力ください。\n\nご本人確認のため、SMS認証を行います。\n入力後、SMSで届く認証コードをご入力ください。\n\n【携帯電話番号】090-XXXX-XXXX\n【認証コード】6ケタのコード", 'choices' => [['label' => '携帯電話番号を入力してSMS認証する', 'value' => 'sms_register', 'action' => 'sms_register']]],
        'contact_request' => ['question' => '前回のご相談内容を引き継ぐため、お名前・メールアドレス・携帯電話番号のご登録をお願いいたします。', 'choices' => []],
    ];
}

function chatIntakeScenarioFields($customerType) {
    $map = [
        'purchase' => ['preferred_area', 'budget', 'purchase_timing', 'preferred_area_size', 'layout', 'station_walk_minutes', 'family_structure', 'loan_status', 'property_type', 'preferred_station_line', 'commute_destination', 'priority', 'renovation_preference', 'competitor_viewing_status', 'viewed_property_count', 'contact_name', 'contact_email', 'contact_phone'],
        'replacement' => ['preferred_area', 'budget', 'move_completion_timing', 'current_property_type', 'selling_strategy', 'current_property_location', 'loan_balance', 'minimum_price', 'reason_for_move', 'temporary_housing', 'tax_consideration', 'competitor_status', 'contact_name', 'contact_email', 'contact_phone'],
        'sale' => ['property_location', 'selling_timing', 'minimum_price', 'property_type', 'selling_reason', 'loan_balance', 'appraisal_status', 'appraisal_request', 'disclosure_flags', 'repair_history', 'appeal_points', 'preferred_contact', 'contact_name', 'contact_email', 'contact_phone'],
        'rent' => ['preferred_area', 'budget', 'rent_timing', 'preferred_area_size', 'layout', 'station_walk_minutes', 'family_structure', 'priority', 'contact_name', 'contact_email', 'contact_phone'],
        'consultation' => ['preferred_area', 'budget', 'purchase_timing', 'loan_status', 'preferred_contact', 'contact_name', 'contact_email', 'contact_phone'],
        'investment_buy' => ['preferred_area', 'budget', 'purchase_timing', 'investment_type', 'owner_change_ok', 'target_yield', 'age_tolerance', 'finance_plan', 'equity', 'competitor_status', 'contact_name', 'contact_email', 'contact_phone'],
        'investment_sale' => ['property_location', 'property_type', 'occupancy_status', 'sublease_status', 'sublease_cancelable', 'rent_income', 'gross_yield', 'loan_balance', 'desired_price', 'selling_reason', 'replacement_plan', 'competitor_status', 'contact_name', 'contact_email', 'contact_phone'],
        'loan' => ['income', 'employment_type', 'years_employed', 'down_payment', 'desired_loan_amount', 'other_debts', 'pre_approval_status', 'loan_concern', 'loan_simulation_used', 'simulation_save_consent', 'contact_name', 'contact_email', 'contact_phone'],
        'market' => ['property_location', 'property_type', 'consultation_reason', 'selling_timing', 'report_request', 'contact_name', 'contact_email', 'contact_phone'],
        'inheritance' => ['property_location', 'property_type', 'consultation_reason', 'selling_timing', 'ownership_status', 'report_request', 'contact_name', 'contact_email', 'contact_phone'],
        'other' => ['property_location', 'property_type', 'preferred_contact', 'contact_name', 'contact_email', 'contact_phone'],
    ];
    return $map[$customerType] ?? [];
}

function chatIntakeInsertFieldsAfter($fields, $afterField, $insertFields) {
    $pos = array_search($afterField, $fields, true);
    if ($pos === false) return array_merge($fields, $insertFields);
    return array_merge(array_slice($fields, 0, $pos + 1), $insertFields, array_slice($fields, $pos + 1));
}

function chatIntakeScenarioFieldsForData($data) {
    $fields = chatIntakeScenarioFields($data['customer_type'] ?? '');
    $propertyType = chatIntakeDisplayValue($data['property_type'] ?? '');
    if (($data['customer_type'] ?? '') === 'sale') {
        $insertFields = [];
        if (mb_strpos($propertyType, 'マンション') !== false) {
            $insertFields = array_merge($insertFields, ['building_name', 'exclusive_area', 'floor_info', 'direction', 'monthly_cost', 'renovation_history', 'facility_notes']);
        }
        if (mb_strpos($propertyType, '戸建') !== false || mb_strpos($propertyType, '土地') !== false) {
            $insertFields = array_merge($insertFields, ['land_area', 'building_area', 'building_age', 'road_info', 'coverage_ratio', 'floor_area_ratio', 'land_status', 'boundary_issue']);
        }
        if (!empty($insertFields)) {
            $fields = chatIntakeInsertFieldsAfter($fields, 'property_type', $insertFields);
        }
    }
    return array_values(array_unique($fields));
}

function chatIntakeCurrentFieldForData($data) {
    $field = $data['_current_field'] ?? null;
    if (($field === null || $field === '' || $field === 'customer_type') && !empty($data['customer_type'])) {
        $next = chatIntakeNextField($data);
        if ($next !== null && $next !== '') return $next;
    }
    if ($field === null || $field === '') return chatIntakeNextField($data);
    return $field;
}

function chatIntakeQuickRepliesForCurrentField($data) {
    $field = chatIntakeCurrentFieldForData($data);
    $defs = chatIntakeFieldDefinitions();
    return $field && isset($defs[$field]) ? chatIntakeQuickRepliesForField($field, $data) : [];
}

function chatIntakeQuestionForCurrentField($data) {
    $field = chatIntakeCurrentFieldForData($data);
    $defs = chatIntakeFieldDefinitions();
    return $field && isset($defs[$field]) ? ($defs[$field]['question'] ?? '') : '';
}

function chatIntakeResumePayload($db, $sessionId, $businessCardId) {
    $data = chatIntakeLoad($db, $sessionId, $businessCardId);
    $mode = $data['_intake_mode'] ?? 'guided';
    $field = $mode === 'free' ? null : chatIntakeCurrentFieldForData($data);
    if (($data['_current_field'] ?? null) !== $field) {
        $data['_current_field'] = $field;
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
    }
    $canAskNext = $mode !== 'free' && $field !== null && $field !== '';
    return [
        'current_field' => $field,
        'current_question' => $canAskNext ? chatIntakeQuestionForCurrentField($data) : '',
        'quick_replies' => $canAskNext ? chatIntakeQuickRepliesForCurrentField($data) : [],
        'can_ask_next' => $canAskNext,
        'intake_mode' => $mode,
        'data' => $data,
    ];
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
    if (!empty($data['customer_phone'])) {
        if (!function_exists('chatRegisterVerifiedPhone')) {
            $phoneHelper = __DIR__ . '/chat-phone-helper.php';
            if (file_exists($phoneHelper)) require_once $phoneHelper;
        }
        if (function_exists('chatRegisterVerifiedPhone')) {
            chatRegisterVerifiedPhone($db, $businessCardId, $data['customer_phone'], '', $sessionId, $data['customer_name'] ?? null);
        }
    }
}

function chatIntakeSave($db, $sessionId, $businessCardId, $data) {
    $data['_updated_at'] = date('c');
    $storedData = $data;
    unset($storedData['last_user_message'], $storedData['_invalid_inputs']);
    if (isset($storedData['_field_meta']) && is_array($storedData['_field_meta'])) {
        foreach ($storedData['_field_meta'] as $metaField => $meta) {
            if (is_array($meta) && ($meta['status'] ?? '') === 'invalid') unset($storedData['_field_meta'][$metaField]);
        }
    }
    foreach ($storedData as $field => $storedValue) {
        if ($field === '' || strpos((string)$field, '_') === 0) continue;
        if (is_array($storedValue)) {
            $filtered = [];
            foreach ($storedValue as $item) {
                if ($item === null || $item === '' || chatIntakeLooksLikeLowInformationInput($item, $field)) continue;
                $filtered[] = $item;
            }
            $storedData[$field] = array_values(array_unique($filtered));
        } elseif (chatIntakeLooksLikeLowInformationInput($storedValue, $field)) {
            $storedData[$field] = null;
        }
    }
    $json = json_encode($storedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $consent = !empty($data['contact_consent']) ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO chat_leads (session_id, business_card_id, structured_data, consent_given)
                          VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE structured_data = VALUES(structured_data), consent_given = VALUES(consent_given), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$sessionId, $businessCardId, $json, $consent]);
    chatIntakeSaveContact($db, $sessionId, $businessCardId, $data);
}

function chatIntakeNormalizeInputText($value) {
    $value = trim((string)$value);
    $value = mb_convert_kana($value, "asKV");
    $value = preg_replace("/[\\s　]+/u", " ", $value);
    return trim($value);
}

function chatIntakeIsUnknownAnswer($value) {
    $plain = chatIntakeNormalizeInputText($value);
    $plain = preg_replace("/[\s　。、，．・！？?！「」『』（）()【】\[\]{}]+/u", "", $plain);
    if ($plain === "") return true;
    $unknowns = ["未定", "わからない", "分からない", "判らない", "不明", "まだ", "まだ未定", "まだ決まっていない", "決まっていない", "決めていない", "未確認", "知らない", "特になし", "なし", "回答しない", "未回答", "あとで", "後で", "あとで答える", "後で答える", "スキップ", "飛ばす"];
    return in_array($plain, $unknowns, true);
}

function chatIntakeLooksLikeLowInformationInput($value, $field = null) {
    $display = chatIntakeNormalizeInputText(chatIntakeDisplayValue($value));
    if ($display === "") return true;
    if ($field !== null && chatIntakeMatchesDefinedChoice($field, $display)) return false;
    if (chatIntakeIsUnknownAnswer($display)) return false;

    $plain = preg_replace("/[\s　[:punct:]。、，．・！？?！「」『』（）()【】\[\]{}]+/u", "", $display);
    if ($plain === "") return true;
    if (mb_strlen($plain) <= 1 && !preg_match("/[0-9０-９]/u", $plain)) return true;
    if (preg_match("/^(.)\\1{2,}$/u", $plain)) return true;
    if (preg_match("/^(?:test|asdf|qwerty|dummy|sample|abc|xxx|aaa|ok)$/iu", $plain)) return true;
    if (preg_match("/(?:意味不明|意味わから|意味がわから|分からん|わからん|何でもいい|なんでもいい|適当|テスト|ダミー|サンプル|よろしく|お願いします|お願い|こんにちは|ありがとう)$/u", $plain)) return true;

    $placeFields = ["preferred_area", "preferred_station_line", "property_location", "current_property_location"];
    if (in_array((string)$field, $placeFields, true)) {
        if (preg_match("/^[A-Za-z]{2,}$/u", $plain)) return true;
        if (!preg_match("/[一-龥ぁ-んァ-ンA-Za-z0-9０-９]/u", $plain)) return true;
        if (!preg_match("/(?:駅|線|沿線|市|区|町|村|都|道|府|県|丁目|番地|号|マンション|周辺|エリア|[一-龥ぁ-んァ-ン]{2,})/u", $plain)) return true;
    }

    return false;
}

function chatIntakeAreaPlanningChoices() {
    return ["駅名で決めたい", "市区町村で決めたい", "通勤時間で決めたい", "まだ全く決まっていない"];
}

function chatIntakeIsAreaPlanningChoice($value) {
    return in_array(chatIntakeNormalizeInputText($value), chatIntakeAreaPlanningChoices(), true);
}

function chatIntakeRecordFieldMeta(&$data, $field, $value, $confidence, $status, $raw = "", $source = "typed", $reason = "") {
    if ($field === null || $field === "") return;
    if (!isset($data["_field_meta"]) || !is_array($data["_field_meta"])) $data["_field_meta"] = [];
    $entry = [
        "field" => $field,
        "value" => chatIntakeDisplayValue($value),
        "confidence" => $confidence,
        "status" => $status,
        "source" => $source,
        "raw" => mb_substr(trim((string)$raw), 0, 500),
        "reason" => $reason,
        "updated_at" => date("c"),
    ];
    $data["_field_meta"][$field] = $entry;
    if ($status === "invalid") {
        if (!isset($data["_invalid_inputs"]) || !is_array($data["_invalid_inputs"])) $data["_invalid_inputs"] = [];
        $data["_invalid_inputs"][] = $entry;
        $data["_invalid_inputs"] = array_slice($data["_invalid_inputs"], -50);
    }
}

function chatIntakeFieldMeta($data, $field) {
    return is_array($data["_field_meta"] ?? null) && is_array($data["_field_meta"][$field] ?? null) ? $data["_field_meta"][$field] : null;
}

function chatIntakeIsConfirmedField($data, $field) {
    $meta = chatIntakeFieldMeta($data, $field);
    if ($meta === null) return true;
    return ($meta["status"] ?? "") === "confirmed" && in_array(($meta["confidence"] ?? ""), ["high", "medium"], true);
}

function chatIntakeValidationResult($confidence, $status, $saveValue, $normalizedValue = null, $reason = "", $markAsked = false) {
    return [
        "confidence" => $confidence,
        "status" => $status,
        "save_value" => $saveValue,
        "normalized_value" => $normalizedValue,
        "reason" => $reason,
        "mark_asked" => $markAsked,
    ];
}

function chatIntakeValidateFieldValue($field, $value, $data, $fromButton = false, $source = "typed") {
    $display = chatIntakeNormalizeInputText(chatIntakeDisplayValue($value));
    if ($display === "") return chatIntakeValidationResult("invalid", "invalid", false, null, "empty", false);
    if ($field !== "customer_type" && chatIntakeLooksLikeAccidentalShortInput($display, $field)) {
        return chatIntakeValidationResult("invalid", "invalid", false, null, "too_short", false);
    }
    if ($field !== "customer_type" && chatIntakeLooksLikeLowInformationInput($display, $field)) {
        return chatIntakeValidationResult("invalid", "invalid", false, null, "low_information", false);
    }

    if ($field === "customer_type") {
        $valid = array_map(function ($choice) { return $choice["value"]; }, chatIntakeTypeChoices());
        return in_array((string)$value, $valid, true)
            ? chatIntakeValidationResult("high", "confirmed", true, $value, "", true)
            : chatIntakeValidationResult("invalid", "invalid", false, null, "not_a_customer_type", false);
    }

    if (in_array($field, ["contact_name", "contact_email", "contact_phone", "contact_request"], true)) {
        return chatIntakeValidationResult("high", "confirmed", true, $value, "", true);
    }

    if ($field === "preferred_area") {
        if (chatIntakeIsAreaPlanningChoice($display)) {
            return chatIntakeValidationResult("low", "unconfirmed", false, $display, "area_planning_choice", true);
        }
        if (chatIntakeIsUnknownAnswer($display)) {
            return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_area", true);
        }
        $area = chatIntakeExtractPreferredArea($display) ?: $display;
        $compact = preg_replace("/[\\s　]+/u", "", $area);
        if (mb_strlen($compact) < 2) return chatIntakeValidationResult("invalid", "invalid", false, null, "area_too_short", false);
        if (!preg_match("/[一-龥ぁ-んァ-ンA-Za-z0-9０-９]/u", $compact)) return chatIntakeValidationResult("invalid", "invalid", false, null, "area_not_readable", false);
        $confidence = preg_match("/(駅|線|沿線|市|区|町|村|都|道|府|県|周辺|エリア)$/u", $compact) ? "high" : "medium";
        return chatIntakeValidationResult($confidence, "confirmed", true, $area, "", true);
    }

    if ($field === "preferred_station_line") {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_station", true);
        $confidence = preg_match("/(駅|線|沿線)$/u", $display) ? "high" : "medium";
        return chatIntakeValidationResult($confidence, "confirmed", true, $display, "", true);
    }

    if ($field === "budget") {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_budget", true);
        if (!preg_match("/[0-9０-９]/u", $display) && !preg_match("/月々|返済|予算|価格|万円|億|円/u", $display)) {
            return chatIntakeValidationResult("low", "needs_confirmation", false, $display, "budget_not_readable", false);
        }
        return chatIntakeValidationResult("high", "confirmed", true, $display, "", true);
    }

    if (in_array($field, ["purchase_timing", "selling_timing", "move_completion_timing", "rent_timing"], true)) {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_timing", true);
        if (!preg_match("/すぐ|今|来月|再来月|[0-9０-９一二三四五六七八九十]+\\s*(?:か月|ヶ月|カ月|年|月|日)|半年|年内|春|夏|秋|冬/u", $display)) {
            return chatIntakeValidationResult("low", "needs_confirmation", false, $display, "timing_not_readable", false);
        }
        return chatIntakeValidationResult("high", "confirmed", true, $display, "", true);
    }

    if ($field === "layout") {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_layout", true);
        if (!preg_match("/[1-5１-５]\\s*(?:LDK|SLDK|DK|K|R)|ワンルーム|未定/u", $display)) {
            return chatIntakeValidationResult("low", "needs_confirmation", false, $display, "layout_not_readable", false);
        }
        return chatIntakeValidationResult("high", "confirmed", true, $display, "", true);
    }

    if ($field === "station_walk_minutes") {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_station_walk", true);
        if (!preg_match("/[0-9０-９]+\\s*分|徒歩|バス|bus/u", $display)) {
            return chatIntakeValidationResult("low", "needs_confirmation", false, $display, "station_walk_not_readable", false);
        }
        return chatIntakeValidationResult("high", "confirmed", true, $display, "", true);
    }

    if ($field === "family_structure") {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_family", true);
        if (!preg_match("/単身|夫婦|子|親|家族|同居|[0-9０-９]+\\s*人/u", $display)) {
            return chatIntakeValidationResult("low", "needs_confirmation", false, $display, "family_not_readable", false);
        }
        return chatIntakeValidationResult("high", "confirmed", true, $display, "", true);
    }

    if ($field === "property_type") {
        if (chatIntakeIsUnknownAnswer($display)) return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown_property_type", true);
        if (!preg_match("/マンション|戸建|一戸建て|土地|投資|アパート|ビル|その他/u", $display)) {
            return chatIntakeValidationResult("low", "needs_confirmation", false, $display, "property_type_not_readable", false);
        }
        return chatIntakeValidationResult("high", "confirmed", true, $display, "", true);
    }

    if (chatIntakeIsUnknownAnswer($display)) {
        return chatIntakeValidationResult("low", "unconfirmed", false, $display, "unknown", true);
    }

    $status = $source === "natural" ? "inferred" : "confirmed";
    $confidence = $source === "natural" ? "medium" : "high";
    return chatIntakeValidationResult($confidence, $status, true, $value, "", true);
}

function chatIntakeBuildClarifyingReply($field, $data, $validation = []) {
    $reason = $validation["reason"] ?? "";
    $note = in_array($reason, ["low_information", "too_short", "area_too_short", "area_not_readable"], true)
        ? "今の内容は条件として読み取れなかったため、担当者共有用の情報には入れません。"
        : "今の内容だけだと、条件として少し判断が難しそうです。";

    if ($field === "preferred_area") {
        return $note . "\n無理に決めなくて大丈夫です。駅名・市区町村名・沿線名など、分かる範囲で教えてください。";
    }
    if ($field === "budget") {
        return $note . "\n金額が未定なら『未定』で大丈夫です。分かる場合だけ、総額や月々返済の目安を教えてください。";
    }
    return $note . "\n答えにくければ『あとで答える』でも大丈夫です。\n" . chatIntakeNaturalQuestion($field, $data);
}

function chatIntakeBuildUnconfirmedReply($field, $value, $nextField, $data) {
    $nextQuestion = chatIntakeNaturalQuestion($nextField, $data);
    if ($field === "preferred_area") {
        return "まだエリアは固まっていない段階ですね。無理に記録せず、担当者へは「エリア未定」と分かる形で共有します。\n\n" . $nextQuestion;
    }
    return "承知しました。ここは未確認情報として扱い、確定条件には入れずに進めます。\n\n" . $nextQuestion;
}

function chatIntakeFieldLabelMap() {
    return [
        "customer_type" => "相談種別", "temperature" => "温度感", "preferred_area" => "希望エリア", "preferred_station" => "希望駅", "preferred_station_line" => "希望駅・沿線",
        "commute_destination" => "通勤・通学先", "budget" => "予算", "budget_min" => "予算下限", "budget_max" => "予算上限", "budget_note" => "予算メモ",
        "property_type" => "物件種別", "preferred_area_size" => "希望㎡数", "layout" => "間取り", "station_walk_minutes" => "駅徒歩許容分数", "family_structure" => "家族構成",
        "purchase_timing" => "購入時期", "selling_timing" => "売却時期", "rent_timing" => "賃貸時期", "move_completion_timing" => "買い替え完了希望時期",
        "loan_status" => "ローン状況", "loan_balance" => "ローン残債", "loan_concern" => "ローン不安", "priority" => "重視条件",
        "renovation_preference" => "リフォーム意向", "current_property_location" => "現居所在地", "property_location" => "物件所在地", "selling_reason" => "売却理由",
        "minimum_price" => "最低希望価格", "desired_price" => "希望価格", "appraisal_status" => "査定状況", "appraisal_request" => "査定希望",
        "disclosure_flags" => "告知事項", "repair_history" => "修繕履歴", "appeal_points" => "物件PRポイント", "preferred_contact" => "希望連絡方法",
        "customer_name" => "顧客名", "customer_phone" => "電話番号", "customer_phone_verified" => "SMS認証済み", "customer_email" => "メールアドレス", "preferred_contact_method" => "希望連絡方法",
        "summary_for_sales" => "営業向け要約", "next_action" => "次アクション", "_intake_mode" => "会話モード",
    ];
}

function chatIntakeClassifiedLeadItems($data) {
    $groups = ["confirmed" => [], "inferred" => [], "needs_confirmation" => [], "invalid" => []];
    if (!$data || !is_array($data)) return $groups;
    $labels = chatIntakeFieldLabelMap();
    foreach ($labels as $key => $label) {
        if (strpos($key, "_") === 0 || in_array($key, ["temperature", "summary_for_sales", "next_action"], true)) continue;
        $hasValue = isset($data[$key]) && $data[$key] !== null && $data[$key] !== "" && $data[$key] !== [];
        if (!$hasValue) continue;
        $meta = chatIntakeFieldMeta($data, $key);
        $status = $meta["status"] ?? "confirmed";
        if ($status === "invalid") continue;
        $confidence = $meta["confidence"] ?? "high";
        $value = chatIntakeDisplayValue($data[$key]);
        if ($value === "") continue;
        if (chatIntakeLooksLikeLowInformationInput($value, $key)) continue;
        $item = ["field" => $key, "label" => $label, "value" => $value, "confidence" => $confidence, "status" => $status, "raw" => $meta["raw"] ?? "", "updated_at" => $meta["updated_at"] ?? ""];
        if ($status === "needs_confirmation") $groups["needs_confirmation"][] = $item;
        elseif ($status === "inferred" || $status === "unconfirmed") $groups["inferred"][] = $item;
        else $groups["confirmed"][] = $item;
    }
    return $groups;
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
        'initial_message' => "こんにちは。24時間365日、担当「{$agentName}」に代わって、AI{$agentName}が不動産のご相談を承ります。\n\n担当者にスムーズにつなげられるように、必要な条件だけを少しずつ整理します。答えられる範囲だけで大丈夫です。\n\nまず、今回のご相談内容に近いものを選べます。選びにくい場合は「自由に質問する」を押すか、そのまま文章でご相談ください。",
        'quick_replies' => chatIntakeQuickRepliesForField('customer_type'),
    ];
}

function chatIntakeUserWantsFreeConversation($message) {
    return (bool)preg_match('/(質問|ヒアリング|聞き取り).*?(やめ|止め|停止|しない|不要)|勝手な質問|任意の質問|自由に質問|自由に相談|普通に質問|普通に相談|このまま質問|このまま相談|質問ばかり|尋問|詰問|意味不明|わかりにくい|分かりにくい|噛み合わない|スキップして相談|stop\s+asking|don[’\'`]?t\s+ask|no\s+more\s+questions/iu', (string)$message);
}

function chatIntakeUserWantsGuidedConversation($message) {
    return (bool)preg_match('/(条件|希望|相談内容).*(整理|聞いて|質問して)|ヒアリング.*(再開|して)|質問.*(再開|して)|最初から整理/u', (string)$message);
}

function chatIntakeLooksLikeUserQuestion($message) {
    $message = trim((string)$message);
    if ($message === '') return false;
    if (preg_match('/[?？]/u', $message)) return true;
    if (preg_match('/(教えて|知りたい|どう|どの|どれ|なぜ|いつ|いくら|できますか|でしょうか|ですか|とは|について|相談したい|悩んで|困って|不安|心配)/u', $message)) return true;
    if (preg_match('/^(what|why|how|when|where|can|could|should|would|tell me|explain)\b/i', $message)) return true;
    return false;
}

function chatIntakeUserRequestsDirectAnswer($message) {
    $message = trim((string)$message);
    if ($message === '') return false;
    return (bool)preg_match('/(こちら|こっち|先ほど|さっき|前|上|私|自分)?の?質問.*(答え|回答|返答)|質問に(答え|回答|返答)|聞いた(?:こと|内容).*(答え|回答|返答)|ちゃんと.*(会話|答え|回答|返答)|普通に答え|会話.*(成立|成り立|噛み合)|話.*(通じ|噛み合)|同じ.*(質問|こと).*(繰り返|聞か)|無視しない|答えてください|回答してください/u', $message);
}

function chatIntakeMatchesDefinedChoice($field, $message) {
    $message = trim((string)$message);
    if ($message === '') return false;
    $defs = chatIntakeFieldDefinitions();
    foreach (($defs[$field]['choices'] ?? []) as $choice) {
        if ($message === (string)($choice['label'] ?? '') || $message === (string)($choice['value'] ?? '')) {
            return true;
        }
    }
    return false;
}

function chatIntakeLooksLikeAccidentalShortInput($message, $field = null) {
    $message = trim((string)$message);
    if ($message === '') return false;
    $plain = preg_replace('/[\s　[:punct:]。、，．・！？?！「」『』（）()【】\[\]{}]+/u', '', $message);
    if ($plain === '') return true;
    if ($field !== null && chatIntakeMatchesDefinedChoice($field, $message)) return false;
    if (preg_match('/[0-9０-９]/u', $plain)) return false;
    return mb_strlen($plain) <= 1;
}

function chatIntakeBuildShortInputReply($field, $data) {
    $question = chatIntakeNaturalQuestion($field, $data);
    if ($field === 'preferred_area') {
        return "すみません、希望エリアとしては少し判断が難しそうです。\n駅名や地域名でいうと、どのあたりをご希望でしょうか。\n\n" . $question;
    }
    return "すみません、入力途中かもしれません。\nもう一度、分かる範囲で入力してください。未定の場合は「未定」でも大丈夫です。\n\n" . $question;
}

function chatIntakeBuildFreeConversationReply($agentName = '担当者') {
    $agentLabel = trim((string)$agentName) !== '' ? trim((string)$agentName) : '担当者';
    return "承知しました。こちらから条件整理の質問を続けるのはいったん止めます。\n\nこれまで伺った内容は引き継ぎますので、不動産の購入・売却・ローン・相場など、気になることをそのまま自由にご質問ください。必要な時だけ、担当「{$agentLabel}」へ引き継ぎやすい形で整理します。";
}

function chatIntakeParseNameParts($value) {
    $value = trim((string)$value);
    $value = preg_replace('/【[^】]+】/u', ' ', $value);
    $last = '';
    $first = '';
    if (preg_match('/(?:姓|苗字|名字)\s*[:：]?\s*([^\s　\n]+).*?(?:名|名前)\s*[:：]?\s*([^\s　\n]+)/us', $value, $m)) {
        $last = trim($m[1]);
        $first = trim($m[2]);
    } else {
        $parts = preg_split('/[\s　\/／,，、]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 2) {
            $last = trim($parts[0]);
            $first = trim($parts[1]);
        } elseif (count($parts) === 1 && mb_strlen($parts[0]) >= 2) {
            $name = trim($parts[0]);
            $last = mb_substr($name, 0, 1);
            $first = mb_substr($name, 1);
        }
    }
    return [$last, $first];
}

function chatIntakeExtractEmail($value) {
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', (string)$value, $m)) {
        return mb_substr($m[0], 0, 255);
    }
    return null;
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

function chatIntakeIsSmsRegisterRequest($message, $buttonSelection = null) {
    $message = trim((string)$message);
    if ($message === 'sms_register' || $message === '携帯電話番号を入力してSMS認証する' || $message === 'もう一度SMS認証する') {
        return true;
    }
    if (!is_array($buttonSelection)) return false;
    foreach (['action', 'value', 'label'] as $key) {
        $value = trim((string)($buttonSelection[$key] ?? ''));
        if ($value === 'sms_register' || $value === '携帯電話番号を入力してSMS認証する' || $value === 'もう一度SMS認証する') {
            return true;
        }
    }
    return false;
}

function chatIntakeExtractPhoneCandidate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $normalized = mb_convert_kana($value, 'n');
    if (preg_match('/(?:\+\d{1,3}[\s\-]?)?(?:0\d{1,4}|[1-9]\d{1,3})[\s\-]?\d{2,4}[\s\-]?\d{3,4}/u', $normalized, $m)) {
        $digits = preg_replace('/\D+/', '', $m[0]);
        if (strlen($digits) >= 9 && strlen($digits) <= 15) return trim($m[0]);
    }
    $digits = preg_replace('/\D+/', '', $normalized);
    if (strlen($digits) >= 9 && strlen($digits) <= 15) return $value;
    return null;
}

function chatIntakeSetContact(&$data, $value) {
    $value = trim((string)$value);
    if ($value === '' || $value === 'chat_continue' || $value === 'このままチャットを続ける' || $value === '連絡はまだ不要') {
        $data['contact_status'] = 'not_requested';
        $data['contact_consent'] = false;
        return;
    }
    if ($value === 'anonymous') {
        $data['contact_status'] = 'not_requested';
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

function chatIntakeAmountToManYen($rawNumber, $unit = '') {
    $number = (float)str_replace([',', '，'], '', mb_convert_kana((string)$rawNumber, 'n'));
    $unit = mb_strtolower((string)$unit);
    if ($number <= 0) return null;
    if (mb_strpos($unit, '億') !== false) return (int)round($number * 10000);
    if ($unit === '円' || $unit === 'yen' || $unit === 'jpy') return (int)round($number / 10000);
    if (mb_strpos($unit, '万') !== false) return (int)round($number);
    return $number >= 100000 ? (int)round($number / 10000) : (int)round($number);
}


function chatIntakeMarkAsked(&$data, $field) {
    if ($field === null || $field === '') return;
    $asked = $data['_asked_fields'] ?? [];
    $asked[] = $field;
    $data['_asked_fields'] = array_values(array_unique($asked));
}

function chatIntakeFieldHasValue($data, $field) {
    if ($field === 'budget') return !empty($data['budget_min']) || !empty($data['budget_max']) || !empty($data['budget_note']);
    if ($field === 'preferred_station_line') return !empty($data['preferred_station_line']) || !empty($data['preferred_station']);
    if ($field === 'current_property_location') return !empty($data['current_property_location']);
    if ($field === 'property_location') return !empty($data['property_location']);
    if ($field === 'contact_name') return !empty($data['customer_last_name']) && !empty($data['customer_first_name']);
    if ($field === 'contact_email') return !empty($data['customer_email']);
    if ($field === 'contact_phone') return !empty($data['customer_phone']) && !empty($data['customer_phone_verified']);
    if ($field === 'contact_request') return !empty($data['customer_contact_raw']) || (in_array(($data['contact_status'] ?? ''), ['anonymous', 'not_requested'], true) && in_array('contact_request', $data['_asked_fields'] ?? [], true));
    if (!array_key_exists($field, $data)) return false;
    $value = $data[$field];
    if (is_array($value)) return !empty($value);
    return $value !== null && $value !== '';
}

function chatIntakeExtractPreferredArea($message) {
    $message = trim((string)$message);
    if ($message === '') return null;
    $patterns = [
        '/([一-龥ぁ-んァ-ンA-Za-z0-9０-９・ヶケー\-]{1,30}駅周辺)/u',
        '/([一-龥ぁ-んァ-ンA-Za-z0-9０-９・ヶケー\-]{1,30}周辺)/u',
        '/([一-龥ぁ-んァ-ンA-Za-z0-9０-９・ヶケー\-]{1,30}(?:都|道|府|県|市|区|町|村))/u',
        '/([一-龥ぁ-んァ-ンA-Za-z0-9０-９・ヶケー\-]{1,30}(?:駅|沿線|エリア))/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) return trim($m[1]);
    }
    return null;
}

function chatIntakeExtractNaturalFields($message, $data) {
    $message = trim((string)$message);
    if ($message === '') return [];
    $extracted = [];

    if (empty($data["customer_type"])) {
        if (preg_match("/購入|買いたい|買う|探して|探し|物件を見たい|家を見たい|住まいを探/u", $message)) $extracted["customer_type"] = "purchase";
        elseif (preg_match("/賃貸|借りたい|家を借り|部屋を借り|賃貸物件/u", $message)) $extracted["customer_type"] = "rent";
        elseif (preg_match("/売却|売りたい|売る|査定/u", $message)) $extracted["customer_type"] = "sale";
        elseif (preg_match("/相談だけ|相談のみ|まず相談|まだ相談/u", $message)) $extracted["customer_type"] = "consultation";
    }

    $area = chatIntakeExtractPreferredArea($message);
    if ($area !== null && $area !== '未定') $extracted['preferred_area'] = $area;

    if (preg_match('/一戸建て|戸建て|戸建/u', $message)) $extracted['property_type'] = '戸建て';
    elseif (preg_match('/マンション/u', $message)) $extracted['property_type'] = 'マンション';
    elseif (preg_match('/土地/u', $message)) $extracted['property_type'] = '土地';

    if (preg_match('/築\s*([0-9０-９]+)\s*年\s*(?:位|くらい|程度)?\s*(?:まで|以内|以下|未満)?/u', $message, $m)) {
        $years = mb_convert_kana($m[1], 'n');
        $extracted['age_tolerance'] = $years . '年以内';
    } elseif (preg_match('/築浅/u', $message)) {
        $extracted['age_tolerance'] = '築浅';
    }

    if (preg_match('/予算|価格|[0-9０-９,\.]+\s*(?:億円|億|万円|万|円|yen|jpy)/iu', $message)) {
        $extracted['budget'] = $message;
    }

    if (preg_match('/\b([1-5])\s*(?:LDK|SLDK|DK|K|R)\b/iu', $message, $m)) {
        $extracted['layout'] = strtoupper(mb_convert_kana($m[0], 'a'));
    } elseif (preg_match('/([1-5])\s*部屋/u', mb_convert_kana($message, 'n'), $m)) {
        $extracted['layout'] = $m[1] . 'LDK';
    }

    if (preg_match('/徒歩\s*([0-9０-９]{1,2})\s*分/u', $message, $m)) {
        $minutes = (int)mb_convert_kana($m[1], 'n');
        $extracted['station_walk_minutes'] = (string)$minutes;
    } elseif (preg_match('/バス/u', $message)) {
        $extracted['station_walk_minutes'] = 'bus';
    }

    if (preg_match('/事前審査.*(済|通|承認)|審査済/u', $message)) {
        $extracted['loan_status'] = '事前審査済';
    } elseif (preg_match('/現金購入|キャッシュ/u', $message)) {
        $extracted['loan_status'] = '現金購入';
    } elseif (preg_match('/ローン.*(これから|未定|不安|相談|わからない|分からない)/u', $message)) {
        $extracted['loan_status'] = 'これから';
    }

    if (preg_match('/(すぐ|3か月以内|３か月以内|三か月以内|半年以内|1年以内|１年以内|一年以内|未定)/u', $message, $m)) {
        $timing = strtr($m[1], ['３' => '3', '１' => '1', '三か月以内' => '3か月以内', '一年以内' => '1年以内']);
        if (($data['customer_type'] ?? '') === 'sale') $extracted['selling_timing'] = $timing;
        else $extracted['purchase_timing'] = $timing;
    }

    return $extracted;
}

function chatIntakeApplyNaturalFields(&$data, $message, $currentField = null) {
    $extracted = chatIntakeExtractNaturalFields($message, $data);
    $accepted = [];
    foreach ($extracted as $field => $value) {
        if ($field === "customer_type" && !empty($data["customer_type"])) continue;
        if ($field !== $currentField && chatIntakeFieldHasValue($data, $field)) continue;
        $source = ($field === $currentField) ? "typed" : "natural";
        $validation = chatIntakeValidateFieldValue($field, $value, $data, false, $source);
        $normalized = $validation["normalized_value"] ?? $value;
        if (!empty($validation["save_value"])) {
            chatIntakeSetField($data, $field, $normalized, [
                "confidence" => $validation["confidence"] ?? ($source === "natural" ? "medium" : "high"),
                "status" => $source === "natural" ? "inferred" : ($validation["status"] ?? "confirmed"),
                "raw" => $message,
                "source" => $source,
                "reason" => $validation["reason"] ?? "",
            ]);
            if ($field === "customer_type") $data["customer_type"] = $normalized;
            $accepted[$field] = $normalized;
        } else {
            chatIntakeRecordFieldMeta($data, $field, $normalized, $validation["confidence"] ?? "low", $validation["status"] ?? "needs_confirmation", $message, $source, $validation["reason"] ?? "");
            if (!empty($validation["mark_asked"]) && $field === $currentField) chatIntakeMarkAsked($data, $field);
        }
    }
    return $accepted;
}

function chatIntakeSetField(&$data, $field, $value, $meta = []) {
    if ($field === '') return;
    if (is_array($value)) {
        $values = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $value)), function ($v) { return $v !== ''; })));
        if (empty($values)) return;
    } else {
        $value = trim((string)$value);
        if ($value === '') return;
        $values = [$value];
    }

    if ($field === 'preferred_area') {
        if ($value === '未定') {
            $data['preferred_area'] = [];
        } else {
            $area = chatIntakeExtractPreferredArea($value) ?: $value;
            $data['preferred_area'] = array_values(array_unique(array_merge($data['preferred_area'] ?? [], [$area])));
        }
    }
    elseif ($field === 'preferred_station_line') {
        $data['preferred_station'] = $value === '未定' ? [] : array_values(array_unique(array_merge($data['preferred_station'] ?? [], [$value])));
        $data['preferred_station_line'] = $data['preferred_station'];
    }
    elseif ($field === 'contact_name') {
        [$lastName, $firstName] = chatIntakeParseNameParts($value);
        if ($lastName !== '' && $firstName !== '') {
            $data['customer_last_name'] = $lastName;
            $data['customer_first_name'] = $firstName;
            $data['customer_name'] = $lastName . ' ' . $firstName;
        } else {
            $data['customer_name'] = $value;
        }
    } elseif ($field === 'contact_email') {
        $email = chatIntakeExtractEmail($value);
        if ($email !== null) {
            $data['customer_email'] = $email;
            $data['preferred_contact_method'] = $data['preferred_contact_method'] ?: 'email';
            $data['preferred_contact_value'] = $data['preferred_contact_value'] ?: $email;
        } else {
            $data['customer_email'] = $value;
        }
    } elseif ($field === 'contact_phone') {
        $data['contact_status'] = 'phone_verification_pending';
    }
    elseif ($field === 'budget') {
        if (preg_match_all('/([0-9０-９,\.]+)\s*(億円|億|万円|万|円|yen|jpy)?/iu', $value, $m, PREG_SET_ORDER) && count($m) >= 1) {
            $nums = [];
            foreach ($m as $match) {
                $amount = chatIntakeAmountToManYen($match[1], $match[2] ?? '');
                if ($amount !== null) $nums[] = $amount;
            }
            if (count($nums) >= 2) {
                $data['budget_min'] = min($nums);
                $data['budget_max'] = max($nums);
            } elseif (count($nums) === 1) {
                if (preg_match('/以上|超|over|more than|at least|最低/iu', $value)) {
                    $data['budget_min'] = $nums[0];
                } elseif (preg_match('/以下|未満|under|less than|up to|最大/iu', $value)) {
                    $data['budget_max'] = $nums[0];
                } else {
                    $data['budget_max'] = $nums[0];
                }
            }
        } else $data['budget_note'] = $value;
    } elseif ($field === 'current_property_location') {
        $data['current_property_location'] = $value === '市区町村まで' ? null : $value;
        $data['property_location'] = $data['current_property_location'];
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
    $confidence = $meta["confidence"] ?? "high";
    $status = $meta["status"] ?? "confirmed";
    $raw = $meta["raw"] ?? chatIntakeDisplayValue($value);
    $source = $meta["source"] ?? "typed";
    $reason = $meta["reason"] ?? "";
    chatIntakeRecordFieldMeta($data, $field, $value, $confidence, $status, $raw, $source, $reason);
    chatIntakeMarkAsked($data, $field);
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
    foreach (chatIntakeScenarioFieldsForData($data) as $field) {
        if (in_array($field, $asked, true)) continue;
        if (chatIntakeFieldHasValue($data, $field)) continue;
        return $field;
    }
    return null;
}

function chatIntakeNextFields($data, $limit = 3) {
    if (empty($data['customer_type'])) return ['customer_type'];
    $fields = [];
    $asked = $data['_asked_fields'] ?? [];
    foreach (chatIntakeScenarioFieldsForData($data) as $field) {
        if (in_array($field, $asked, true)) continue;
        if (chatIntakeFieldHasValue($data, $field)) continue;
        $fields[] = $field;
        if (count($fields) >= $limit) break;
    }
    return $fields;
}

function chatIntakeDisplayValue($value) {
    return is_array($value) ? implode('、', $value) : (string)$value;
}

function chatIntakeScenarioIntro($customerType) {
    $map = [
        'purchase' => '購入のご相談ですね。最初は条件を完璧に固めるより、「エリア・予算・時期」を少しずつ整理すると、物件探しが進めやすくなります。',
        'rent' => '賃貸のご相談ですね。まずはエリア・予算・時期を軽く整理すると、担当者も候補を絞りやすくなります。',
        'consultation' => 'まずは相談だけでも大丈夫です。担当者に共有しやすいように、今分かっていることだけ整理していきます。',
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
    if ($field === 'preferred_area') {
        if ($displayValue === '未定') return 'エリアがまだ未定でも大丈夫です。最初は「通勤しやすさ」「実家や学校との距離」「予算とのバランス」から候補を広げる方が進めやすいです。';
        $areaLabel = preg_match('/周辺$/u', $displayValue) ? '「' . $displayValue . '」' : '「' . $displayValue . '」周辺';
        return $areaLabel . 'でお考えですね。エリアが見えてくると、価格帯や広さ、駅距離の現実的なバランスもかなり整理しやすくなります。';
    }
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
    if ($field === 'purchase_timing') {
        if (($data['customer_type'] ?? '') === 'investment_buy') {
            return '時期感が分かると、今すぐ動くべきことと、少し準備してからでよいことを分けやすくなります。特に3か月以内の場合は、融資条件や収支の確認も早めに並行した方が安心です。';
        }
        return '時期感が分かると、今すぐ動くべきことと、少し準備してからでよいことを分けやすくなります。特に3か月以内の場合は、資金計画やローン事前審査も早めに並行した方が安心です。';
    }
    if ($field === 'selling_timing') return '時期感が分かると、今すぐ動くべきことと、少し準備してからでよいことを分けやすくなります。特に3か月以内の場合は、相場確認や査定も早めに並行した方が安心です。';
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
    if ($field === 'contact_name') return !empty($data['customer_name']) ? 'ありがとうございます。お名前を登録しました。' : 'ありがとうございます。';
    if ($field === 'contact_email') return !empty($data['customer_email']) ? 'ありがとうございます。メールアドレスを登録しました。' : 'ありがとうございます。';
    if ($field === 'contact_phone') return '下のボタンから携帯電話番号を入力し、SMS認証を行ってください。';
    if ($field === 'contact_request') return !empty($data['contact_consent']) ? 'ありがとうございます。ご入力内容を保存しました。いただいた条件とあわせて、次のご案内に活用します。' : '承知しました。このままチャットで相談を続けられます。';
    return 'ありがとうございます。いただいた内容を条件整理に反映しました。';
}

function chatIntakeNaturalQuestion($field, $data) {
    if (!$field) return '主要な条件はかなり整理できました。追加で気になることがあれば、そのまま自由に入力してください。';
    $defs = chatIntakeFieldDefinitions();
    $question = $defs[$field]['question'] ?? '';
    if (in_array($field, ['contact_name', 'contact_email', 'contact_phone', 'contact_request'], true)) {
        return $question;
    }

    $prefixMap = [
        'preferred_area' => '担当者へスムーズにつなげられるように、まずエリアだけ整理させてください。',
        'preferred_station_line' => 'エリアに加えて、駅や沿線の希望があると提案の精度が上がります。',
        'commute_destination' => '日々の移動も住み心地に大きく関わります。',
        'budget' => '担当者が提案の幅を見誤らないように、予算感だけ確認します。',
        'competitor_viewing_status' => '検討の進み具合も把握しておくと、急ぐべきか整理しやすいです。',
        'viewed_property_count' => '見学済みの場合は、比較の進み具合を確認させてください。',
        'preferred_area_size' => '暮らしやすさを考えるうえで、間取りより先に広さの感覚も見ておきたいです。',
        'layout' => '広さのイメージに合わせて、間取りも確認します。',
        'property_type' => '提案対象を絞るために、物件種別も確認させてください。',
        'station_walk_minutes' => '価格と利便性のバランスを見るため、駅距離の許容範囲も伺います。',
        'priority' => '条件の優先順位が分かると、提案がかなり現実的になります。',
        'family_structure' => '差し支えない範囲で、暮らす人数のイメージも教えてください。',
        'purchase_timing' => 'スケジュール感によって、急ぐ準備と後でよい準備が変わります。',
        'rent_timing' => '賃貸は時期によって候補の動き方が変わるため、まず時期感を確認します。',
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
        'preferred_contact' => '具体的なご案内に進む場合に備えて、ご希望の連絡方法も確認します。',
        'income' => 'ローン相談では、まず概算の年収から無理のない借入目安を見ます。',
        'employment_type' => '審査では勤務形態も見られるため、差し支えない範囲で確認します。',
        'years_employed' => '勤続年数も審査傾向を見る材料になります。',
        'down_payment' => '自己資金が分かると、借入額と諸費用のバランスを見やすくなります。',
        'desired_loan_amount' => '希望借入額がある場合は、返済額の目安と一緒に見ていきます。',
        'other_debts' => '他のお借入れは返済比率に関わるため、答えられる範囲で大丈夫です。',
        'loan_concern' => 'ローンで一番気になる点を先に押さえると、説明が的確になります。',
        'loan_simulation_used' => '必要であれば、シミュレーターで月々返済も整理できます。',
        'move_date' => '希望日があると、逆算スケジュールを作りやすくなります。',
        'contact_name' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'contact_request' => '',
    ];
    $prefix = $prefixMap[$field] ?? '続けて、もう少しだけ確認させてください。';
    return $prefix . "\n" . $question;
}

function chatIntakeBuildReply($field, $value, $nextField, $data) {
    $advice = chatIntakeAdvice($field, $value, $data);
    $confirm = "";
    if (!in_array($field, ["customer_type", "contact_name", "contact_email", "contact_phone", "contact_request"], true)) {
        $labelMap = chatIntakeFieldLabelMap();
        $label = $labelMap[$field] ?? "この内容";
        $display = chatIntakeDisplayValue($value);
        if ($display !== "") {
            $confirm = $label . "は「" . $display . "」として条件整理に反映しました。違っていたらいつでも修正できます。";
        }
    }
    $parts = array_values(array_filter([$advice, $confirm, chatIntakeNaturalQuestion($nextField, $data)], function ($part) { return trim((string)$part) !== ""; }));
    return implode("\n\n", $parts);
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
    if (!empty($data['customer_type']) && chatIntakeIsConfirmedField($data, 'customer_type')) $parts[] = '種別: ' . $data['customer_type'];
    if (!empty($data['preferred_area']) && chatIntakeIsConfirmedField($data, 'preferred_area')) $parts[] = '希望エリア: ' . implode('、', $data['preferred_area']);
    if ((!empty($data['budget_min']) || !empty($data['budget_max'])) && chatIntakeIsConfirmedField($data, 'budget')) $parts[] = '予算: ' . ($data['budget_min'] ?? '') . '〜' . ($data['budget_max'] ?? '') . '万円目安';
    if (!empty($data['property_type'])) $parts[] = '物件種別: ' . (is_array($data['property_type']) ? implode('、', $data['property_type']) : $data['property_type']);
    if (!empty($data['purchase_timing'])) $parts[] = '購入時期: ' . $data['purchase_timing'];
    if (!empty($data['selling_timing'])) $parts[] = '売却時期: ' . $data['selling_timing'];
    if (!empty($data['loan_status'])) $parts[] = 'ローン: ' . $data['loan_status'];
    if (!empty($data['move_date'])) $parts[] = '引越/引渡希望: ' . $data['move_date'];
    if (!empty($data['customer_name'])) $parts[] = '顧客名: ' . $data['customer_name'];
    if (!empty($data['customer_phone']) || !empty($data['customer_email'])) $parts[] = '連絡先取得済み';
    $data['summary_for_sales'] = implode(' / ', $parts);
    $data['missing_fields'] = [];
    foreach (chatIntakeScenarioFieldsForData($data) as $field) {
        if (!in_array($field, $data['_asked_fields'] ?? [], true)) $data['missing_fields'][] = $field;
    }
    $data['next_action'] = chatIntakeNextAction($data);
}

function chatIntakeNextAction($data) {
    $customerType = $data['customer_type'] ?? '';
    if (($data['temperature'] ?? 'low') === 'high') {
        $map = [
            'purchase' => '早めの具体案内。希望条件の確認、候補物件提案、内覧調整、資金計画・ローン事前審査を提案。',
            'rent' => '条件整理をもとに、希望エリア・予算・入居時期に合う候補提案へつなげる。',
            'consultation' => '相談内容を整理し、担当者から次の進め方を案内。',
            'replacement' => '早めの具体案内。購入条件整理、売却査定、残債確認、買い替えスケジュール相談を提案。',
            'sale' => '早めの具体案内。査定、販売戦略、売却スケジュール相談を提案。',
            'investment_buy' => '早めの具体案内。収支確認、融資相談、候補物件提案へつなげる。',
            'investment_sale' => '早めの具体案内。賃貸状況確認、査定、売却戦略相談を提案。',
            'loan' => '早めの具体案内。借入可能額、返済計画、事前審査の相談を提案。',
            'market' => '早めの具体案内。相場確認、価格レポート、今後の判断材料を提案。',
            'inheritance' => '早めの具体案内。名義・共有状況の確認、相場確認、必要な専門機関確認を提案。',
        ];
        return $map[$customerType] ?? '早めの具体案内。相談内容に合わせた具体案を提案。';
    }
    if (($data['temperature'] ?? 'low') === 'middle') {
        $map = [
            'purchase' => '条件整理を継続し、候補物件提案・資金計画・ローン相談へつなげる。',
            'rent' => '条件整理を継続し、希望条件に合う賃貸候補の確認へつなげる。',
            'consultation' => '相談内容を継続整理し、担当者が対応しやすい状態にする。',
            'replacement' => '条件整理を継続し、購入条件・売却見込み・買い替えスケジュール相談へつなげる。',
            'sale' => '条件整理を継続し、相場情報・査定・販売戦略相談へつなげる。',
            'investment_buy' => '条件整理を継続し、収支確認・融資相談・候補物件提案へつなげる。',
            'investment_sale' => '条件整理を継続し、賃貸状況確認・査定・売却戦略相談へつなげる。',
            'loan' => '条件整理を継続し、借入可能額・返済計画・事前審査相談へつなげる。',
        ];
        return $map[$customerType] ?? '条件整理を継続し、相談内容に合う次の案内へつなげる。';
    }
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

function processChatIntakeMessage($db, $sessionId, $businessCardId, $message, $options = []) {
    $data = chatIntakeLoad($db, $sessionId, $businessCardId);
    $data['last_user_message'] = $message;
    $fromButton = !empty($options['from_button']);
    $buttonSelection = isset($options['button_selection']) && is_array($options['button_selection']) ? $options['button_selection'] : null;
    $agentName = $options['agent_name'] ?? '担当者';

    if (chatIntakeUserWantsFreeConversation($message)) {
        $data['_intake_mode'] = 'free';
        $data['_current_field'] = null;
        chatIntakeEvaluateTemperature($data);
        chatIntakeBuildSummary($data);
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
        return [
            'handled' => true,
            'reply' => chatIntakeBuildFreeConversationReply($agentName),
            'quick_replies' => [],
            'data' => $data,
        ];
    }

    if (chatIntakeUserWantsGuidedConversation($message)) {
        $data['_intake_mode'] = 'guided';
        $data['_current_field'] = chatIntakeNextField($data);
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
    }

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

    if (($data['_intake_mode'] ?? 'guided') === 'free') {
        return ['handled' => false, 'quick_replies' => [], 'data' => $data];
    }

    $field = $data['_current_field'] ?? 'customer_type';
    if ($field === null || $field === '') {
        return ['handled' => false, 'data' => $data];
    }

    $validCustomerTypes = array_map(function ($choice) { return $choice['value']; }, chatIntakeTypeChoices());
    $normalizedCustomerType = $field === 'customer_type' ? chatIntakeNormalizeChoiceValue($field, $message) : null;
    $shouldAnswerDirectly = !$fromButton && (
        chatIntakeUserRequestsDirectAnswer($message)
        || ($field !== 'customer_type' && chatIntakeLooksLikeUserQuestion($message))
        || ($field === 'customer_type' && chatIntakeLooksLikeUserQuestion($message) && !in_array($normalizedCustomerType, $validCustomerTypes, true))
    );
    if ($shouldAnswerDirectly) {
        chatIntakeApplyNaturalFields($data, $message, null);
        $data['_intake_mode'] = 'free';
        $data['_current_field'] = null;
        chatIntakeEvaluateTemperature($data);
        chatIntakeBuildSummary($data);
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
        return ['handled' => false, 'quick_replies' => [], 'data' => $data];
    }

    $extractedFields = $fromButton ? [] : chatIntakeApplyNaturalFields($data, $message, $field);
    if (!$fromButton && empty($extractedFields) && chatIntakeLooksLikeAccidentalShortInput($message, $field)) {
        chatIntakeRecordFieldMeta($data, $field, $message, "invalid", "invalid", $message, "typed", "too_short");
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
        return [
            'handled' => true,
            'reply' => chatIntakeBuildShortInputReply($field, $data),
            'quick_replies' => chatIntakeQuickRepliesForCurrentField($data),
            'data' => $data,
        ];
    }
    if (!$fromButton && !isset($extractedFields[$field]) && !empty($extractedFields)) {
        chatIntakeEvaluateTemperature($data);
        chatIntakeBuildSummary($data);
        $nextField = chatIntakeNextField($data);
        $data['_current_field'] = $nextField;
        chatIntakeSave($db, $sessionId, $businessCardId, $data);

        $defs = chatIntakeFieldDefinitions();
        $handledField = array_key_first($extractedFields);
        $reply = chatIntakeBuildReply($handledField, $extractedFields[$handledField], $nextField, $data);
        $quick = $nextField && isset($defs[$nextField]) ? chatIntakeQuickRepliesForField($nextField, $data) : [];
        return [
            'handled' => true,
            'reply' => $reply,
            'quick_replies' => $quick,
            'data' => $data,
        ];
    }
    if (!$fromButton && $field !== 'customer_type' && chatIntakeLooksLikeUserQuestion($message) && !isset($extractedFields[$field])) {
        return ['handled' => false, 'quick_replies' => chatIntakeQuickRepliesForCurrentField($data), 'data' => $data];
    }
    $value = isset($extractedFields[$field]) ? $extractedFields[$field] : chatIntakeNormalizeChoiceValue($field, $message);
    if ($field === 'customer_type') {
        $validTypes = array_map(function ($choice) { return $choice['value']; }, chatIntakeTypeChoices());
        if (is_array($value) || !in_array($value, $validTypes, true)) {
            return ['handled' => false, 'quick_replies' => chatIntakeQuickRepliesForField('customer_type', $data), 'data' => $data];
        }
    }
    if ($field === 'contact_name') {
        [$lastNameCheck, $firstNameCheck] = chatIntakeParseNameParts($value);
        if ($lastNameCheck === '' || $firstNameCheck === '') {
            return ['handled' => true, 'reply' => '恐れ入ります。苗字とお名前を分けてご入力ください。\n\n例：山田 太郎', 'quick_replies' => [], 'data' => $data];
        }
    }
    if ($field === 'contact_email' && chatIntakeExtractEmail($value) === null) {
        return ['handled' => true, 'reply' => 'メールアドレスの形式を確認できませんでした。\n例：yamada@example.com の形式でご入力ください。', 'quick_replies' => [], 'data' => $data];
    }
    if ($field === 'contact_phone') {
        $phoneCandidate = chatIntakeExtractPhoneCandidate($value);
        if (($fromButton && chatIntakeIsSmsRegisterRequest($message, $buttonSelection)) || $phoneCandidate !== null) {
            return [
                'handled' => true,
                'reply' => 'SMS認証フォームを表示します。電話番号を入力して認証を進めてください。',
                'quick_replies' => [],
                'sms_auth_required' => true,
                'sms_auth_phone' => $phoneCandidate,
                'data' => $data,
            ];
        }
        $defs = chatIntakeFieldDefinitions();
        return [
            'handled' => true,
            'reply' => chatIntakeNaturalQuestion('contact_phone', $data),
            'quick_replies' => $defs['contact_phone']['choices'] ?? [],
            'data' => $data,
        ];
    }
    $validation = chatIntakeValidateFieldValue($field, $value, $data, $fromButton, $fromButton ? "button" : "typed");
    $normalizedValue = $validation["normalized_value"] ?? $value;
    if (empty($validation["save_value"])) {
        chatIntakeRecordFieldMeta($data, $field, $normalizedValue, $validation["confidence"] ?? "low", $validation["status"] ?? "needs_confirmation", $message, $fromButton ? "button" : "typed", $validation["reason"] ?? "");
        if (!empty($validation["mark_asked"])) chatIntakeMarkAsked($data, $field);
        chatIntakeEvaluateTemperature($data);
        chatIntakeBuildSummary($data);
        $nextField = !empty($validation["mark_asked"]) ? chatIntakeNextField($data) : $field;
        $data["_current_field"] = $nextField;
        chatIntakeSave($db, $sessionId, $businessCardId, $data);
        $defs = chatIntakeFieldDefinitions();
        if (($validation["status"] ?? "") === "unconfirmed" && !empty($validation["mark_asked"])) {
            $reply = chatIntakeBuildUnconfirmedReply($field, $normalizedValue, $nextField, $data);
            $quick = $nextField && isset($defs[$nextField]) ? chatIntakeQuickRepliesForField($nextField, $data) : [];
        } else {
            $reply = chatIntakeBuildClarifyingReply($field, $data, $validation);
            $quick = isset($defs[$field]) ? chatIntakeQuickRepliesForField($field, $data) : [];
        }
        return ["handled" => true, "reply" => $reply, "quick_replies" => $quick, "data" => $data];
    }

    chatIntakeSetField($data, $field, $normalizedValue, [
        "confidence" => $validation["confidence"] ?? "high",
        "status" => $validation["status"] ?? "confirmed",
        "raw" => $message,
        "source" => $fromButton ? "button" : "typed",
        "reason" => $validation["reason"] ?? "",
    ]);
    if ($field === "customer_type") $data["customer_type"] = $normalizedValue;
    chatIntakeEvaluateTemperature($data);
    chatIntakeBuildSummary($data);
    $nextField = chatIntakeNextField($data);
    $data["_current_field"] = $nextField;
    chatIntakeSave($db, $sessionId, $businessCardId, $data);

    $defs = chatIntakeFieldDefinitions();
    $reply = chatIntakeBuildReply($field, $normalizedValue, $nextField, $data);
    $quick = $nextField && isset($defs[$nextField]) ? chatIntakeQuickRepliesForField($nextField, $data) : [];
    return [
        'handled' => true,
        'reply' => $reply,
        'quick_replies' => $quick,
        'data' => $data,
    ];
}


function chatIntakeApplyVerifiedPhoneRegistration($db, $sessionId, $businessCardId, $phone) {
    $data = chatIntakeLoad($db, $sessionId, $businessCardId);
    $data['customer_phone'] = trim((string)$phone);
    $data['customer_phone_verified'] = true;
    $data['customer_contact_raw'] = trim(implode("\n", array_filter([$data['customer_contact_raw'] ?? '', $phone])));
    $data['contact_status'] = 'provided';
    $data['contact_consent'] = true;
    $data['preferred_contact_method'] = 'phone';
    $data['preferred_contact_value'] = $data['customer_phone'];
    chatIntakeMarkAsked($data, 'contact_phone');
    $nextField = chatIntakeNextField($data);
    $data['_current_field'] = $nextField;
    chatIntakeEvaluateTemperature($data);
    chatIntakeBuildSummary($data);
    chatIntakeSave($db, $sessionId, $businessCardId, $data);
    return $data;
}

function buildChatLeadContext($data) {
    if (!$data || !is_array($data)) return '';
    $labels = [
        'customer_type' => '相談種別', 'temperature' => '温度感', 'preferred_area' => '希望エリア', 'preferred_station' => '希望駅', 'preferred_station_line' => '希望駅・沿線',
        'commute_destination' => '通勤・通学先', 'budget_min' => '予算下限', 'budget_max' => '予算上限',
        'competitor_viewing_status' => '他社内覧有無', 'viewed_property_count' => '内覧件数', 'preferred_area_size' => '希望㎡数',
        'layout' => '間取り', 'property_type' => '物件種別', 'purchase_timing' => '購入時期', 'selling_timing' => '売却時期', 'rent_timing' => '賃貸時期',
        'loan_status' => 'ローン状況', 'loan_balance' => 'ローン残債', 'sublease_status' => 'サブリース有無',
        'sublease_cancelable' => 'サブリース解除可否', 'summary_for_sales' => '営業向け要約', 'next_action' => '次アクション',
        'move_date' => '引越/引渡希望日', 'customer_name' => '顧客名', 'customer_last_name' => '姓', 'customer_first_name' => '名', 'customer_phone' => '電話番号',
        'customer_phone_verified' => 'SMS認証済み', 'customer_email' => 'メールアドレス', 'customer_line' => 'LINE', 'preferred_contact_method' => '希望連絡方法',
        'preferred_contact' => '希望連絡方法', 'contact_status' => '連絡希望状態', 'station_walk_minutes' => '駅徒歩許容分数',
        'renovation_preference' => 'リフォーム意向', 'current_property_location' => '現居所在地', 'reason_for_move' => '買い替え理由',
        'temporary_housing' => '仮住まい可否', 'tax_consideration' => '3,000万円控除検討', 'move_completion_timing' => '買い替え完了希望時期',
        'repair_history' => '修繕履歴', 'appeal_points' => '物件PRポイント', 'building_name' => 'マンション名', 'exclusive_area' => '専有面積',
        'floor_info' => '所在階・総階数', 'direction' => '方位', 'monthly_cost' => '管理費・修繕積立金', 'renovation_history' => 'リフォーム履歴',
        'facility_notes' => '設備・特徴', 'land_area' => '土地面積', 'building_area' => '建物面積', 'building_age' => '築年月',
        'road_info' => '前面道路', 'coverage_ratio' => '建ぺい率', 'floor_area_ratio' => '容積率', 'land_status' => '土地状況',
        'boundary_issue' => '境界・越境', 'occupancy_status' => '賃貸状況', 'gross_yield' => '表面利回り', 'replacement_plan' => '買い替え予定',
        'age_tolerance' => '築年数許容', 'finance_plan' => '融資利用予定', 'equity' => '自己資金', 'ownership_status' => '名義・共有状況',
        'simulation_save_consent' => 'シミュレーター結果保存可否', '_intake_mode' => '会話モード'
    ];
    $lines = [];
    foreach ($labels as $key => $label) {
        if (!isset($data[$key]) || $data[$key] === null || $data[$key] === '' || $data[$key] === []) continue;
        $value = is_array($data[$key]) ? implode("、", $data[$key]) : $data[$key];
        $meta = chatIntakeFieldMeta($data, $key);
        $status = $meta["status"] ?? "confirmed";
        if ($status === "invalid") continue;
        $confidence = $meta["confidence"] ?? "high";
        $suffix = $status === "confirmed" ? "" : "（未確認 status: " . $status . ", confidence: " . $confidence . "）";
        $lines[] = $label . ": " . $value . $suffix;

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

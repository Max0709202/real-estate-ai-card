<?php
/**
 * 営業担当者向けの構造化AI要約。
 *
 * AIチャット履歴と入力済みの条件をもとに、GPT へ以下5項目を整理させて返す。
 *   1. お客様概要        customer_overview      (string)
 *   2. 主な希望条件      property_requirements  (string[])
 *   3. 資金・住宅ローン  financial_status       (string[])
 *   4. 営業担当者への申し送り sales_handover     (string)
 *   5. 次に確認すること  needs_confirmation     (string[])
 *
 * 生成結果はソースデータのハッシュと共にキャッシュし、内容に変化があった時だけ再生成する。
 */

require_once __DIR__ . '/openai-chat-helper.php';
require_once __DIR__ . '/chat-intake-helper.php';
require_once __DIR__ . '/chat-crm-helper.php';
require_once __DIR__ . '/chat-helpers.php';

function chatSalesSummaryModel() {
    if (defined('OPENAI_MODEL_SALES_SUMMARY') && OPENAI_MODEL_SALES_SUMMARY !== '') return OPENAI_MODEL_SALES_SUMMARY;
    $env = getenv('OPENAI_MODEL_SALES_SUMMARY');
    if ($env !== false && $env !== '') return $env;
    return 'gpt-5.4-mini';
}

function ensureChatSalesSummaryTable($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS chat_sales_summaries (
        session_id CHAR(36) PRIMARY KEY,
        business_card_id INT NULL,
        summary_json JSON NULL,
        source_hash CHAR(32) NULL,
        model VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
        INDEX idx_chat_sales_summaries_card (business_card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** 5項目のキーと型を常に満たす形へ正規化する。 */
function chatSalesSummaryNormalize($data) {
    $data = is_array($data) ? $data : [];
    $asString = function ($value) {
        if (is_array($value)) {
            $parts = array_filter(array_map('trim', array_map('strval', $value)), function ($v) { return $v !== ''; });
            return implode("\n", $parts);
        }
        return trim((string)$value);
    };
    $asList = function ($value) {
        if (!is_array($value)) {
            $value = preg_split('/\R/u', (string)$value);
        }
        $out = [];
        foreach ((array)$value as $item) {
            if (is_array($item)) $item = implode('', $item);
            $item = trim((string)$item);
            $item = ltrim($item, "-・*　 ");
            if ($item !== '') $out[] = $item;
        }
        return array_values($out);
    };
    return [
        'customer_overview'     => $asString($data['customer_overview'] ?? ''),
        'property_requirements' => $asList($data['property_requirements'] ?? []),
        'financial_status'      => $asList($data['financial_status'] ?? []),
        'sales_handover'        => $asString($data['sales_handover'] ?? ''),
        'needs_confirmation'    => $asList($data['needs_confirmation'] ?? []),
    ];
}

function chatSalesSummaryIsEmpty($summary) {
    if (!is_array($summary)) return true;
    return trim((string)($summary['customer_overview'] ?? '')) === ''
        && empty($summary['property_requirements'])
        && empty($summary['financial_status'])
        && trim((string)($summary['sales_handover'] ?? '')) === ''
        && empty($summary['needs_confirmation']);
}

/**
 * モデルへ渡すソースデータ（人手入力・分類済み条件・直近チャット）を組み立てる。
 * 入力に無い情報を推測させないため、実際に保存されている値のみを渡す。
 */
function chatSalesSummaryBuildSource($db, $sessionId, $case, $structuredLead) {
    $lines = [];

    // 相談種別（購入/売却/賃貸/買い替え）は案件データの既定値が 'purchase' のため、
    // お客様が何も選んでいなくても「購入」として要約に反映されてしまっていた。
    // お客様の回答（structured_data の customer_type）があるか、既定値以外に設定されている
    // 場合だけ確定として渡し、それ以外は「未確定」と明示する。
    $leadCustomerType = trim((string)($structuredLead['customer_type'] ?? ''));
    $dealType = trim((string)($case['deal_type'] ?? ''));
    if ($leadCustomerType !== '' || ($dealType !== '' && $dealType !== 'purchase')) {
        $dealTypeLabel = function_exists('chatCrmDealTypeLabel') ? chatCrmDealTypeLabel($dealType ?: 'purchase') : '購入';
        $lines[] = '相談種別: ' . $dealTypeLabel;
    } else {
        $lines[] = '相談種別: 未確定（お客様の回答なし）';
    }

    $customerName = trim((string)($case['customer_name'] ?? ''));
    if ($customerName === '' && !empty($structuredLead['customer_name'])) {
        $customerName = trim((string)$structuredLead['customer_name']);
    }
    if ($customerName !== '') $lines[] = '顧客名: ' . $customerName;

    $tempMap = ['high' => '高い', 'middle' => '中程度', 'low' => '低い'];
    $temp = $structuredLead['temperature'] ?? '';
    if (isset($tempMap[$temp])) $lines[] = '検討温度感（営業内部評価）: ' . $tempMap[$temp];

    // 「希望物件種別」は、以前の初期値（マンション）が案件データに保存されている既存のお客様がいる。
    // お客様の回答（structured_data の property_type）で裏付けが取れない種別は要約の入力に含めない。
    // 実際に伺った種別は下の「確定情報／未確認情報」として別途渡すため、情報は失われない。
    $caseForSummary = $case;
    if (trim((string)($structuredLead['property_type'] ?? '')) === '' && is_array($caseForSummary['conditions'] ?? null)) {
        foreach (['buyer', 'renter'] as $section) {
            if (isset($caseForSummary['conditions'][$section]['property_type'])) {
                $caseForSummary['conditions'][$section]['property_type'] = null;
            }
        }
    }
    $conditionsSummary = trim((string)chatCrmSummarizeConditions($caseForSummary));
    if ($conditionsSummary !== '') $lines[] = '整理済み条件: ' . $conditionsSummary;
    else $lines[] = '整理済み条件: まだありません（お客様の回答なし）';

    // 分類済みリード項目（確定 / 未確認 / 要確認）。低情報の入力は既にフィルタ済み。
    $classified = function_exists('chatIntakeClassifiedLeadItems') && is_array($structuredLead)
        ? chatIntakeClassifiedLeadItems($structuredLead)
        : ['confirmed' => [], 'inferred' => [], 'needs_confirmation' => []];
    $renderItems = function ($items) {
        $out = [];
        foreach ((array)$items as $item) {
            $label = trim((string)($item['label'] ?? ''));
            $value = trim((string)($item['value'] ?? ''));
            if ($label === '' || $value === '') continue;
            $out[] = '  - ' . $label . ': ' . $value;
        }
        return $out;
    };
    $confirmed = $renderItems($classified['confirmed'] ?? []);
    if ($confirmed) { $lines[] = '確定情報:'; $lines = array_merge($lines, $confirmed); }
    $inferred = $renderItems($classified['inferred'] ?? []);
    if ($inferred) { $lines[] = '未確認情報（顧客の確定回答ではない）:'; $lines = array_merge($lines, $inferred); }
    $needs = $renderItems($classified['needs_confirmation'] ?? []);
    if ($needs) { $lines[] = '要確認情報:'; $lines = array_merge($lines, $needs); }

    // 直近のチャット履歴（顧客とAIの会話）。
    if (function_exists('loadRecentChatMessagesForResume')) {
        $history = loadRecentChatMessagesForResume($db, $sessionId, 20);
        $historyLines = [];
        foreach ($history as $row) {
            $role = $row['role'] ?? '';
            if ($role === 'user') $label = '顧客';
            elseif ($role === 'agent') $label = '担当者';
            else $label = 'AI';
            $text = trim((string)($row['message'] ?? ''));
            if ($text === '') continue;
            $historyLines[] = $label . ': ' . mb_substr($text, 0, 400);
        }
        if ($historyLines) {
            $lines[] = '直近のチャット履歴:';
            $lines = array_merge($lines, array_slice($historyLines, -16));
        }
    }

    return implode("\n", $lines);
}

/** ソーステキストから strict JSON の5項目要約を生成する。失敗時は null。 */
function chatSalesSummaryGenerate($db, $sessionId, $businessCardId, $sourceText) {
    $model = chatSalesSummaryModel();
    $apiKey = function_exists('chatOpenAIApiKeyForModel')
        ? chatOpenAIApiKeyForModel($model)
        : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: ''));
    if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY_HERE') return null;

    $system = 'あなたは不動産営業を支援するアシスタントです。担当者が一目で顧客状況と次の対応を把握できるよう、'
        . '与えられた入力情報だけを使って日本語で簡潔に整理します。必ず指定のJSON形式のみを出力します。';

    $rules = "厳守事項:\n"
        . "- 入力に書かれていない情報は推測・創作しない。\n"
        . "- 「未確定」「未確認」「まだありません」と書かれた項目は、確定した希望条件として書かない。「未定」と明記する。\n"
        . "- 相談種別（購入/売却/賃貸）と希望物件種別は、お客様の回答がある場合だけ書く。両方とも未確定なら"
        . "customer_overview は「ご相談内容も、希望物件種別も未定です。」から始める。\n"
        . "- 確定した希望条件が1件も無い場合、property_requirements は [\"未定\"] とする（空配列にしない）。\n"
        . "- 質問文や案内文（例:「条件整理を進めてください」）を顧客の希望条件として採用しない。明らかに不自然な値は無視する。\n"
        . "- 意味や単位が不明な数値は「要確認」とする。\n"
        . "- 入力に無い / 未回答の項目は無理に埋めず、必要なら「次に確認すること」に回す。\n"
        . "- purchase / high / yes などの内部コードは自然な日本語に言い換える。\n"
        . "- 住宅ローンが事前審査済みの場合、再度の事前審査は提案しない（承認額・既存借入・金融機関の確認や具体的な資金計画の案内につなげる）。\n"
        . "- 重複する情報はまとめ、単なる項目の羅列ではなく読みやすい日本語にする。\n"
        . "- 全体は営業担当者が一目で把握できる分量に抑える。\n";

    $format = "出力は次のJSONのみ（前後に説明文やコードブロックを付けない）:\n"
        . "{\n"
        . "  \"customer_overview\": \"お客様概要。2〜4文程度で、目的・物件種別・時期・温度感などを自然な文章で。\",\n"
        . "  \"property_requirements\": [\"主な希望条件を1項目ずつ。例: 希望エリア：中野区、文京区\"],\n"
        . "  \"financial_status\": [\"資金・住宅ローンに関する情報を1項目ずつ\"],\n"
        . "  \"sales_handover\": \"営業担当者への申し送り。次に何をどう対応すべきかを具体的に。\",\n"
        . "  \"needs_confirmation\": [\"次に確認すべき事項を1項目ずつ\"]\n"
        . "}";

    $user = $rules . "\n" . $format . "\n\n入力情報:\n" . $sourceText;

    $resp = callOpenAIChat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ], $apiKey, $model, [
        'db' => $db,
        'session_id' => $sessionId,
        'business_card_id' => (int)$businessCardId,
        'purpose' => 'sales_summary',
        'max_tokens' => 1200,
        'temperature' => 0.2,
    ]);

    if (!empty($resp['error']) || empty($resp['reply'])) return null;
    $parsed = chatSalesSummaryParseJson($resp['reply']);
    if ($parsed === null) return null;
    return chatSalesSummaryNormalize($parsed);
}

/** モデル出力から最初の JSON オブジェクトを取り出してデコードする。 */
function chatSalesSummaryParseJson($text) {
    $text = trim((string)$text);
    if ($text === '') return null;
    // コードフェンスを除去。
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;
    // 最初の { から最後の } までを抽出して再試行。
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

/**
 * キャッシュを踏まえて構造化要約を取得する。
 * 返り値: ['summary' => [...], 'model' => string, 'cached' => bool, 'generated' => bool]
 */
function chatSalesSummaryResolve($db, $sessionId, $businessCardId, $case, $structuredLead, $forceRefresh = false) {
    ensureChatSalesSummaryTable($db);

    $sourceText = chatSalesSummaryBuildSource($db, $sessionId, $case, $structuredLead);
    $model = chatSalesSummaryModel();
    $hash = md5($model . "\n" . $sourceText);

    $cached = null;
    try {
        $stmt = $db->prepare("SELECT summary_json, source_hash FROM chat_sales_summaries WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['summary_json'])) {
            $decoded = json_decode($row['summary_json'], true);
            if (is_array($decoded)) $cached = ['summary' => chatSalesSummaryNormalize($decoded), 'hash' => $row['source_hash'] ?? ''];
        }
    } catch (Throwable $e) {
        $cached = null;
    }

    if (!$forceRefresh && $cached && ($cached['hash'] ?? '') === $hash && !chatSalesSummaryIsEmpty($cached['summary'])) {
        return ['summary' => $cached['summary'], 'model' => $model, 'cached' => true, 'generated' => false];
    }

    $generated = chatSalesSummaryGenerate($db, $sessionId, $businessCardId, $sourceText);
    if ($generated !== null && !chatSalesSummaryIsEmpty($generated)) {
        try {
            $stmt = $db->prepare("INSERT INTO chat_sales_summaries (session_id, business_card_id, summary_json, source_hash, model)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE summary_json = VALUES(summary_json), source_hash = VALUES(source_hash), model = VALUES(model)");
            $stmt->execute([$sessionId, (int)$businessCardId, json_encode($generated, JSON_UNESCAPED_UNICODE), $hash, $model]);
        } catch (Throwable $e) {
            // 保存に失敗しても生成結果は返す。
        }
        return ['summary' => $generated, 'model' => $model, 'cached' => false, 'generated' => true];
    }

    // 生成に失敗した場合、古いキャッシュがあればそれを返す。
    if ($cached && !chatSalesSummaryIsEmpty($cached['summary'])) {
        return ['summary' => $cached['summary'], 'model' => $model, 'cached' => true, 'generated' => false];
    }

    return null;
}

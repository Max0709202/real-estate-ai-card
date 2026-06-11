<?php
/**
 * OpenAI GPT-4o-mini chat helper with blog context (https://smile.re-agent.info/blog/).
 * - Fetches blog index and optional article pages for context
 * - Calls OpenAI Chat Completions API
 */

if (!defined('CHAT_BLOG_BASE_URL')) {
    define('CHAT_BLOG_BASE_URL', 'https://smile.re-agent.info/blog/');
}

/**
 * Fetch URL with a short timeout. Returns HTML string or empty on failure.
 *
 * @param string $url
 * @param int $timeoutSeconds
 * @return string
 */
function fetchUrlForChat($url, $timeoutSeconds = 8) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeoutSeconds],
            'ssl'  => ['verify_peer' => true]
        ]);
        $html = @file_get_contents($url, false, $ctx);
        return is_string($html) ? $html : '';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AI-Fcard-ChatBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return is_string($body) ? $body : '';
}

/**
 * Extract text from HTML (strip tags, normalize whitespace).
 *
 * @param string $html
 * @param int $maxLength
 * @return string
 */
function extractTextFromHtml($html, $maxLength = 12000) {
    $text = strip_tags($html);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength) . '…';
    }
    return $text;
}

/**
 * Parse blog index HTML for article links (?p=...) and titles.
 * Returns array of [ 'url' => string, 'title' => string ].
 *
 * @param string $html
 * @return array
 */
function parseBlogIndexForLinks($html) {
    $links = [];
    $baseUrl = rtrim(CHAT_BLOG_BASE_URL, '/');
    if (preg_match_all('/<a\s[^>]*href=(["\'])((?:https?:\/\/[^"\']*?\/blog\/\?p=\d+|(?:\/blog\/\?p=\d+)))\1[^>]*>([^<]*)<\/a>/iu', $html, $m, PREG_SET_ORDER)) {
        $seen = [];
        foreach ($m as $x) {
            $rawUrl = $x[2];
            $url = (strpos($rawUrl, 'http') === 0) ? $rawUrl : (parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST) . $rawUrl);
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            $title = trim(html_entity_decode(strip_tags($x[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200) . '…';
            $links[] = ['url' => $url, 'title' => $title ?: '戸建てリノベINFO記事'];
        }
    }
    if (empty($links) && preg_match_all('/href=(["\'])([^"\']*\/blog\/\?p=\d+)[^"\']*\1/', $html, $m)) {
        $base = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
        foreach (array_unique($m[2]) as $raw) {
            $url = (strpos($raw, 'http') === 0) ? $raw : $base . $raw;
            $links[] = ['url' => $url, 'title' => '戸建てリノベINFO記事'];
        }
    }
    return array_slice($links, 0, 10);
}

/**
 * Build context string from blog: index page summary + up to 2 full article texts.
 *
 * @param string $userMessage Optional: not used for now, could later for relevance
 * @return array [ 'context' => string, 'sources' => [ ['url'=>..., 'title'=>...], ... ] ]
 */
function getBlogContextForChat($userMessage = '') {
    $sources = [['url' => CHAT_BLOG_BASE_URL, 'title' => '戸建てリノベINFO']];
    $indexHtml = fetchUrlForChat(CHAT_BLOG_BASE_URL);
    $indexText = extractTextFromHtml($indexHtml, 4000);
    $contextParts = ["【ブログ「戸建てリノベINFO」の概要・記事一覧】\n" . $indexText];
    $articleLinks = parseBlogIndexForLinks($indexHtml);
    $fetched = 0;
    foreach (array_slice($articleLinks, 0, 3) as $item) {
        if ($fetched >= 2) break;
        $articleHtml = fetchUrlForChat($item['url']);
        if ($articleHtml !== '') {
            $articleText = extractTextFromHtml($articleHtml, 3500);
            if (strlen($articleText) > 100) {
                $contextParts[] = "\n【記事: " . $item['title'] . "】\n" . $articleText;
                $sources[] = ['url' => $item['url'], 'title' => $item['title']];
                $fetched++;
            }
        }
    }
    $totalContext = implode("\n", $contextParts);
    if (mb_strlen($totalContext) > 8000) {
        $totalContext = mb_substr($totalContext, 0, 8000) . '…';
    }
    return ['context' => $totalContext, 'sources' => $sources];
}

require_once __DIR__ . '/chat-rag-helper.php';
require_once __DIR__ . '/chat-intake-helper.php';
require_once __DIR__ . '/chat-public-data-helper.php';
require_once __DIR__ . '/loan-simulation-helper.php';

function chatOpenAIModelLight() {
    if (defined('OPENAI_MODEL_LIGHT')) return OPENAI_MODEL_LIGHT;
    if (defined('OPENAI_CHAT_MODEL')) return OPENAI_CHAT_MODEL;
    return getenv('OPENAI_MODEL_LIGHT') ?: (getenv('OPENAI_CHAT_MODEL') ?: 'gpt-4o-mini');
}

function chatOpenAIModelSales() {
    if (defined('OPENAI_MODEL_SALES')) return OPENAI_MODEL_SALES;
    return getenv('OPENAI_MODEL_SALES') ?: chatOpenAIModelLight();
}

function chatOpenAIModelSummary() {
    if (defined('OPENAI_MODEL_SUMMARY')) return OPENAI_MODEL_SUMMARY;
    return getenv('OPENAI_MODEL_SUMMARY') ?: chatOpenAIModelLight();
}

function chatOpenAIModelKeyGroup($model) {
    $model = strtolower((string)$model);
    if (strpos($model, 'gpt-5') === 0 || strpos($model, 'gpt5') === 0 || $model === strtolower((string)chatOpenAIModelSales())) return 'sales';
    return 'light';
}

function chatOpenAIApiKeyForModel($model) {
    $group = chatOpenAIModelKeyGroup($model);
    if ($group === 'sales') {
        if (defined('OPENAI_API_KEY_SALES') && OPENAI_API_KEY_SALES !== '') return OPENAI_API_KEY_SALES;
        $key = getenv('OPENAI_API_KEY_SALES');
        if ($key !== false && $key !== '') return $key;
    }
    if ($group === 'summary') {
        if (defined('OPENAI_API_KEY_SUMMARY') && OPENAI_API_KEY_SUMMARY !== '') return OPENAI_API_KEY_SUMMARY;
        $key = getenv('OPENAI_API_KEY_SUMMARY');
        if ($key !== false && $key !== '') return $key;
    }
    if (defined('OPENAI_API_KEY_LIGHT') && OPENAI_API_KEY_LIGHT !== '') return OPENAI_API_KEY_LIGHT;
    $key = getenv('OPENAI_API_KEY_LIGHT');
    if ($key !== false && $key !== '') return $key;
    if (defined('OPENAI_API_KEY')) return OPENAI_API_KEY;
    return getenv('OPENAI_API_KEY') ?: '';
}

function chatOpenAIValuePresent($value) {
    if (is_array($value)) return !empty(array_filter($value, 'chatOpenAIValuePresent'));
    return $value !== null && $value !== '' && $value !== '未定' && $value !== '未回答' && $value !== '不明';
}

function chatOpenAILeadValue($leadData, $keys) {
    foreach ($keys as $key) {
        if (isset($leadData[$key]) && chatOpenAIValuePresent($leadData[$key])) return $leadData[$key];
    }
    return null;
}

function chatOpenAIShouldUseSalesModel($message, $memory = [], $leadData = []) {
    $intent = $leadData['customer_type'] ?? ($memory['intent'] ?? null);
    $salesIntents = ['purchase', 'rent', 'replacement', 'sale', 'loan', 'relocation', 'investment_buy', 'investment_sale'];
    $isSalesIntent = in_array($intent, $salesIntents, true);
    if (!$isSalesIntent && preg_match('/購入|買いたい|買う|住み替え|買い替え|売却|売りたい|ローン|内覧|物件|査定/u', (string)$message)) {
        $isSalesIntent = true;
    }
    if (!$isSalesIntent) return false;

    $score = 0;
    if (chatOpenAILeadValue($leadData, ['budget_min', 'budget_max', 'budget_note']) !== null || !empty($memory['budget'])) $score += 2;
    if (chatOpenAILeadValue($leadData, ['preferred_area', 'preferred_station_line', 'preferred_station']) !== null || !empty($memory['preferred_area'])) $score += 2;
    if (($leadData['competitor_viewing_status'] ?? '') === 'yes' || chatOpenAILeadValue($leadData, ['viewed_property_count', 'competitor_status']) !== null) $score += 2;
    if (chatOpenAILeadValue($leadData, ['loan_status', 'pre_approval_status', 'income', 'down_payment', 'desired_loan_amount', 'desired_monthly_payment', 'loan_concern']) !== null || !empty($memory['loan_plan']) || preg_match('/ローン|借入|事前審査|返済|金利/u', (string)$message)) $score += 2;
    if (chatOpenAILeadValue($leadData, ['purchase_timing', 'selling_timing', 'move_completion_timing']) !== null) $score += 1;
    if (!empty($leadData['contact_consent']) || (($leadData['contact_status'] ?? '') === 'provided')) $score += 2;

    $temperature = $leadData['temperature'] ?? ($memory['temperature'] ?? 'low');
    $temperatureScore = (int)($leadData['temperature_score'] ?? 0);
    if ($temperature === 'high' || $temperatureScore >= 60) $score += 3;
    elseif ($temperature === 'middle' || $temperatureScore >= 30) $score += 2;

    if (preg_match('/比較|迷|不安|相談|提案|おすすめ|条件|予算|エリア|内覧|審査|購入|売却|住み替え/u', (string)$message)) $score += 1;

    return $score >= 4;
}

function chatOpenAISelectModel($purpose, $message = '', $memory = [], $leadData = []) {
    if ($purpose === 'summary') return chatOpenAIModelSummary();
    if ($purpose === 'faq' || $purpose === 'intake' || $purpose === 'classification') return chatOpenAIModelLight();
    return chatOpenAIShouldUseSalesModel($message, $memory, $leadData) ? chatOpenAIModelSales() : chatOpenAIModelLight();
}

function chatOpenAIEnsureUsageTable($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS chat_openai_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id CHAR(36) NULL,
        business_card_id INT NULL,
        purpose VARCHAR(60) NOT NULL DEFAULT 'chat',
        requested_model VARCHAR(120) NOT NULL,
        response_model VARCHAR(120) NULL,
        prompt_tokens INT NULL,
        completion_tokens INT NULL,
        total_tokens INT NULL,
        http_status INT NULL,
        error_message TEXT NULL,
        duration_ms INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_openai_usage_session (session_id),
        INDEX idx_chat_openai_usage_card_created (business_card_id, created_at),
        INDEX idx_chat_openai_usage_model_created (requested_model, created_at),
        INDEX idx_chat_openai_usage_purpose_created (purpose, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function chatOpenAILogUsage($options, $requestedModel, $responseModel, $usage, $httpCode, $error, $durationMs) {
    $db = $options['db'] ?? null;
    if (!$db instanceof PDO) return;
    try {
        chatOpenAIEnsureUsageTable($db);
        $stmt = $db->prepare("INSERT INTO chat_openai_usage
            (session_id, business_card_id, purpose, requested_model, response_model, prompt_tokens, completion_tokens, total_tokens, http_status, error_message, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $options['session_id'] ?? null,
            $options['business_card_id'] ?? null,
            $options['purpose'] ?? 'chat',
            $requestedModel,
            $responseModel,
            isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null,
            isset($usage['total_tokens']) ? (int)$usage['total_tokens'] : null,
            $httpCode !== null ? (int)$httpCode : null,
            $error !== null && $error !== '' ? mb_substr((string)$error, 0, 1000) : null,
            $durationMs !== null ? (int)$durationMs : null,
        ]);
    } catch (Throwable $e) {
        error_log('Chat OpenAI usage log error: ' . $e->getMessage());
    }
}

function chatOpenAIGetSessionBusinessCardId($db, $sessionId, $leadData = []) {
    if (!empty($leadData['business_card_id'])) return (int)$leadData['business_card_id'];
    if (!$db instanceof PDO || $sessionId === '') return null;
    try {
        $stmt = $db->prepare('SELECT business_card_id FROM chat_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Call OpenAI Chat Completions API.
 *
 * @param array $messages [ ['role'=>'system'|'user'|'assistant', 'content'=>'...'], ... ]
 * @param string $apiKey
 * @param string $model
 * @param array $options Optional logging context: db, session_id, business_card_id, purpose.
 * @return array [ 'reply' => string|null, 'error' => string|null, 'model' => string, 'response_model' => string|null, 'usage' => array|null ]
 */
function callOpenAIChat($messages, $apiKey, $model = 'gpt-4o-mini', $options = []) {
    $started = microtime(true);
    if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY_HERE') {
        $error = 'OpenAI API key is not configured.';
        chatOpenAILogUsage($options, $model, null, null, null, $error, 0);
        return ['reply' => null, 'error' => $error, 'model' => $model, 'response_model' => null, 'usage' => null, 'http_code' => null];
    }
    $purpose = $options['purpose'] ?? 'chat';
    $defaultMaxTokens = $purpose === 'summary' ? 450 : ($purpose === 'chat_fallback' ? 700 : 850);
    $maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : $defaultMaxTokens;
    $maxTokens = max(128, min(1024, $maxTokens));
    $temperature = isset($options['temperature']) ? (float)$options['temperature'] : ($purpose === 'summary' ? 0.2 : 0.6);
    $payload = [
        'model'    => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
    ];
    $json = json_encode($payload);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $json,
        CURLOPT_HTTPHEADER    => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT       => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlError = $response === false ? curl_error($ch) : '';
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $durationMs = (int)round((microtime(true) - $started) * 1000);
    if ($response === false) {
        $error = $curlError !== '' ? $curlError : 'OpenAI request failed.';
        chatOpenAILogUsage($options, $model, null, null, $httpCode, $error, $durationMs);
        return ['reply' => null, 'error' => $error, 'model' => $model, 'response_model' => null, 'usage' => null, 'http_code' => $httpCode];
    }
    $data = json_decode($response, true);
    $responseModel = $data['model'] ?? null;
    $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : null;
    if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $err = isset($data['error']['message']) ? $data['error']['message'] : 'Invalid response (HTTP ' . $httpCode . ')';
        chatOpenAILogUsage($options, $model, $responseModel, $usage, $httpCode, $err, $durationMs);
        return ['reply' => null, 'error' => $err, 'model' => $model, 'response_model' => $responseModel, 'usage' => $usage, 'http_code' => $httpCode];
    }
    $reply = trim($data['choices'][0]['message']['content']);
    chatOpenAILogUsage($options, $model, $responseModel, $usage, $httpCode, null, $durationMs);
    return ['reply' => $reply, 'error' => null, 'model' => $model, 'response_model' => $responseModel, 'usage' => $usage, 'http_code' => $httpCode];
}

function chatOpenAITrimPromptText($text, $maxChars = 900) {
    $text = trim((string)$text);
    if ($text === '') return '';
    if (mb_strlen($text) <= $maxChars) return $text;
    return mb_substr($text, 0, $maxChars) . '…';
}

function chatOpenAICompactHistory($conversationHistory, $maxMessages = 8, $maxCharsPerMessage = 900) {
    if (!is_array($conversationHistory) || empty($conversationHistory)) return [];
    $recent = array_slice($conversationHistory, -1 * max(1, (int)$maxMessages));
    $compact = [];
    foreach ($recent as $msg) {
        if (!is_array($msg)) continue;
        $rawRole = $msg['role'] ?? '';
        $role = ($rawRole === 'bot' || $rawRole === 'assistant') ? 'assistant' : 'user';
        $content = chatOpenAITrimPromptText($msg['message'] ?? ($msg['content'] ?? ''), $maxCharsPerMessage);
        if ($content === '') continue;
        $compact[] = ['role' => $role, 'content' => $content];
    }
    return $compact;
}

function sanitizeChatReferralLanguage($reply, $agentName = '担当者') {
    $reply = (string)$reply;
    if ($reply === '') return $reply;

    $agentLabel = trim((string)$agentName) !== '' ? trim((string)$agentName) : '担当者';
    $replacement = 'ご相談・ご依頼は、この名刺の担当「' . $agentLabel . '」へお寄せください。';
    $leadIn = '(?:詳しくは|詳細は|不明な点は|個別には|個別の確認は)?';
    $patterns = [
        '/[^。！？\n]*(?:他社|他の会社|外部|別会社|不動産会社|不動産エージェント|専門家|税理士|司法書士|弁護士|金融機関|銀行|行政窓口|自治体|役所|税務署|法務局)(?:など|等)?(?:に|へ)(?:相談|確認|問い合わせ|問合せ|連絡|依頼|聞く|申し込|申込)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:相談先|依頼先|問い合わせ先|連絡先)[^。！？\n]*(?:他社|他の会社|外部|別会社|不動産会社|不動産エージェント|専門家|税理士|司法書士|弁護士|金融機関|銀行|行政窓口|自治体|役所|税務署|法務局)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:他社|他の会社|外部|別会社|不動産会社|不動産エージェント|専門家|税理士|司法書士|弁護士|金融機関|銀行|行政窓口|自治体|役所|税務署|法務局)(?:など|等)?(?:で|から)(?:相談|確認|問い合わせ|問合せ|連絡|依頼|査定|見積|提案)[^。！？\n]*(?:受け|もら|行い|行う|できます|可能)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:相談先|依頼先|問い合わせ先|連絡先)[^。！？\n]*(?:不動産会社|不動産エージェント|専門家|税理士|金融機関|行政窓口|自治体|役所)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:専門家|税理士|金融機関|行政窓口|自治体|役所)(?:など|等)?に(?:相談|確認|問い合わせ|聞く|依頼)[^。！？\n]*(?:おすすめ|お勧め|推奨|良い|よい|望ましい|必要)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:専門家|税理士|金融機関|行政窓口|自治体|役所)(?:など|等)?へ(?:相談|確認|問い合わせ|依頼)[^。！？\n]*(?:おすすめ|お勧め|推奨|良い|よい|望ましい|必要)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:専門家|税理士|金融機関|行政窓口|自治体|役所)(?:など|等)?の(?:確認|判断|相談)[^。！？\n]*(?:必要|おすすめ|お勧め)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:不動産会社|不動産エージェント)(?:や不動産会社|や不動産エージェント)?に(?:依頼|相談|確認|問い合わせ|聞く)して[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:不動産会社|不動産エージェント)(?:や不動産会社|や不動産エージェント)?へ(?:依頼|相談|確認|問い合わせ)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:不動産会社|不動産エージェント)(?:から|に)(?:査定|見積|提案)[^。！？\n]*(?:もら|受け|依頼)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:不動産会社|不動産エージェント)(?:や不動産会社|や不動産エージェント)?に(?:相談|確認|問い合わせ|聞く)[^。！？\n]*(?:おすすめ|お勧め|推奨|良い|よい|望ましい)[^。！？\n]*(?:[。！？]|$)/u',
        '/[^。！？\n]*(?:不動産会社|不動産エージェント)(?:や不動産会社|や不動産エージェント)?へ(?:相談|確認|問い合わせ)[^。！？\n]*(?:おすすめ|お勧め|推奨|良い|よい|望ましい)[^。！？\n]*(?:[。！？]|$)/u',
        '/' . $leadIn . '(?:、|。|\s)*?(?:地元|近く|お近く|地域|信頼できる|別|他社|他の)の?不動産会社(?:や不動産エージェント)?に(?:相談|確認|問い合わせ|聞く)[^。！？\n]*(?:[。！？]|$)/u',
        '/' . $leadIn . '(?:、|。|\s)*?(?:地元|近く|お近く|地域|信頼できる|別|他社|他の)の?不動産エージェントに(?:相談|確認|問い合わせ|聞く)[^。！？\n]*(?:[。！？]|$)/u',
        '/' . $leadIn . '(?:、|。|\s)*?不動産会社(?:や不動産エージェント)?に(?:相談|確認|問い合わせ|聞く)ことを(?:おすすめ|お勧め|推奨)[^。！？\n]*(?:[。！？]|$)/u',
        '/' . $leadIn . '(?:、|。|\s)*?不動産エージェントに(?:相談|確認|問い合わせ|聞く)ことを(?:おすすめ|お勧め|推奨)[^。！？\n]*(?:[。！？]|$)/u',
        '/' . $leadIn . '(?:、|。|\s)*?(?:地元|近く|お近く|地域|信頼できる|別|他社|他の)の?不動産会社[^。！？\n]*(?:[。！？]|$)/u',
        '/' . $leadIn . '(?:、|。|\s)*?(?:地元|近く|お近く|地域|信頼できる|別|他社|他の)の?不動産エージェント[^。！？\n]*(?:[。！？]|$)/u',
    ];
    foreach ($patterns as $pattern) {
        $reply = preg_replace($pattern, $replacement, $reply);
    }

    $reply = str_replace(
        ['地元の不動産会社', '近くの不動産会社', 'お近くの不動産会社', '他の不動産会社', '他社の不動産会社', '地元の不動産エージェント', '他の不動産エージェント', '相談先', '依頼先', '問い合わせ先'],
        ['この名刺の担当「' . $agentLabel . '」', 'この名刺の担当「' . $agentLabel . '」', 'この名刺の担当「' . $agentLabel . '」', 'この名刺の担当「' . $agentLabel . '」', 'この名刺の担当「' . $agentLabel . '」', 'この名刺の担当「' . $agentLabel . '」', 'この名刺の担当「' . $agentLabel . '」', 'ご相談先', 'ご依頼先', 'お問い合わせ先'],
        $reply
    );
    return trim(preg_replace('/\n{3,}/u', "\n\n", $reply));
}

/**
 * Get bot reply using the selected OpenAI model with blog context and conversation history.
 *
 * @param string $userMessage
 * @param array $conversationHistory [ ['role'=>'user'|'assistant', 'message'=>'...'], ... ] (oldest first)
 * @param string $agentName Optional agent name for persona
 * @return array [ 'reply' => string, 'sources' => array, 'error' => string|null ]
 */
function getBotReplyWithOpenAI($userMessage, $conversationHistory = [], $agentName = '担当者', $db = null, $sessionId = '') {
    $today  = date('Y-m-d');
    $leadData = [];
    $businessCardId = null;

    $rag = [
        'context' => '',
        'sources' => [],
        'requires_fresh' => chatShouldUseFreshSources($userMessage),
        'has_local_knowledge' => false,
    ];
    $memory = chatMemoryDefault();
    $publicData = [
        'context' => '',
        'sources' => [],
        'notices' => [],
        'meta' => [],
    ];

    $liveRefresh = [
        'attempted' => false,
        'updated' => 0,
        'failed' => 0,
        'skipped' => true,
        'source_keys' => [],
        'errors' => [],
    ];

    if ($db instanceof PDO) {
        $liveRefresh = refreshChatKnowledgeForMessage($db, $userMessage, 24);
        $rag = getChatRagContextForChat($db, $userMessage, 4);
        if ($sessionId !== '') {
            $memory = getChatSessionMemory($db, $sessionId);
            $leadData = chatLoadLeadDataForMemory($db, $sessionId);
            if (!empty($leadData)) chatApplyLeadDataToMemory($memory, $leadData);
            $businessCardId = chatOpenAIGetSessionBusinessCardId($db, $sessionId, $leadData);
        }
        $publicData = chatBuildPublicDataContext($db, $userMessage);
    }

    $model = chatOpenAISelectModel('chat', $userMessage, $memory, $leadData);
    $apiKey = chatOpenAIApiKeyForModel($model);
    $memoryContext = buildChatMemoryContext($memory);
    $leadContext = !empty($leadData) ? buildChatLeadContext($leadData) : (($db instanceof PDO && $sessionId !== '') ? getChatLeadContextForPrompt($db, $sessionId) : '');
    $loanSimulationContext = ($db instanceof PDO && $sessionId !== "") ? loanSimulationPromptContextForSession($db, $sessionId) : "";
    $freshnessInstruction = $rag['requires_fresh']
        ? "この質問は最新確認が必要な可能性があります。ローカルRAG参照情報がある場合はそれを優先し、参照情報が不足している場合は断定せず、最新確認が必要であることを明示してください。"
        : "ローカルRAG参照情報が質問に関係する場合は優先してください。関係しない場合は一般的な不動産実務知識で回答してください。";
    $ragContext = $rag['context'] !== ''
        ? $rag['context']
        : "【ローカルRAG参照情報】\n該当するローカル参照情報は見つかりませんでした。最新性が必要な制度・税制・金利・補助金については断定を避け、担当者による公式情報確認を案内してください。";

    $refreshContext = '';
    if (!empty($rag['requires_fresh'])) {
        if (!empty($liveRefresh['attempted']) && (int)$liveRefresh['updated'] > 0) {
            $refreshContext = "\n【最新情報チェック】\nこの質問に関連する公式RAGソースを会話中に再取得しました。回答では取得済みの参照情報を優先してください。";
        } elseif (!empty($liveRefresh['attempted']) && (int)$liveRefresh['failed'] > 0) {
            $refreshContext = "\n【最新情報チェック】\n公式RAGソースの再取得を試みましたが一部失敗しました。手元の参照情報だけで断定せず、必要に応じて最新確認が必要であることを明示してください。";
        } else {
            $refreshContext = "\n【最新情報チェック】\nローカル公式RAGソースは直近同期済み、または今回の質問で追加再取得は不要と判定されています。";
        }
    }
    $publicDataContext = $publicData['context'] !== ''
        ? "\n\n" . $publicData['context']
        : '';
    $publicDataMeta = $publicData['meta'] ?? [];
    $publicDataUiSources = chatPublicDataSourcesForUi($publicData['sources'], $publicDataMeta);
    if (!empty($publicDataMeta) && $db instanceof PDO) {
        chatLogPublicDataAccess($db, $sessionId, $businessCardId, $userMessage, $publicDataMeta);
    }

    $systemPrompt = <<<PROMPT
あなたは不動産の専門家ではなく、日本の不動産営業現場で使われる顧客を担当している不動産エージェントです。 
名前は「{$agentName}」です。今日の日付は {$today} です。
目的は、顧客の不動産購入・売却・住み替え・住宅ローン・税制・補助金・物件選びに関する質問に対して、一般論ではなく、実務に役立つ形で分かりやすく回答することです。
回答の目的は知識提供ではなく、顧客の意思決定を支援することです。（重要 ）
ユーザーが「どう思いますか」「どうでしょうか」と質問した場合は、まず担当者としての見解を述べ、その後に理由を説明してください。
一般論や注意事項の説明は必要最小限にしてください。

回答の最後は、必要な場合だけ、次の行動につながる自然な質問を1つだけ行ってください。質問が不要な場面では無理に質問で終わらせないでください。

# 基本姿勢
- 回答は日本語で、丁寧かつ親しみやすく行う。
- 顧客が初心者でも理解できるように、専門用語はかみ砕いて説明する。
- 顧客が質問停止・自由会話を求めた場合は、それを尊重し、不要な追加質問をしない。
- 不動産と無関係な雑談や質問には、混乱させる不動産回答を無理に返さない。短く自然に受け止めたうえで、不動産の購入・売却・ローン・相場の相談に戻れることを添える。
- ただし、内容は浅くしない。不動産営業実務で役立つ具体性を持たせる。
- 「参考情報です」「専門家に相談してください」だけで終わらせない。
- まず顧客が判断しやすい実務的な説明を行う。AIチャット内で完結できない問い合わせ・相談・依頼は、必ずこのAI名刺の担当者を唯一の相談先・依頼先として案内する。

# 入れるべきルール 
---------------------------------
ルール①
ユーザーが
•	どう思いますか 
•	どうでしょうか 
•	そろそろ～ 
•	～した方が良いですか 
と質問した場合
まず最初に「あなたの見解」を答える
________________________________________
ルール②
いきなり解説しない
順番は
①見解
②理由
③質問
です。
________________________________________
悪い例
内覧とは～
メリットは～
注意点は～
どの物件ですか？
良い例
良いタイミングだと思います。
なぜなら～
ちなみに気になる物件はありますか？
________________________________________
ルール③
不動産営業として話す
ChatGPTはすぐに
一般論
注意事項
補足情報
を書きたがります。
しかし不動産AI名刺は
「不動産営業担当の分身」
です。
つまり、
❌ 不動産知識AI
ではなく
⭕ 担当エージェントAI
でなければなりません。
---------------------------------

# 最新情報が必要な質問への対応
住宅ローン減税、補助金、税制改正、金利、フラット35、自治体制度、法改正、建築基準、省エネ基準、不動産関連の最新制度では、モデルの内部知識だけで断定しない。
{$freshnessInstruction}
参照情報の優先順位は、国税庁、国土交通省、住宅金融支援機構/フラット35、自治体、金融機関公式サイト、自社確認済み資料、信頼できる業界メディアの順です。一般ブログや未確認情報だけを根拠に断定してはいけません。

# 公的データ・マンションDB参照
公的データ参照情報またはマンションDB参照情報がある場合は、その内容を一般ユーザー向けにやさしく要約してください。
APIキー、内部URL、リクエスト情報、DBの内部構造は回答に出してはいけません。
e-Statの統計情報を使う場合は、自然な文脈で「政府統計によると、」という前置きを入れてください。
公的データやマンションDBを根拠にした場合は、回答末尾に出典を明記してください。
参照情報がない、または取得できない場合は、取得できたかのように装わず、必要に応じて確認中・追加確認が必要と伝えてください。

# 回答スタイル
原則として、見解、理由、次の質問の順で回答する。
判断相談の場合は「良いタイミングだと思います」「今は一度整理してから動く方が良いです」のように、担当者としての見解を最初の文で明確にする。
質問が簡単な場合は自然な短文でよい。制度・税制・ローン・売却・購入判断では、見解を先に出したうえで、根拠と確認事項を簡潔に整理する。
見出しを使う場合も、知識解説ではなく「見解」「理由」「次に確認したいこと」の流れにする。
会話がヒアリング目的の場合でも、毎回すべての見出しを出す必要はない。自然な会話文として、受け止め、役立つ補足、次の確認を短くつなげる。

# ヒアリング設計
顧客条件によって回答が変わる場合は自然に聞き返す。同じ情報が会話メモリーにある場合は繰り返し聞かない。
回答が短すぎる、意味不明、項目と合わない、または矛盾する場合は確定情報として扱わず、自然に確認し直す。未確認情報や推測情報は断定せず、担当者に共有するための仮整理として扱う。
構造化ヒアリング情報の会話モードが free の場合、または顧客が質問を止めてほしい・自由に聞きたいと表明した場合は、こちらから新しいヒアリング質問を始めない。必要な確認がある場合も、回答の最後に任意の確認事項として1つだけ添える。
ただし、質問だけを連続して並べてはいけない。まず顧客の発言を受け止め、実務上の意味や判断材料を短く伝えてから、次に確認したいことを1テーマ・1質問だけ自然に聞く。
顧客に「情報を取られているだけ」と感じさせないよう、回答ごとにワンポイントアドバイス、注意点、比較の考え方、次に役立つ視点を添える。エリア、予算、時期、間取り、連絡先などを連続で聞き出すフォームのような会話は禁止。顧客の発言に答えたうえで、その回答に必要な確認だけを1つ自然に聞く。
顧客の相談種別と回答済み内容を最優先する。購入だけの相談では、顧客が売却・住み替え・所有物件の価格確認を話していない限り、査定、売却査定、販売戦略、売却スケジュールを勧めない。購入相談で急ぎの時期が分かった場合は、資金計画、ローン事前審査、内覧準備、候補物件の比較を中心に案内する。
査定に触れてよいのは、売却、住み替え・買い替え、投資物件売却、または顧客が所有物件の価格確認を明示した場合に限る。
購入相談では、新築/中古、マンション/戸建て、購入予定価格、自己資金、住宅ローン利用予定、年収帯、家族構成、子育て世帯か、希望エリア、入居予定時期、省エネ性能や長期優良住宅の該当有無を確認する。
売却相談では、売却予定物件の種別、所有期間、購入価格、売却見込み価格、住宅ローン残債、居住用/投資用、住み替え予定、3,000万円特別控除の利用可能性を確認する。
住宅ローン減税では、新築/中古、入居予定年、借入予定額、年収帯、所得税・住民税の目安、子育て世帯・若者夫婦世帯、省エネ性能、住み替えで3,000万円特別控除を使う予定があるかを確認する。

# 会話文脈とメモリー
過去の会話履歴と会話メモリーを踏まえて回答する。「前回のご相談では中古マンションをご検討中でしたので、その前提でお答えします」のように自然に引き継ぐ。
過去に把握済みの希望エリア・家族構成・ローン不安・予算・検討物件などは、再度質問する前に前提として使う。
個人情報やセンシティブ情報は適切に扱い、保存・利用・削除については運用方針に従う。

# 禁止事項
- 古い制度情報を断定しない。
- 税制や補助金を最新確認なしに断定しない。
- 「専門家に聞いてください」だけで終わらせない。
- 一般論だけで終わらせない。
- 顧客条件によって答えが変わる内容を、条件確認なしで断定しない。
- 不確かな情報を事実のように言わない。
- 法律・税務・融資審査の最終判断を保証しない。
- 他の不動産会社、地元の不動産会社、近くの不動産会社、別の不動産エージェント、他社への相談・問い合わせ・確認を促してはいけない。
- 相談先・依頼先・問い合わせ先を案内する場合は、必ずこのAI名刺の担当者に限定する。他社、他の不動産会社、外部の会社、税理士、司法書士、弁護士、金融機関、銀行、自治体、役所、行政窓口、税務署、法務局などへユーザーを直接誘導してはいけない。
- AIチャットで回答しきれない内容、個別確認、査定依頼、内覧依頼、ローン相談、税務・登記・行政手続きの確認などは、すべて「この名刺の担当者へご相談ください」という導線にする。

{$memoryContext}

{$leadContext}

{$loanSimulationContext}

{$ragContext}{$refreshContext}{$publicDataContext} 
PROMPT;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    // Pass enough real conversation for the model to analyze the user's own sentences.
    // Recap/summary requests get the full conversation so "まとめて" is answered from the whole history.
    $isRecapRequest = function_exists('chatIsRecapRequest') && chatIsRecapRequest($userMessage);
    $historyWindow = $isRecapRequest ? 200 : 20;
    foreach (chatOpenAICompactHistory($conversationHistory, $historyWindow, 900) as $msg) {
        $messages[] = $msg;
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];
    $logContext = [
        'db' => $db,
        'session_id' => $sessionId,
        'business_card_id' => $businessCardId,
        'purpose' => 'chat',
    ];
    $result = callOpenAIChat($messages, $apiKey, $model, $logContext);
    if ($result['error'] !== null && $model === chatOpenAIModelSales()) {
        $fallbackContext = $logContext;
        $fallbackContext['purpose'] = 'chat_fallback';
        $fallbackModel = chatOpenAIModelLight();
        $fallback = callOpenAIChat($messages, chatOpenAIApiKeyForModel($fallbackModel), $fallbackModel, $fallbackContext);
        if ($fallback['error'] === null && $fallback['reply'] !== null && $fallback['reply'] !== '') {
            $fallback['model_fallback_from'] = $model;
            $result = $fallback;
        }
    }
    if ($result['error'] !== null) {
        return ['reply' => null, 'sources' => array_merge($rag['sources'], $publicDataUiSources), 'freshness' => $liveRefresh, 'error' => $result['error'], 'model' => $result['model'] ?? $model];
    }
    $safeReply = sanitizeChatReferralLanguage($result['reply'], $agentName);
    if (!empty($publicData['sources'])) {
        $safeReply = chatAppendPublicDataSourcesToReply($safeReply, $publicData['sources']);
    }
    // Make it explicit to the user when the answer is backed by real retrieved data:
    // append a small footer with source / record count / fetch time.
    if (!empty($publicDataMeta)) {
        $transparencyFooter = chatPublicDataTransparencyFooter($publicDataMeta);
        if ($transparencyFooter !== '' && mb_strpos($safeReply, '📊 データ取得情報') === false) {
            $safeReply = rtrim($safeReply) . "\n\n" . $transparencyFooter;
        }
    }
    return ['reply' => $safeReply, 'sources' => array_merge($rag['sources'], $publicDataUiSources), 'freshness' => $liveRefresh, 'error' => null, 'model' => $result['model'] ?? $model, 'usage' => $result['usage'] ?? null];
}

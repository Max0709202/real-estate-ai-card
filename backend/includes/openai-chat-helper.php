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

/**
 * Call OpenAI Chat Completions API (gpt-4o-mini).
 *
 * @param array $messages [ ['role'=>'system'|'user'|'assistant', 'content'=>'...'], ... ]
 * @param string $apiKey
 * @param string $model
 * @return array [ 'reply' => string, 'error' => string|null ]
 */
function callOpenAIChat($messages, $apiKey, $model = 'gpt-4o-mini') {
    if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY_HERE') {
        return ['reply' => null, 'error' => 'OpenAI API key is not configured.'];
    }
    $payload = [
        'model'    => $model,
        'messages' => $messages,
        'max_tokens' => 1024,
        'temperature' => 0.6,
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        return ['reply' => null, 'error' => 'OpenAI request failed.'];
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $err = isset($data['error']['message']) ? $data['error']['message'] : 'Invalid response (HTTP ' . $httpCode . ')';
        return ['reply' => null, 'error' => $err];
    }
    $reply = trim($data['choices'][0]['message']['content']);
    return ['reply' => $reply, 'error' => null];
}

/**
 * Get bot reply using GPT-4o-mini with blog context and conversation history.
 *
 * @param string $userMessage
 * @param array $conversationHistory [ ['role'=>'user'|'assistant', 'message'=>'...'], ... ] (oldest first)
 * @param string $agentName Optional agent name for persona
 * @return array [ 'reply' => string, 'sources' => array, 'error' => string|null ]
 */
function getBotReplyWithOpenAI($userMessage, $conversationHistory = [], $agentName = '担当者') {
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: '');
    $model  = defined('OPENAI_CHAT_MODEL') ? OPENAI_CHAT_MODEL : 'gpt-4o-mini';
    $blog   = getBlogContextForChat($userMessage);
    $systemPrompt = "あなたは不動産（戸建て・リノベ・購入・売却・住宅ローンなど）の相談に乗る親切なアシスタントです。"
        . "名前は「" . $agentName . "」です。"
        . "以下のブログ「戸建てリノベINFO」の内容を参照して、質問に答えてください。ブログにない一般的な質問には一般的な知識で答え、ブログの内容がある場合はそれを優先して引用してください。"
        . "回答は日本語で、簡潔かつ分かりやすく。最後に「※参考情報です。個別のご相談は担当者までお問い合わせください。」と付けてください。\n\n"
        . "【参照ブログ】\n" . $blog['context'];
    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    foreach ($conversationHistory as $msg) {
        $role = ($msg['role'] === 'bot' || $msg['role'] === 'assistant') ? 'assistant' : 'user';
        $messages[] = ['role' => $role, 'content' => $msg['message']];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];
    $result = callOpenAIChat($messages, $apiKey, $model);
    if ($result['error'] !== null) {
        return ['reply' => null, 'sources' => $blog['sources'], 'error' => $result['error']];
    }
    return ['reply' => $result['reply'], 'sources' => $blog['sources'], 'error' => null];
}

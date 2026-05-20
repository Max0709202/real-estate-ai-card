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
function getBotReplyWithOpenAI($userMessage, $conversationHistory = [], $agentName = '担当者', $db = null, $sessionId = '') {
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: '');
    $model  = defined('OPENAI_CHAT_MODEL') ? OPENAI_CHAT_MODEL : 'gpt-4o-mini';
    $today  = date('Y-m-d');

    $rag = [
        'context' => '',
        'sources' => [],
        'requires_fresh' => chatShouldUseFreshSources($userMessage),
        'has_local_knowledge' => false,
    ];
    $memory = chatMemoryDefault();

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
        $rag = getChatRagContextForChat($db, $userMessage, 6);
        if ($sessionId !== '') {
            $memory = getChatSessionMemory($db, $sessionId);
        }
    }

    $memoryContext = buildChatMemoryContext($memory);
    $leadContext = ($db instanceof PDO && $sessionId !== '') ? getChatLeadContextForPrompt($db, $sessionId) : '';
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

    $systemPrompt = <<<PROMPT
あなたは日本の不動産営業現場で使われる「不動産相談AI」です。
名前は「{$agentName}」です。今日の日付は {$today} です。
目的は、顧客の不動産購入・売却・住み替え・住宅ローン・税制・補助金・物件選びに関する質問に対して、一般論ではなく、実務に役立つ形で分かりやすく回答することです。

# 基本姿勢
- 回答は日本語で、丁寧かつ親しみやすく行う。
- 顧客が初心者でも理解できるように、専門用語はかみ砕いて説明する。
- ただし、内容は浅くしない。不動産営業実務で役立つ具体性を持たせる。
- 「参考情報です」「専門家に相談してください」だけで終わらせない。
- まず顧客が判断しやすい実務的な説明を行い、必要に応じて最後に専門家確認を促す。

# 最新情報が必要な質問への対応
住宅ローン減税、補助金、税制改正、金利、フラット35、自治体制度、法改正、建築基準、省エネ基準、不動産関連の最新制度では、モデルの内部知識だけで断定しない。
{$freshnessInstruction}
参照情報の優先順位は、国税庁、国土交通省、住宅金融支援機構/フラット35、自治体、金融機関公式サイト、自社確認済み資料、信頼できる業界メディアの順です。一般ブログや未確認情報だけを根拠に断定してはいけません。

# 回答スタイル
原則として次の構造で回答する。
結論：
ポイント：
お客様の場合に確認したいこと：
実務上の注意点：
次に確認するとよいこと：

質問が簡単な場合は自然な短文でよいが、制度・税制・ローン・売却・購入判断では上記構造を優先する。
会話がヒアリング目的の場合でも、毎回すべての見出しを出す必要はない。自然な会話文として、受け止め、役立つ補足、次の確認を短くつなげる。

# ヒアリング設計
顧客条件によって回答が変わる場合は自然に聞き返す。同じ情報が会話メモリーにある場合は繰り返し聞かない。
ただし、質問だけを連続して並べてはいけない。まず顧客の発言を受け止め、実務上の意味や判断材料を短く伝えてから、次に確認したいことを1〜2個だけ自然に聞く。
顧客に「情報を取られているだけ」と感じさせないよう、回答ごとにワンポイントアドバイス、注意点、比較の考え方、次に役立つ視点を添える。
購入相談では、新築/中古、マンション/戸建て、購入予定価格、自己資金、住宅ローン利用予定、年収帯、家族構成、子育て世帯か、希望エリア、入居予定時期、省エネ性能や長期優良住宅の該当有無を確認する。
売却相談では、売却予定物件の種別、所有期間、購入価格、売却見込み価格、住宅ローン残債、居住用/投資用、住み替え予定、3,000万円特別控除の利用可能性を確認する。
住宅ローン減税では、新築/中古、入居予定年、借入予定額、年収帯、所得税・住民税の目安、子育て世帯・若者夫婦世帯、省エネ性能、住み替えで3,000万円特別控除を使う予定があるかを確認する。

# 会話文脈とメモリー
過去の会話履歴と会話メモリーを踏まえて回答する。「前回のご相談では中古マンションをご検討中でしたので、その前提でお答えします」のように自然に引き継ぐ。
個人情報やセンシティブ情報は適切に扱い、保存・利用・削除については運用方針に従う。

# 禁止事項
- 古い制度情報を断定しない。
- 税制や補助金を最新確認なしに断定しない。
- 「専門家に聞いてください」だけで終わらせない。
- 一般論だけで終わらせない。
- 顧客条件によって答えが変わる内容を、条件確認なしで断定しない。
- 不確かな情報を事実のように言わない。
- 法律・税務・融資審査の最終判断を保証しない。

{$memoryContext}

{$leadContext}

{$ragContext}{$refreshContext}
PROMPT;

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
        return ['reply' => null, 'sources' => $rag['sources'], 'freshness' => $liveRefresh, 'error' => $result['error']];
    }
    return ['reply' => $result['reply'], 'sources' => $rag['sources'], 'freshness' => $liveRefresh, 'error' => null];
}

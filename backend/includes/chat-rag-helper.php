<?php
/**
 * Local RAG and conversation memory helpers for the real estate chat widget.
 * The live chat path normally reads local chunks. For latest-sensitive questions,
 * it may refresh stale official sources before retrieving context.
 */

function chatDefaultKnowledgeSources() {
    return [
        [
            'source_key' => 'nta_housing_loan_1211_1',
            'title' => '国税庁: 住宅の新築等をし、令和4年以降に居住した場合の住宅借入金等特別控除',
            'url' => 'https://www.nta.go.jp/taxes/shiraberu/taxanswer/shotoku/1211-1.htm',
            'source_type' => 'official_tax',
            'priority' => 10,
        ],
        [
            'source_key' => 'nta_housing_loan_1225',
            'title' => '国税庁: 住宅借入金等特別控除の対象となる住宅ローン等',
            'url' => 'https://www.nta.go.jp/taxes/shiraberu/taxanswer/shotoku/1225.htm',
            'source_type' => 'official_tax',
            'priority' => 11,
        ],
        [
            'source_key' => 'nta_home_sale_3302',
            'title' => '国税庁: マイホームを売ったときの特例',
            'url' => 'https://www.nta.go.jp/taxes/shiraberu/taxanswer/joto/3302.htm',
            'source_type' => 'official_tax',
            'priority' => 12,
        ],
        [
            'source_key' => 'mlit_housing_loan_tax_r7',
            'title' => '国土交通省: 住宅ローン減税（所得税・個人住民税）',
            'url' => 'https://www.mlit.go.jp/jutakukentiku/house/shienjigyo_r7-06.html',
            'source_type' => 'official_mlit',
            'priority' => 20,
        ],
        [
            'source_key' => 'mlit_reform_tax',
            'title' => '国土交通省: 住宅をリフォームした場合に使える減税制度',
            'url' => 'https://www.mlit.go.jp/jutakukentiku/house/jutakukentiku_house_tk4_000251.html',
            'source_type' => 'official_mlit',
            'priority' => 21,
        ],
        [
            'source_key' => 'mlit_mirai_eco_2026',
            'title' => '国土交通省: みらいエコ住宅2026事業について',
            'url' => 'https://www.mlit.go.jp/jutakukentiku/house/jutakukentiku_house_tk4_000310.html',
            'source_type' => 'official_mlit',
            'priority' => 22,
        ],
        [
            'source_key' => 'mirai_eco_2026_about',
            'title' => 'みらいエコ住宅2026事業公式: 事業概要',
            'url' => 'https://mirai-eco2026.mlit.go.jp/about/',
            'source_type' => 'official_subsidy',
            'priority' => 23,
        ],
        [
            'source_key' => 'jhf_interest_rates',
            'title' => '住宅金融支援機構: 金利情報',
            'url' => 'https://www.jhf.go.jp/kinri/index.html',
            'source_type' => 'official_finance',
            'priority' => 30,
        ],
        [
            'source_key' => 'flat35_zeh',
            'title' => 'フラット35: フラット35S（ZEH）',
            'url' => 'https://www.flat35.com/loan/lineup/flat35s_zeh/index.html',
            'source_type' => 'official_finance',
            'priority' => 31,
        ],
        [
            'source_key' => 'company_blog_renovation_info',
            'title' => '自社参考ブログ: 戸建てリノベINFO',
            'url' => defined('CHAT_BLOG_BASE_URL') ? CHAT_BLOG_BASE_URL : 'https://smile.re-agent.info/blog/',
            'source_type' => 'company_blog',
            'priority' => 90,
        ],
    ];
}

function ensureChatRagTables($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS chat_knowledge_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_key VARCHAR(120) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        url TEXT NOT NULL,
        source_type VARCHAR(50) NOT NULL DEFAULT 'official',
        priority INT NOT NULL DEFAULT 100,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_fetched_at TIMESTAMP NULL DEFAULT NULL,
        last_status VARCHAR(30) NULL DEFAULT NULL,
        last_error TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_chat_knowledge_sources_enabled (enabled, priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS chat_knowledge_chunks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_id INT NOT NULL,
        chunk_index INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        url TEXT NOT NULL,
        content MEDIUMTEXT NOT NULL,
        content_hash CHAR(64) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (source_id) REFERENCES chat_knowledge_sources(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_chat_knowledge_chunk (source_id, chunk_index),
        INDEX idx_chat_knowledge_chunks_source (source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS chat_session_memory (
        session_id CHAR(36) PRIMARY KEY,
        business_card_id INT NULL,
        memory_json JSON NULL,
        last_summary TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
        INDEX idx_chat_session_memory_card (business_card_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS agent_custom_rag_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_card_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        content MEDIUMTEXT NOT NULL,
        source_note VARCHAR(255) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
        INDEX idx_agent_custom_rag_card_enabled (business_card_id, enabled),
        FULLTEXT KEY ft_agent_custom_rag (title, content)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS agent_prohibited_words (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_card_id INT NOT NULL,
        word VARCHAR(255) NOT NULL,
        replacement VARCHAR(255) NULL,
        note VARCHAR(255) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_agent_prohibited_word (business_card_id, word),
        INDEX idx_agent_prohibited_card_enabled (business_card_id, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seedDefaultChatKnowledgeSources($db) {
    ensureChatRagTables($db);
    $sql = "INSERT INTO chat_knowledge_sources (source_key, title, url, source_type, priority, enabled)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE title = VALUES(title), url = VALUES(url), source_type = VALUES(source_type), priority = VALUES(priority), enabled = 1";
    $stmt = $db->prepare($sql);
    foreach (chatDefaultKnowledgeSources() as $source) {
        $stmt->execute([
            $source['source_key'],
            $source['title'],
            $source['url'],
            $source['source_type'],
            $source['priority'],
        ]);
    }
}

function chatShouldUseFreshSources($message) {
    return (bool) preg_match('/今年度|最新|202[0-9]|令和[0-9０-９]+|税制|減税|補助金|控除|金利|フラット\s*35|省エネ|ZEH|長期優良住宅|法改正|自治体|制度|住宅ローン|住宅借入金|みらいエコ|子育て|若者夫婦/u', $message);
}


function chatOfficialSourceTypes() {
    return ['official_tax', 'official_mlit', 'official_finance', 'official_subsidy'];
}

function chatRelevantFreshSourceKeys($message) {
    $keys = [];
    $add = function ($items) use (&$keys) {
        foreach ($items as $item) {
            if (!in_array($item, $keys, true)) $keys[] = $item;
        }
    };

    if (preg_match('/住宅ローン減税|住宅ローン控除|住宅借入金|控除|税制|減税|子育て|若者夫婦/u', $message)) {
        $add(['nta_housing_loan_1211_1', 'nta_housing_loan_1225', 'mlit_housing_loan_tax_r7']);
    }
    if (preg_match('/売却|住み替え|3000|3,000|譲渡|特別控除/u', $message)) {
        $add(['nta_home_sale_3302']);
    }
    if (preg_match('/補助金|省エネ|ZEH|長期優良|みらいエコ|リフォーム/u', $message)) {
        $add(['mlit_mirai_eco_2026', 'mirai_eco_2026_about', 'mlit_reform_tax', 'flat35_zeh']);
    }
    if (preg_match('/金利|フラット\s*35|フラット３５/u', $message)) {
        $add(['jhf_interest_rates', 'flat35_zeh']);
    }
    if (empty($keys)) {
        $add(['mlit_housing_loan_tax_r7', 'jhf_interest_rates', 'mirai_eco_2026_about']);
    }

    return array_slice($keys, 0, 4);
}

function refreshChatKnowledgeForMessage($db, $message, $maxAgeHours = 24) {
    $status = [
        'attempted' => false,
        'updated' => 0,
        'failed' => 0,
        'skipped' => true,
        'source_keys' => [],
        'errors' => [],
    ];
    if (!$db || !chatShouldUseFreshSources($message)) return $status;

    try {
        ensureChatRagTables($db);
        seedDefaultChatKnowledgeSources($db);

        $keys = chatRelevantFreshSourceKeys($message);
        if (empty($keys)) return $status;

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $hours = max(1, min(168, (int)$maxAgeHours));
        $sql = "SELECT source_key FROM chat_knowledge_sources
                WHERE enabled = 1
                  AND source_key IN ({$placeholders})
                  AND source_type IN ('official_tax', 'official_mlit', 'official_finance', 'official_subsidy')
                  AND (last_fetched_at IS NULL OR last_fetched_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR) OR last_status IS NULL OR last_status <> 'ok')
                ORDER BY priority ASC
                LIMIT 4";
        $stmt = $db->prepare($sql);
        $stmt->execute($keys);
        $staleKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($staleKeys)) return $status;

        $status['attempted'] = true;
        $status['skipped'] = false;
        $status['source_keys'] = $staleKeys;
        $results = syncChatKnowledgeSources($db, null, chatOfficialSourceTypes(), $staleKeys);
        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'ok') {
                $status['updated']++;
            } else {
                $status['failed']++;
                if (!empty($result['error'])) $status['errors'][] = $result['source_key'] . ': ' . $result['error'];
            }
        }
    } catch (Throwable $e) {
        $status['attempted'] = true;
        $status['skipped'] = false;
        $status['failed']++;
        $status['errors'][] = $e->getMessage();
        error_log('Chat live knowledge refresh error: ' . $e->getMessage());
    }

    return $status;
}

function chatExtractSearchTerms($message) {
    $terms = [];
    $keywords = [
        '住宅ローン減税', '住宅ローン控除', '住宅借入金等特別控除', '住宅ローン', '補助金', '税制', '減税', '控除',
        '金利', 'フラット35', 'フラット３５', '省エネ', 'ZEH', '長期優良住宅', '子育て', '若者夫婦', '中古', '新築',
        'マンション', '戸建て', '売却', '住み替え', '投資', '3,000万円', '3000万円', '特別控除', '自治体', '制度',
        'みらいエコ', 'リフォーム', 'リノベ', '建築基準', '入居', '年収', '借入', '予算', 'インスペクション', '建物状況調査', '耐震', '管理状態', '修繕積立金', '既存住宅', '中古住宅'
    ];
    foreach ($keywords as $keyword) {
        if (mb_stripos($message, $keyword) !== false) {
            $terms[] = $keyword;
        }
    }
    if (preg_match_all('/(20[0-9]{2}|令和[0-9０-９]+|[0-9０-９,\.]+\s*(万円|億円|円))/u', $message, $m)) {
        foreach ($m[1] as $term) {
            $terms[] = trim($term);
        }
    }
    if (preg_match('/住宅ローン減税|住宅ローン控除|住宅借入金/u', $message)) {
        $terms = array_merge($terms, ['住宅借入金等特別控除', '省エネ', '子育て', '入居', '控除']);
    }
    if (preg_match('/売却|住み替え|3000|3,000|譲渡/u', $message)) {
        $terms = array_merge($terms, ['マイホーム', '3,000万円', '特別控除', '譲渡所得']);
    }
    if (preg_match('/補助金|省エネ|ZEH|長期優良|みらいエコ/u', $message)) {
        $terms = array_merge($terms, ['みらいエコ', '省エネ', '長期優良住宅', 'ZEH']);
    }
    if (preg_match('/インスペクション|建物状況調査|耐震|管理状態|修繕積立/u', $message)) {
        $terms = array_merge($terms, ['インスペクション', '建物状況調査', '中古住宅', '既存住宅', '耐震', '管理状態', '修繕積立金']);
    }
    $terms = array_values(array_unique(array_filter(array_map('trim', $terms))));
    return array_slice($terms, 0, 16);
}

function chatScoreKnowledgeChunk($row, $terms) {
    $haystack = mb_strtolower(($row['title'] ?? '') . "\n" . ($row['content'] ?? ''));
    $score = max(0, (int) floor((100 - (int)($row['priority'] ?? 100)) / 10));
    foreach ($terms as $term) {
        $needle = mb_strtolower($term);
        if ($needle === '') continue;
        $titleHit = mb_stripos(mb_strtolower($row['title'] ?? ''), $needle) !== false;
        $bodyHit = mb_stripos($haystack, $needle) !== false;
        if ($titleHit) $score += 18;
        if ($bodyHit) $score += 8;
    }
    if (in_array($row['source_type'] ?? '', ['official_tax', 'official_mlit', 'official_finance', 'official_subsidy'], true)) {
        $score += 15;
    }
    return $score;
}

function getChatRagContextForChat($db, $message, $limit = 6) {
    $empty = [
        'context' => '',
        'sources' => [],
        'requires_fresh' => chatShouldUseFreshSources($message),
        'has_local_knowledge' => false,
    ];
    if (!$db) return $empty;

    try {
        ensureChatRagTables($db);
        seedDefaultChatKnowledgeSources($db);

        $terms = chatExtractSearchTerms($message);
        $where = "s.enabled = 1";
        $params = [];
        if (!empty($terms)) {
            $likes = [];
            foreach (array_slice($terms, 0, 8) as $term) {
                $likes[] = "(c.content LIKE ? OR c.title LIKE ? OR s.title LIKE ?)";
                $like = '%' . $term . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $where .= " AND (" . implode(' OR ', $likes) . ")";
        }

        $sql = "SELECT c.id, c.title, c.url, c.content, c.updated_at,
                       s.title AS source_title, s.source_type, s.priority, s.last_fetched_at
                FROM chat_knowledge_chunks c
                JOIN chat_knowledge_sources s ON s.id = c.source_id
                WHERE {$where}
                ORDER BY s.priority ASC, c.id ASC
                LIMIT 300";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows) && !empty($terms)) {
            $stmt = $db->query("SELECT c.id, c.title, c.url, c.content, c.updated_at,
                                       s.title AS source_title, s.source_type, s.priority, s.last_fetched_at
                                FROM chat_knowledge_chunks c
                                JOIN chat_knowledge_sources s ON s.id = c.source_id
                                WHERE s.enabled = 1
                                ORDER BY s.priority ASC, c.id ASC
                                LIMIT 120");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($rows)) return $empty;

        foreach ($rows as &$row) {
            $row['_score'] = chatScoreKnowledgeChunk($row, $terms);
        }
        unset($row);
        usort($rows, function ($a, $b) {
            if ($a['_score'] === $b['_score']) {
                return ((int)$a['priority']) <=> ((int)$b['priority']);
            }
            return $b['_score'] <=> $a['_score'];
        });

        $selected = array_slice(array_filter($rows, function ($row) {
            return $row['_score'] > 0;
        }), 0, $limit);
        if (empty($selected)) {
            $selected = array_slice($rows, 0, min(3, $limit));
        }

        $parts = [];
        $sources = [];
        $seenUrls = [];
        $totalLength = 0;
        foreach ($selected as $idx => $row) {
            $snippet = trim($row['content']);
            if (mb_strlen($snippet) > 750) {
                $snippet = mb_substr($snippet, 0, 750) . '…';
            }
            $label = '[' . ($idx + 1) . '] ' . $row['source_title'];
            $fetched = $row['last_fetched_at'] ? '取得日: ' . $row['last_fetched_at'] : '取得日: 未同期';
            $part = $label . "\nURL: " . $row['url'] . "\n" . $fetched . "\n抜粋: " . $snippet;
            $parts[] = $part;
            $totalLength += mb_strlen($part);
            if (!isset($seenUrls[$row['url']])) {
                $seenUrls[$row['url']] = true;
                $sources[] = [
                    'url' => $row['url'],
                    'title' => $row['source_title'],
                    'type' => $row['source_type'],
                    'last_fetched_at' => $row['last_fetched_at'],
                ];
            }
            if ($totalLength > 3600) break;
        }

        return [
            'context' => "【ローカルRAG参照情報】\n" . implode("\n\n", $parts),
            'sources' => $sources,
            'requires_fresh' => chatShouldUseFreshSources($message),
            'has_local_knowledge' => !empty($sources),
        ];
    } catch (Throwable $e) {
        error_log('Chat RAG context error: ' . $e->getMessage());
        return $empty;
    }
}

function getAgentCustomContextForChat($db, $businessCardId, $message, $limit = 5) {
    $empty = ['context' => '', 'sources' => [], 'prohibited_words' => []];
    if (!$db instanceof PDO || !$businessCardId) return $empty;

    try {
        ensureChatRagTables($db);
        $terms = chatExtractSearchTerms($message);
        if (preg_match_all('/[一-龥ぁ-んァ-ンA-Za-z0-9０-９]{2,}/u', (string)$message, $m)) {
            foreach ($m[0] as $term) {
                $terms[] = $term;
            }
        }
        $terms = array_values(array_unique(array_filter(array_map('trim', $terms))));

        $where = 'business_card_id = ? AND enabled = 1';
        $params = [(int)$businessCardId];
        if (!empty($terms)) {
            $likes = [];
            foreach (array_slice($terms, 0, 8) as $term) {
                $likes[] = '(title LIKE ? OR content LIKE ? OR source_note LIKE ?)';
                $like = '%' . $term . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $where .= ' AND (' . implode(' OR ', $likes) . ')';
        }

        $stmt = $db->prepare("SELECT id, title, content, source_note, updated_at
            FROM agent_custom_rag_items
            WHERE {$where}
            ORDER BY updated_at DESC, id DESC
            LIMIT 80");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows) && empty($terms)) {
            $stmt = $db->prepare("SELECT id, title, content, source_note, updated_at
                FROM agent_custom_rag_items
                WHERE business_card_id = ? AND enabled = 1
                ORDER BY updated_at DESC, id DESC
                LIMIT 20");
            $stmt->execute([(int)$businessCardId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($rows)) {
            foreach ($rows as &$row) {
                $row['_score'] = chatScoreKnowledgeChunk([
                    'title' => $row['title'] ?? '',
                    'content' => $row['content'] ?? '',
                    'priority' => 35,
                    'source_type' => 'agent_custom',
                ], $terms);
            }
            unset($row);
            usort($rows, function ($a, $b) {
                if ($a['_score'] === $b['_score']) return strcmp((string)$b['updated_at'], (string)$a['updated_at']);
                return $b['_score'] <=> $a['_score'];
            });
        }

        $parts = [];
        $sources = [];
        foreach (array_slice($rows, 0, max(1, min(10, (int)$limit))) as $idx => $row) {
            $snippet = trim((string)$row['content']);
            if (mb_strlen($snippet) > 700) $snippet = mb_substr($snippet, 0, 700) . '…';
            $note = trim((string)($row['source_note'] ?? ''));
            $parts[] = '[' . ($idx + 1) . '] ' . ($row['title'] ?: '追加RAG') . ($note !== '' ? "\nメモ: " . $note : '') . "\n内容: " . $snippet;
            $sources[] = [
                'url' => '',
                'title' => '担当者追加RAG: ' . ($row['title'] ?: '追加RAG'),
                'type' => 'agent_custom',
                'last_fetched_at' => $row['updated_at'] ?? null,
            ];
        }

        $stmt = $db->prepare("SELECT word, replacement, note
            FROM agent_prohibited_words
            WHERE business_card_id = ? AND enabled = 1
            ORDER BY updated_at DESC, id DESC
            LIMIT 200");
        $stmt->execute([(int)$businessCardId]);
        $words = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'context' => !empty($parts) ? "【担当者追加RAG】\n" . implode("\n\n", $parts) : '',
            'sources' => $sources,
            'prohibited_words' => $words,
        ];
    } catch (Throwable $e) {
        error_log('Agent custom context error: ' . $e->getMessage());
        return $empty;
    }
}

function buildAgentProhibitedWordsPrompt($words) {
    if (empty($words) || !is_array($words)) return '';
    $lines = [];
    foreach (array_slice($words, 0, 100) as $row) {
        $word = trim((string)($row['word'] ?? ''));
        if ($word === '') continue;
        $replacement = trim((string)($row['replacement'] ?? ''));
        $note = trim((string)($row['note'] ?? ''));
        $line = '- "' . $word . '" は使わない';
        if ($replacement !== '') $line .= '。必要なら "' . $replacement . '" に言い換える';
        if ($note !== '') $line .= '（' . $note . '）';
        $lines[] = $line;
    }
    if (empty($lines)) return '';
    return "【担当者指定の禁止ワード】\n以下の語句・表現は、この担当者のAI回答で使わないでください。出力前に必ず言い換えてください。\n" . implode("\n", $lines);
}

function applyAgentProhibitedWordsToReply($reply, $words) {
    $reply = (string)$reply;
    if ($reply === '' || empty($words) || !is_array($words)) return $reply;
    foreach ($words as $row) {
        $word = trim((string)($row['word'] ?? ''));
        if ($word === '') continue;
        $replacement = trim((string)($row['replacement'] ?? ''));
        $reply = str_replace($word, $replacement !== '' ? $replacement : '別表現', $reply);
    }
    return $reply;
}

function chatExtractPageTitle($html, $fallback) {
    if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title !== '') return mb_substr($title, 0, 255);
    }
    return $fallback;
}

function chatSplitIntoChunks($text, $chunkSize = 1500, $overlap = 160) {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') return [];
    $chunks = [];
    $length = mb_strlen($text);
    $start = 0;
    while ($start < $length) {
        $chunk = mb_substr($text, $start, $chunkSize);
        $chunks[] = trim($chunk);
        $next = $start + $chunkSize - $overlap;
        if ($next <= $start) $next = $start + $chunkSize;
        $start = $next;
    }
    return array_values(array_filter($chunks, function ($chunk) {
        return mb_strlen($chunk) > 120;
    }));
}

function syncChatKnowledgeSources($db, $limit = null, $sourceTypes = null, $sourceKeys = null) {
    ensureChatRagTables($db);
    seedDefaultChatKnowledgeSources($db);

    $sql = "SELECT * FROM chat_knowledge_sources WHERE enabled = 1 AND url NOT LIKE 'file:%'";
    $params = [];
    if (is_array($sourceTypes) && !empty($sourceTypes)) {
        $sql .= " AND source_type IN (" . implode(',', array_fill(0, count($sourceTypes), '?')) . ")";
        foreach ($sourceTypes as $type) $params[] = $type;
    }
    if (is_array($sourceKeys) && !empty($sourceKeys)) {
        $sql .= " AND source_key IN (" . implode(',', array_fill(0, count($sourceKeys), '?')) . ")";
        foreach ($sourceKeys as $key) $params[] = $key;
    }
    $sql .= " ORDER BY priority ASC, id ASC";
    if ($limit !== null) {
        $sql .= " LIMIT " . max(1, (int)$limit);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results = [];

    foreach ($sources as $source) {
        $status = ['source_key' => $source['source_key'], 'url' => $source['url'], 'chunks' => 0, 'status' => 'failed'];
        try {
            $html = fetchUrlForChat($source['url'], 12);
            if ($html === '') {
                throw new RuntimeException('Empty response');
            }
            $title = chatExtractPageTitle($html, $source['title']);
            $text = extractTextFromHtml($html, 40000);
            if (mb_strlen($text) < 200) {
                throw new RuntimeException('Extracted text is too short');
            }
            $chunks = chatSplitIntoChunks($text);
            if (empty($chunks)) {
                throw new RuntimeException('No chunks produced');
            }

            $db->beginTransaction();
            $delete = $db->prepare('DELETE FROM chat_knowledge_chunks WHERE source_id = ?');
            $delete->execute([$source['id']]);
            $insert = $db->prepare('INSERT INTO chat_knowledge_chunks (source_id, chunk_index, title, url, content, content_hash) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($chunks as $idx => $chunk) {
                $insert->execute([$source['id'], $idx, $title, $source['url'], $chunk, hash('sha256', $chunk)]);
            }
            $update = $db->prepare("UPDATE chat_knowledge_sources SET title = ?, last_fetched_at = CURRENT_TIMESTAMP, last_status = 'ok', last_error = NULL WHERE id = ?");
            $update->execute([$title, $source['id']]);
            $db->commit();

            $status['chunks'] = count($chunks);
            $status['status'] = 'ok';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $update = $db->prepare("UPDATE chat_knowledge_sources SET last_status = 'failed', last_error = ? WHERE id = ?");
            $update->execute([mb_substr($e->getMessage(), 0, 1000), $source['id']]);
            $status['error'] = $e->getMessage();
        }
        $results[] = $status;
    }
    return $results;
}

function chatMemoryDefault() {
    return [
        'intent' => null,
        'property_type' => null,
        'topics' => [],
        'budget' => null,
        'income_range' => null,
        'family' => null,
        'child_rearing_household' => null,
        'preferred_area' => null,
        'loan_plan' => null,
        'temperature' => null,
        'lead_summary' => null,
        'next_action' => null,
        'recent_context' => null,
        'previous_suggestions' => [],
        'missing_info' => [],
        'last_summary' => null,
        'last_updated_at' => null,
    ];
}

function getChatSessionMemory($db, $sessionId) {
    if (!$db || $sessionId === '') return chatMemoryDefault();
    try {
        ensureChatRagTables($db);
        $stmt = $db->prepare('SELECT memory_json, last_summary FROM chat_session_memory WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return chatMemoryDefault();
        $memory = json_decode($row['memory_json'] ?: '{}', true);
        if (!is_array($memory)) $memory = [];
        $memory = array_merge(chatMemoryDefault(), $memory);
        if (!empty($row['last_summary'])) $memory['last_summary'] = $row['last_summary'];
        return $memory;
    } catch (Throwable $e) {
        error_log('Chat memory load error: ' . $e->getMessage());
        return chatMemoryDefault();
    }
}

function chatSetIfDetected(&$memory, $key, $value) {
    if ($value !== null && $value !== '') $memory[$key] = $value;
}

function chatAddTopic(&$memory, $topic) {
    if (!isset($memory['topics']) || !is_array($memory['topics'])) $memory['topics'] = [];
    if (!in_array($topic, $memory['topics'], true)) $memory['topics'][] = $topic;
}

function chatMemoryValue($value) {
    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            if ($item === null || $item === '' || $item === []) continue;
            $items[] = is_array($item) ? chatMemoryValue($item) : (string)$item;
        }
        $items = array_values(array_filter($items, function ($item) { return $item !== ''; }));
        return empty($items) ? null : implode('、', $items);
    }
    if (is_bool($value)) return $value ? 'はい' : 'いいえ';
    if ($value === null || $value === '') return null;
    return (string)$value;
}

function chatMemoryValueLooksUsable($value, $field = '') {
    $text = chatMemoryValue($value);
    if ($text === null) return false;
    $plain = trim(preg_replace('/[\s　[:punct:]。、，．・！？?！「」『』（）()【】\[\]{}]+/u', '', $text));
    if ($plain === '') return false;
    if (mb_strlen($plain) <= 1 && !preg_match('/[0-9０-９]/u', $plain)) return false;
    if (preg_match('/^(.)\\1{2,}$/u', $plain)) return false;
    if (preg_match('/^(?:test|asdf|qwerty|dummy|sample|abc|xxx|aaa|ok)$/iu', $plain)) return false;
    if (preg_match('/(?:意味不明|意味わから|意味がわから|分からん|わからん|何でもいい|なんでもいい|適当|テスト|ダミー|サンプル|よろしく|お願いします|お願い|こんにちは|ありがとう)$/u', $plain)) return false;

    if (in_array((string)$field, ['preferred_area', 'property_location', 'current_property_location'], true)) {
        if (preg_match('/^[A-Za-z]{2,}$/u', $plain)) return false;
        if (!preg_match('/(?:駅|線|沿線|市|区|町|村|都|道|府|県|丁目|番地|号|マンション|周辺|エリア|[一-龥ぁ-んァ-ン]{2,})/u', $plain)) return false;
    }

    return true;
}

function chatMemoryLeadFieldIsReliable($leadData, $field) {
    if (!is_array($leadData) || !isset($leadData[$field]) || $leadData[$field] === null || $leadData[$field] === '' || $leadData[$field] === []) return false;
    $meta = is_array($leadData['_field_meta'] ?? null) && is_array($leadData['_field_meta'][$field] ?? null) ? $leadData['_field_meta'][$field] : null;
    if ($meta !== null) {
        $status = $meta['status'] ?? 'confirmed';
        $confidence = $meta['confidence'] ?? 'high';
        if (!in_array($status, ['confirmed', 'inferred'], true)) return false;
        if (!in_array($confidence, ['high', 'medium'], true)) return false;
    }
    return chatMemoryValueLooksUsable($leadData[$field], $field);
}

function chatMemoryTrim($text, $max = 240) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if ($text === '') return '';
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
}

function chatMemoryMapCustomerType($type) {
    $map = [
        'purchase' => 'purchase',
        'replacement' => 'relocation',
        'sale' => 'sale',
        'investment_buy' => 'investment_buy',
        'investment_sale' => 'investment_sale',
        'loan' => 'loan',
        'market' => 'market',
        'inheritance' => 'inheritance',
        'other' => 'other',
    ];
    return $map[$type] ?? $type;
}

function chatMemoryIntentLabel($intent) {
    $map = [
        'purchase' => '購入',
        'relocation' => '住み替え',
        'sale' => '売却',
        'investment' => '投資',
        'investment_buy' => '投資物件購入',
        'investment_sale' => '投資物件売却',
        'loan' => '住宅ローン相談',
        'market' => '相場相談',
        'inheritance' => '相続相談',
        'other' => 'その他',
    ];
    return $map[$intent] ?? $intent;
}

function chatMemoryCustomerIntentLabel($intent) {
    $map = [
        'purchase' => '購入をご検討中',
        'relocation' => '住み替えをご検討中',
        'sale' => '売却をご検討中',
        'investment' => '投資物件についてご相談中',
        'investment_buy' => '投資物件の購入をご検討中',
        'investment_sale' => '投資物件の売却をご検討中',
        'loan' => '住宅ローンについてご相談中',
        'market' => '相場についてご相談中',
        'inheritance' => '相続についてご相談中',
        'other' => '不動産についてご相談中',
    ];
    return $map[$intent] ?? chatMemoryIntentLabel($intent);
}

function chatLoadLeadDataForMemory($db, $sessionId) {
    if (!$db || $sessionId === '') return [];
    try {
        $stmt = $db->prepare('SELECT structured_data FROM chat_leads WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $json = $stmt->fetchColumn();
        $data = $json ? json_decode($json, true) : [];
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        error_log('Chat memory lead load error: ' . $e->getMessage());
        return [];
    }
}

function chatApplyLeadDataToMemory(&$memory, $leadData) {
    if (!$leadData || !is_array($leadData)) return;

    if (chatMemoryLeadFieldIsReliable($leadData, 'customer_type')) $memory['intent'] = chatMemoryMapCustomerType($leadData['customer_type']);
    foreach (['property_type', 'preferred_area', 'family_structure'] as $key) {
        if (!chatMemoryLeadFieldIsReliable($leadData, $key)) continue;
        $value = chatMemoryValue($leadData[$key] ?? null);
        if ($value !== null) {
            if ($key === 'family_structure') $memory['family'] = $value;
            else $memory[$key] = $value;
        }
    }

    $budgetParts = [];
    $budgetReliable = chatMemoryLeadFieldIsReliable($leadData, 'budget') || !empty($leadData['budget_min']) || !empty($leadData['budget_max']);
    if ($budgetReliable && !empty($leadData['budget_min'])) $budgetParts[] = '下限 ' . $leadData['budget_min'];
    if ($budgetReliable && !empty($leadData['budget_max'])) $budgetParts[] = '上限 ' . $leadData['budget_max'];
    if (!empty($budgetParts)) $memory['budget'] = implode(' / ', $budgetParts);

    $income = chatMemoryLeadFieldIsReliable($leadData, 'income') ? chatMemoryValue($leadData['income'] ?? null) : null;
    if ($income !== null) $memory['income_range'] = $income;

    $loanBits = [];
    foreach (['loan_status', 'pre_approval_status', 'income', 'down_payment', 'desired_loan_amount', 'desired_monthly_payment', 'loan_simulation_used', 'simulation_monthly_payment', 'simulation_interest_type'] as $key) {
        if (!chatMemoryLeadFieldIsReliable($leadData, $key)) continue;
        $value = chatMemoryValue($leadData[$key] ?? null);
        if ($value !== null) $loanBits[] = $value;
    }
    if (!empty($loanBits)) $memory['loan_plan'] = implode(' / ', array_unique($loanBits));

    if (!empty($leadData['temperature'])) $memory['temperature'] = $leadData['temperature'];
    if (!empty($leadData['summary_for_sales']) && chatMemoryValueLooksUsable($leadData['summary_for_sales'])) $memory['lead_summary'] = chatMemoryTrim($leadData['summary_for_sales'], 360);
    if (!empty($leadData['next_action']) && chatMemoryValueLooksUsable($leadData['next_action'])) $memory['next_action'] = chatMemoryTrim($leadData['next_action'], 220);

    $topicFields = ['priority', 'loan_concern', 'other_debts', 'disclosure_flags'];
    foreach ($topicFields as $field) {
        if (!chatMemoryLeadFieldIsReliable($leadData, $field)) continue;
        $value = chatMemoryValue($leadData[$field] ?? null);
        if ($value !== null) chatAddTopic($memory, $value);
    }

    if (chatMemoryLeadFieldIsReliable($leadData, 'family_structure') && preg_match('/子ども|子供|こども/u', chatMemoryValue($leadData['family_structure']))) {
        $memory['child_rearing_household'] = true;
    }

    if (!empty($leadData['missing_fields']) && is_array($leadData['missing_fields'])) {
        $memory['missing_info'] = array_values(array_unique(array_merge($memory['missing_info'] ?? [], $leadData['missing_fields'])));
    }
}

function chatDetectMemoryFromMessage(&$memory, $message) {
    if (preg_match('/購入|買いたい|買う|取得/u', $message)) chatSetIfDetected($memory, 'intent', 'purchase');
    if (preg_match('/売却|売りたい|売る|査定/u', $message)) chatSetIfDetected($memory, 'intent', 'sale');
    if (preg_match('/住み替え|買い替え/u', $message)) chatSetIfDetected($memory, 'intent', 'relocation');
    if (preg_match('/投資|収益/u', $message)) chatSetIfDetected($memory, 'intent', 'investment');

    $property = null;
    if (preg_match('/中古.*マンション|マンション.*中古/u', $message)) $property = '中古マンション';
    elseif (preg_match('/新築.*マンション|マンション.*新築/u', $message)) $property = '新築マンション';
    elseif (preg_match('/中古.*(戸建|一戸建)|(?:戸建|一戸建).*中古/u', $message)) $property = '中古戸建て';
    elseif (preg_match('/新築.*(戸建|一戸建)|(?:戸建|一戸建).*新築/u', $message)) $property = '新築戸建て';
    elseif (preg_match('/マンション/u', $message)) $property = 'マンション';
    elseif (preg_match('/戸建|一戸建/u', $message)) $property = '戸建て';
    chatSetIfDetected($memory, 'property_type', $property);

    $topicMap = [
        '住宅ローン減税' => '/住宅ローン減税|住宅ローン控除|住宅借入金/u',
        '補助金' => '/補助金|みらいエコ|子育てグリーン/u',
        '金利・フラット35' => '/金利|フラット\s*35|フラット３５/u',
        '省エネ・ZEH' => '/省エネ|ZEH|長期優良住宅|GX/u',
        '3,000万円特別控除' => '/3,000万円|3000万円|特別控除|譲渡所得/u',
        'リフォーム・リノベ' => '/リフォーム|リノベ/u',
        'インスペクション・建物調査' => '/インスペクション|建物状況調査|耐震|管理状態|修繕積立/u',
    ];
    foreach ($topicMap as $topic => $pattern) {
        if (preg_match($pattern, $message)) chatAddTopic($memory, $topic);
    }

    if (preg_match('/子育て|子ども|子供|こども|18歳|若者夫婦/u', $message)) {
        $memory['child_rearing_household'] = true;
        $memory['family'] = $memory['family'] ?: '子育て世帯または若者夫婦世帯の可能性あり';
    }
    if (preg_match('/ローン|借入/u', $message)) $memory['loan_plan'] = $memory['loan_plan'] ?: '住宅ローン利用予定または検討中';
    if (preg_match('/年収\s*([0-9０-９,\.]+\s*(万円|万|円))/u', $message, $m)) $memory['income_range'] = $m[1];
    if (preg_match('/(?:予算|価格|物件価格|購入価格)\s*(?:は|が|:|：)?\s*([0-9０-９,\.]+\s*(万円|万|億円|円))/u', $message, $m)) $memory['budget'] = $m[1];
    if (preg_match('/([一-龥ぁ-んァ-ン]{2,12}(都|道|府|県|市|区|町|村))/u', $message, $m)) $memory['preferred_area'] = $m[1];
}

function chatBuildMissingInfo($memory) {
    $missing = isset($memory['missing_info']) && is_array($memory['missing_info']) ? $memory['missing_info'] : [];
    $topics = isset($memory['topics']) && is_array($memory['topics']) ? $memory['topics'] : [];
    if (in_array('住宅ローン減税', $topics, true)) {
        foreach (['新築/中古', '入居予定年', '借入予定額', '年収帯', '省エネ性能', '子育て世帯・若者夫婦世帯該当性', '3,000万円特別控除の利用予定'] as $item) {
            $missing[] = $item;
        }
    }
    if (($memory['intent'] ?? null) === 'purchase') {
        foreach (['購入予定価格', '自己資金', '住宅ローン利用予定', '希望エリア'] as $item) $missing[] = $item;
    }
    if (($memory['intent'] ?? null) === 'sale' || ($memory['intent'] ?? null) === 'relocation') {
        foreach (['所有期間', '購入価格', '売却見込み価格', '住宅ローン残債', '居住用/投資用'] as $item) $missing[] = $item;
    }
    return array_values(array_unique(array_slice(array_filter($missing), 0, 12)));
}

function chatBuildRecentConversationNote($db, $sessionId) {
    if (!$db || $sessionId === '') return null;
    try {
        $stmt = $db->prepare('SELECT role, message FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 8');
        $stmt->execute([$sessionId]);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        $parts = [];
        foreach ($rows as $row) {
            $role = $row['role'] ?? '';
            $message = $row['message'] ?? '';
            if ($role === 'user' && !chatMemoryValueLooksUsable($message)) continue;
            $label = $role === 'user' ? '顧客' : 'AI';
            $snippet = chatMemoryTrim($message, 90);
            if ($snippet !== '') $parts[] = $label . ': ' . $snippet;
        }
        return empty($parts) ? null : chatMemoryTrim(implode(' / ', $parts), 700);
    } catch (Throwable $e) {
        error_log('Chat memory recent note error: ' . $e->getMessage());
        return null;
    }
}

function chatRememberBotSuggestion(&$memory, $botReply) {
    $suggestion = chatMemoryTrim($botReply, 220);
    if ($suggestion === '') return;
    if (!isset($memory['previous_suggestions']) || !is_array($memory['previous_suggestions'])) $memory['previous_suggestions'] = [];
    array_unshift($memory['previous_suggestions'], $suggestion);
    $memory['previous_suggestions'] = array_values(array_unique(array_slice($memory['previous_suggestions'], 0, 3)));
}

function chatComposeMemorySummary($memory) {
    $parts = [];
    if (!empty($memory['intent'])) $parts[] = '相談目的: ' . chatMemoryIntentLabel($memory['intent']);
    if (!empty($memory['property_type'])) $parts[] = '物件種別: ' . $memory['property_type'];
    if (!empty($memory['preferred_area'])) $parts[] = '希望エリア: ' . $memory['preferred_area'];
    if (!empty($memory['budget'])) $parts[] = '予算/価格: ' . $memory['budget'];
    if (!empty($memory['income_range'])) $parts[] = '年収帯: ' . $memory['income_range'];
    if (!empty($memory['family'])) $parts[] = '家族構成: ' . $memory['family'];
    if (!empty($memory['loan_plan'])) $parts[] = 'ローン状況: ' . $memory['loan_plan'];
    if (!empty($memory['child_rearing_household'])) $parts[] = '子育て世帯等: 可能性あり';
    if (!empty($memory['temperature'])) $parts[] = '温度感: ' . $memory['temperature'];
    if (!empty($memory['topics']) && is_array($memory['topics'])) $parts[] = '関心テーマ: ' . implode('、', array_slice($memory['topics'], 0, 8));
    if (!empty($memory['lead_summary'])) $parts[] = '営業向け要約: ' . $memory['lead_summary'];
    if (!empty($memory['next_action'])) $parts[] = '次アクション: ' . $memory['next_action'];
    if (empty($parts)) return null;
    return chatMemoryTrim(implode(' / ', $parts), 900);
}

function chatShouldBuildAISummary($db, $sessionId) {
    if (!$db || $sessionId === '') return false;
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM chat_messages WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $count = (int)$stmt->fetchColumn();
        return $count >= 16 && $count % 12 === 0;
    } catch (Throwable $e) {
        error_log('Chat AI summary count error: ' . $e->getMessage());
        return false;
    }
}

function chatBuildAISessionSummary($db, $sessionId, $memory = []) {
    if (!$db || $sessionId === '' || !function_exists('callOpenAIChat')) return '';
    $model = function_exists('chatOpenAISelectModel') ? chatOpenAISelectModel('summary') : (defined('OPENAI_MODEL_SUMMARY') ? OPENAI_MODEL_SUMMARY : 'gpt-4o-mini');
    $apiKey = function_exists('chatOpenAIApiKeyForModel') ? chatOpenAIApiKeyForModel($model) : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: ''));
    if ($apiKey === '' || $apiKey === 'YOUR_OPENAI_API_KEY_HERE') return '';

    try {
        $stmt = $db->prepare('SELECT role, message FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$sessionId]);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log('Chat AI summary load error: ' . $e->getMessage());
        return '';
    }
    if (count($rows) < 6) return '';

    $transcript = [];
    foreach ($rows as $row) {
        $label = ($row['role'] ?? '') === 'user' ? '顧客' : 'AI';
        $text = chatMemoryTrim($row['message'] ?? '', 360);
        if ($text !== '') $transcript[] = $label . ': ' . $text;
    }
    if (empty($transcript)) return '';

    $existingSummary = is_array($memory) && !empty($memory['last_summary']) ? $memory['last_summary'] : 'なし';
    $messages = [
        ['role' => 'system', 'content' => 'あなたは不動産営業向けCRMの会話メモリー作成担当です。会話ログから、次回接客で使える顧客カルテを日本語で簡潔に作ってください。推測で断定せず、分からない項目は書かないでください。電話番号・メールアドレス・LINE IDなどの連絡先そのものは書かず、連絡先取得済みかどうかだけを書いてください。最大700文字。'],
        ['role' => 'user', 'content' => "既存要約:\n" . $existingSummary . "\n\n直近会話ログ:\n" . implode("\n", $transcript) . "\n\n出力形式:\n相談目的 / 希望条件 / 不安・関心 / 家族・生活条件 / 温度感 / 次に確認するとよいこと"],
    ];
    $result = callOpenAIChat($messages, $apiKey, $model, [
        'db' => $db,
        'session_id' => $sessionId,
        'business_card_id' => function_exists('chatOpenAIGetSessionBusinessCardId') ? chatOpenAIGetSessionBusinessCardId($db, $sessionId) : null,
        'purpose' => 'summary',
        'max_tokens' => 450,
        'temperature' => 0.2,
    ]);
    if (!empty($result['error']) || empty($result['reply'])) {
        if (!empty($result['error'])) error_log('Chat AI summary error: ' . $result['error']);
        return '';
    }
    return chatMemoryTrim($result['reply'], 900);
}

function updateChatSessionMemoryHeuristic($db, $sessionId, $businessCardId, $userMessage, $botReply = '') {
    if (!$db || $sessionId === '') return;
    try {
        ensureChatRagTables($db);
        $memory = getChatSessionMemory($db, $sessionId);
        chatDetectMemoryFromMessage($memory, $userMessage);
        chatApplyLeadDataToMemory($memory, chatLoadLeadDataForMemory($db, $sessionId));
        chatRememberBotSuggestion($memory, $botReply);
        $memory['recent_context'] = chatBuildRecentConversationNote($db, $sessionId);
        $memory['missing_info'] = chatBuildMissingInfo($memory);
        $aiSummary = chatShouldBuildAISummary($db, $sessionId) ? chatBuildAISessionSummary($db, $sessionId, $memory) : '';
        $memory['last_summary'] = $aiSummary !== '' ? $aiSummary : chatComposeMemorySummary($memory);
        $memory['last_updated_at'] = date('c');
        $json = json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT INTO chat_session_memory (session_id, business_card_id, memory_json, last_summary)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE business_card_id = VALUES(business_card_id), memory_json = VALUES(memory_json), last_summary = VALUES(last_summary), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$sessionId, $businessCardId, $json, $memory['last_summary']]);
    } catch (Throwable $e) {
        error_log('Chat memory update error: ' . $e->getMessage());
    }
}

function chatSaveSessionMemorySnapshot($db, $sessionId, $businessCardId, $memory) {
    if (!$db || $sessionId === '' || !is_array($memory)) return;
    try {
        ensureChatRagTables($db);
        $memory['last_updated_at'] = date('c');
        $json = json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT INTO chat_session_memory (session_id, business_card_id, memory_json, last_summary)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE business_card_id = VALUES(business_card_id), memory_json = VALUES(memory_json), last_summary = VALUES(last_summary), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$sessionId, $businessCardId, $json, $memory['last_summary'] ?? null]);
    } catch (Throwable $e) {
        error_log('Chat memory snapshot save error: ' . $e->getMessage());
    }
}

function chatMemoryHasContinuity($memory) {
    if (!$memory || !is_array($memory)) return false;
    foreach (['last_summary', 'intent', 'property_type', 'budget', 'family', 'preferred_area', 'loan_plan', 'recent_context', 'lead_summary'] as $key) {
        if (!empty($memory[$key])) return true;
    }
    return false;
}

function chatBuildResumeMessage($memory, $agentName = '担当者') {
    $lines = [];
    if (!empty($memory['intent'])) $lines[] = chatMemoryCustomerIntentLabel($memory['intent']);
    if (!empty($memory['preferred_area'])) $lines[] = $memory['preferred_area'] . 'を中心に検討';
    if (!empty($memory['property_type'])) $lines[] = $memory['property_type'] . 'をご希望';
    if (!empty($memory['budget'])) $lines[] = '予算感を整理中';
    if (!empty($memory['family'])) $lines[] = '暮らし方に合う条件を整理中';
    if (!empty($memory['loan_plan'])) $lines[] = '資金面も確認中';
    if (empty($lines) && !empty($memory['last_summary'])) $lines[] = '前回のご相談内容を引き継ぎ済み';

    $message = "おかえりなさい。
前回ご相談いただいた内容をもとに、続きからご案内できます。";
    if (!empty($lines)) {
        $message .= "

現在把握している内容
・" . implode("
・", array_slice(array_values(array_unique($lines)), 0, 5));
    }
    $message .= "

まだ条件が固まっていなくても大丈夫です。「何から決めればいいかわからない」という段階の方も多いので、整理しながら一緒に考えていきましょう。

この続きからでも、新しいご相談でも大丈夫です。気になることをそのまま自由にご質問ください。";
    return $message;
}
function getChatResumeMessageForSession($db, $sessionId, $agentName = '担当者', $businessCardId = null, $forceAiSummary = false) {
    if (!$db || $sessionId === '') return '';
    $memory = getChatSessionMemory($db, $sessionId);
    if (!chatMemoryHasContinuity($memory)) {
        $leadData = chatLoadLeadDataForMemory($db, $sessionId);
        if (!empty($leadData)) {
            chatApplyLeadDataToMemory($memory, $leadData);
            $memory['last_summary'] = chatComposeMemorySummary($memory);
        }
    }
    $memory['recent_context'] = chatBuildRecentConversationNote($db, $sessionId) ?: ($memory['recent_context'] ?? null);
    if ($forceAiSummary) {
        $aiSummary = chatBuildAISessionSummary($db, $sessionId, $memory);
        if ($aiSummary !== '') {
            $memory['last_summary'] = $aiSummary;
        } elseif (empty($memory['last_summary'])) {
            $memory['last_summary'] = chatComposeMemorySummary($memory);
        }
        if ($businessCardId !== null) {
            chatSaveSessionMemorySnapshot($db, $sessionId, $businessCardId, $memory);
        }
    }
    return chatMemoryHasContinuity($memory) ? chatBuildResumeMessage($memory, $agentName) : '';
}

/**
 * 顧客向けに渡すテキストから、営業内部メモである「温度感／検討温度感」を必ず除去する。
 * 温度感は営業（担当者）側CRMだけで扱う値で、顧客チャットのAI文脈に混ぜると
 * AIがそのまま要約として顧客へ復唱してしまうため、顧客側へ流れる経路では落とす。
 * 例: 「相談目的: 購入 / 温度感: high / 希望エリア: 中野区」→「相談目的: 購入 / 希望エリア: 中野区」
 */
function chatStripCustomerInternalNotes($text) {
    if (!is_string($text) || $text === '') return $text;
    // 「(検討)温度感: ...」を、次の区切り（ / 、改行、行末）まで除去。
    $text = preg_replace('/(?:検討)?温度感\s*[:：][^\/\n\r]*/u', '', $text);
    // 区切りだけ残った「温度感」見出し（値なし）も除去。
    $text = preg_replace('/(?:検討)?温度感(?=\s*(?:\/|$|[\n\r]))/u', '', $text);
    // 除去で生じた連続スラッシュ・先頭/末尾のスラッシュや余分な空白を整理。
    $text = preg_replace('/\s*\/\s*(?:\/\s*)+/u', ' / ', $text);
    $text = preg_replace('/^\s*\/\s*|\s*\/\s*$/u', '', $text);
    $text = preg_replace('/[ \t]{2,}/u', ' ', $text);
    return trim($text);
}

function buildChatMemoryContext($memory) {
    if (!$memory || !is_array($memory)) return '';
    $lines = [];
    if (!empty($memory['last_summary'])) {
        $cleanSummary = chatStripCustomerInternalNotes((string)$memory['last_summary']);
        if ($cleanSummary !== '') $lines[] = '前回までの要約: ' . $cleanSummary;
    }
    foreach (['intent' => '相談目的', 'property_type' => '物件種別', 'budget' => '予算/価格', 'income_range' => '年収帯', 'family' => '家族構成', 'preferred_area' => '希望エリア', 'loan_plan' => 'ローン利用'] as $key => $label) {
        if (empty($memory[$key])) continue;
        $value = $key === 'intent' ? chatMemoryIntentLabel($memory[$key]) : $memory[$key];
        $lines[] = $label . ': ' . $value;
    }
    if (!empty($memory['child_rearing_household'])) $lines[] = '子育て世帯・若者夫婦世帯: 該当する可能性あり';
    if (!empty($memory['topics']) && is_array($memory['topics'])) $lines[] = '関心テーマ: ' . implode('、', array_slice($memory['topics'], 0, 8));
    if (!empty($memory['previous_suggestions']) && is_array($memory['previous_suggestions'])) $lines[] = '過去にAIが案内した内容: ' . implode(' / ', array_slice($memory['previous_suggestions'], 0, 2));
    if (!empty($memory['recent_context'])) $lines[] = '直近の会話要点: ' . chatStripCustomerInternalNotes((string)$memory['recent_context']);
    if (!empty($memory['missing_info']) && is_array($memory['missing_info'])) $lines[] = '未確認事項: ' . implode('、', array_slice($memory['missing_info'], 0, 10));
    if (empty($lines)) return '';
    return "【会話メモリー】\n" . implode("\n", $lines);
}

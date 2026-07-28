<?php
/**
 * 社内FAQ（不動産AI名刺 RAG統合マスター / RAG_FAQシート）の検索・プロンプト生成。
 *
 * Excelの「エンジニア実装仕様」シートの指定にそのまま対応している:
 *   1行＝1FAQドキュメント             -> chat_faq_items の1レコード（分割しない）
 *   本文フィールド=RAG投入テキスト     -> rag_text をそのまま本文として渡す
 *   検索対象=質問／言い換え／キーワード／回答（＋README推奨の小カテゴリ）
 *   優先検索=優先度「最重要」を上位へ  -> chatFaqScoreRow() で加点＋priority_rankで整列
 *   重複防止=FAQ_IDでupsert／別名FAQ_IDは同一FAQ扱い
 *   回答生成=回答本文と注意事項・禁止表現を同時にLLMへ渡す
 *   検索不一致=閾値未満なら推測回答をせず追加質問／担当エージェント確認へ誘導
 *   推奨返却件数=上位3〜5件、同じFAQ_IDの重複結果は除外
 *   ログ=利用者質問、採用FAQ_ID、類似度、最終回答、担当者確認有無を保存
 *
 * 検索はローカルRAG（chat-rag-helper.php）と同じ語句一致＋スコアリング方式。
 * 埋め込みベクトルは使っていないため、語句抽出は chatExtractSearchTerms() と
 * chatRagTokenizeMessage() を共用する。
 */

function ensureChatFaqTables($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS chat_faq_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faq_id VARCHAR(64) NOT NULL,
        category_major VARCHAR(80) NOT NULL DEFAULT '',
        category_minor VARCHAR(120) NOT NULL DEFAULT '',
        question VARCHAR(500) NOT NULL,
        question_aliases TEXT NULL,
        answer MEDIUMTEXT NOT NULL,
        caution TEXT NULL,
        reference_name VARCHAR(255) NULL,
        reference_url TEXT NULL,
        confidence VARCHAR(20) NOT NULL DEFAULT '中',
        update_rule VARCHAR(255) NULL,
        keywords TEXT NULL,
        rag_text MEDIUMTEXT NOT NULL,
        alias_faq_id VARCHAR(64) NULL,
        alias_conflict TINYINT(1) NOT NULL DEFAULT 0,
        priority VARCHAR(20) NOT NULL DEFAULT '通常',
        priority_rank TINYINT NOT NULL DEFAULT 50,
        adopted_from VARCHAR(80) NULL,
        adoption_status VARCHAR(20) NOT NULL DEFAULT '採用',
        review_status VARCHAR(20) NOT NULL DEFAULT 'approved',
        review_note VARCHAR(500) NULL,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        source_file VARCHAR(255) NOT NULL DEFAULT '',
        updated_on DATE NULL,
        content_hash CHAR(64) NOT NULL,
        last_seen_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_chat_faq_id (faq_id),
        INDEX idx_chat_faq_lookup (enabled, review_status, priority_rank),
        INDEX idx_chat_faq_alias (alias_faq_id),
        INDEX idx_chat_faq_review (review_status, confidence),
        INDEX idx_chat_faq_source (source_file, last_seen_at),
        INDEX idx_chat_faq_update_rule (confidence, updated_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS chat_faq_retrieval_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        session_id CHAR(36) NULL,
        business_card_id INT NULL,
        user_message TEXT NOT NULL,
        matched_faq_ids VARCHAR(500) NULL,
        top_faq_id VARCHAR(64) NULL,
        top_score INT NOT NULL DEFAULT 0,
        score_threshold INT NOT NULL DEFAULT 0,
        matched TINYINT(1) NOT NULL DEFAULT 0,
        agent_review_required TINYINT(1) NOT NULL DEFAULT 0,
        final_reply MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_faq_logs_session (session_id, id),
        INDEX idx_chat_faq_logs_top (top_faq_id, created_at),
        INDEX idx_chat_faq_logs_unmatched (matched, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * 「検索不一致」判定のスコア閾値。これ未満のFAQは採用せず、推測回答もさせない。
 */
function chatFaqScoreThreshold() {
    return defined('CHAT_FAQ_SCORE_THRESHOLD') ? (int) CHAT_FAQ_SCORE_THRESHOLD : 24;
}

function chatFaqPriorityRank($priority) {
    return trim((string)$priority) === '最重要' ? 10 : 50;
}

/**
 * 質問文とFAQ文の表記ゆれを吸収する。空白と句読点・記号だけを落とし、
 * 長音符などの表音要素は残す（「ローン」→「ロン」のような誤一致を避けるため）。
 */
function chatFaqNormalize($text) {
    $text = (string)$text;
    if ($text === '') return '';
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[\s　]+/u', '', $text);
    $text = preg_replace('/[。、．，・？?！!「」『』（）()\[\]【】：:；;\/／]+/u', '', $text);
    return (string)$text;
}

function chatFaqSearchTerms($message) {
    $terms = function_exists('chatExtractSearchTerms') ? chatExtractSearchTerms($message) : [];
    if (function_exists('chatRagTokenizeMessage')) {
        foreach (chatRagTokenizeMessage($message) as $token) {
            $terms[] = $token;
        }
    }
    $terms = array_map('trim', $terms);
    $terms = array_filter($terms, function ($term) {
        return $term !== '' && mb_strlen($term) >= 2;
    });
    return array_values(array_unique($terms));
}

/**
 * 語句一致のスコアリング。ベクトル類似度の代わりに使う「類似度」。
 * 質問文そのものが一致した場合を最大とし、優先度「最重要」と信頼度で調整する。
 */
function chatFaqScoreRow($row, $terms, $message) {
    $score = 0;

    $question = (string)($row['question'] ?? '');
    $aliases = (string)($row['question_aliases'] ?? '');
    $keywords = (string)($row['keywords'] ?? '');
    $answer = (string)($row['answer'] ?? '');
    $minor = (string)($row['category_minor'] ?? '');

    // 質問文（または言い換え）と利用者の文がほぼ同一なら、他の加点を圧倒させる。
    $normMessage = chatFaqNormalize($message);
    if ($normMessage !== '') {
        $candidates = array_merge([$question], preg_split('/\r\n|\r|\n/u', $aliases) ?: []);
        foreach ($candidates as $candidate) {
            $normCandidate = chatFaqNormalize($candidate);
            if ($normCandidate === '' || mb_strlen($normCandidate) < 4) continue;
            if ($normCandidate === $normMessage) { $score += 80; break; }
            if (mb_strpos($normMessage, $normCandidate) !== false || mb_strpos($normCandidate, $normMessage) !== false) {
                $score += 55;
                break;
            }
        }
    }

    $fields = [
        [$question, 20],
        [$aliases, 16],
        [$keywords, 12],
        [$minor, 8],
        [$answer, 6],
    ];
    foreach ($terms as $term) {
        $needle = mb_strtolower($term, 'UTF-8');
        if ($needle === '') continue;
        foreach ($fields as $field) {
            if ($field[0] === '') continue;
            if (mb_stripos($field[0], $needle) !== false) $score += $field[1];
        }
    }

    // 優先検索: 優先度＝最重要のFAQは通常FAQより上位へ。
    if (trim((string)($row['priority'] ?? '')) === '最重要') $score += 12;

    switch (trim((string)($row['confidence'] ?? ''))) {
        case '最高': $score += 8; break;
        case '高':   $score += 5; break;
        case '中':   $score += 1; break;
        case '要確認': $score -= 20; break;
    }

    return $score;
}

function chatFaqEmptyResult($message = '') {
    return [
        'context' => '',
        'sources' => [],
        'items' => [],
        'matched' => false,
        'below_threshold' => false,
        'top_score' => 0,
        'top_faq_id' => null,
        'threshold' => chatFaqScoreThreshold(),
        'agent_review_required' => false,
    ];
}

/**
 * 利用者の質問に対応する社内FAQを取得し、プロンプト用テキストを組み立てる。
 *
 * @param int $limit 返却件数（実装仕様シートの推奨に合わせ3〜5件に丸める）
 */
function getChatFaqContextForChat($db, $message, $limit = 5) {
    $empty = chatFaqEmptyResult($message);
    if (!$db instanceof PDO) return $empty;
    $message = trim((string)$message);
    if ($message === '') return $empty;

    $limit = max(3, min(5, (int)$limit));
    $threshold = chatFaqScoreThreshold();

    try {
        ensureChatFaqTables($db);
        $terms = chatFaqSearchTerms($message);
        if (empty($terms)) return $empty;

        // 検索対象フィールド: 質問／質問の言い換え／検索用キーワード／回答（＋小カテゴリ）
        $likes = [];
        $params = [];
        foreach (array_slice($terms, 0, 8) as $term) {
            $likes[] = '(question LIKE ? OR question_aliases LIKE ? OR keywords LIKE ? OR category_minor LIKE ? OR answer LIKE ?)';
            $like = '%' . $term . '%';
            for ($i = 0; $i < 5; $i++) $params[] = $like;
        }

        $sql = "SELECT faq_id, category_major, category_minor, question, question_aliases, answer, caution,
                       reference_name, reference_url, confidence, update_rule, keywords, rag_text,
                       alias_faq_id, alias_conflict, priority, priority_rank, updated_on
                FROM chat_faq_items
                WHERE enabled = 1 AND review_status = 'approved'
                  AND (" . implode(' OR ', $likes) . ")
                ORDER BY priority_rank ASC, id ASC
                LIMIT 200";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return $empty;

        foreach ($rows as &$row) {
            $row['_score'] = chatFaqScoreRow($row, $terms, $message);
        }
        unset($row);
        usort($rows, function ($a, $b) {
            if ($a['_score'] === $b['_score']) {
                return ((int)$a['priority_rank']) <=> ((int)$b['priority_rank']);
            }
            return $b['_score'] <=> $a['_score'];
        });

        $topScore = (int)($rows[0]['_score'] ?? 0);
        $topFaqId = (string)($rows[0]['faq_id'] ?? '');

        // 閾値未満: 推測回答をさせず、追加質問または担当エージェント確認へ誘導する。
        $selected = array_values(array_filter($rows, function ($row) use ($threshold) {
            return (int)$row['_score'] >= $threshold;
        }));
        if (empty($selected)) {
            $result = $empty;
            $result['below_threshold'] = true;
            $result['top_score'] = $topScore;
            $result['top_faq_id'] = $topFaqId;
            $result['agent_review_required'] = true;
            return $result;
        }

        // 同じFAQ_IDの重複結果は除外。別名FAQ_IDも同一FAQとして扱うが、
        // 別名が「別の実在FAQ_ID」と衝突している行（alias_conflict=1）は
        // 無関係なFAQを誤って落とすため、この判定に使わない。
        $seen = [];
        $picked = [];
        foreach ($selected as $row) {
            $keys = [(string)$row['faq_id']];
            $alias = trim((string)($row['alias_faq_id'] ?? ''));
            if ($alias !== '' && (int)($row['alias_conflict'] ?? 0) === 0) $keys[] = $alias;

            $duplicate = false;
            foreach ($keys as $key) {
                if (isset($seen[$key])) { $duplicate = true; break; }
            }
            if ($duplicate) continue;
            foreach ($keys as $key) $seen[$key] = true;
            $picked[] = $row;
            if (count($picked) >= $limit) break;
        }

        $parts = [];
        $sources = [];
        $items = [];
        $reviewRequired = false;
        foreach ($picked as $idx => $row) {
            $meta = [
                'FAQ_ID: ' . $row['faq_id'],
                'カテゴリ: ' . trim($row['category_major'] . ($row['category_minor'] !== '' ? ' > ' . $row['category_minor'] : '')),
                '優先度: ' . $row['priority'],
                '信頼度: ' . $row['confidence'],
            ];
            // 本文は「RAG投入テキスト」列をそのまま使う（回答＋注意事項・禁止表現を含む）。
            $body = trim((string)$row['rag_text']);
            if ($body === '') {
                $body = '質問: ' . $row['question'] . "\n回答: " . $row['answer'];
                if (trim((string)$row['caution']) !== '') {
                    $body .= "\n回答時の注意事項・禁止表現: " . $row['caution'];
                }
            }
            $parts[] = '[' . ($idx + 1) . '] ' . implode(' / ', $meta) . "\n" . $body;

            if (trim((string)($row['reference_url'] ?? '')) !== '') {
                $sources[] = [
                    'url' => $row['reference_url'],
                    'title' => trim((string)($row['reference_name'] ?? '')) !== '' ? $row['reference_name'] : ('社内FAQ: ' . $row['question']),
                    'type' => 'faq_master',
                    'last_fetched_at' => $row['updated_on'] ?? null,
                ];
            }
            $items[] = [
                'faq_id' => $row['faq_id'],
                'question' => $row['question'],
                'score' => (int)$row['_score'],
                'confidence' => $row['confidence'],
                'priority' => $row['priority'],
            ];
            if (trim((string)$row['confidence']) === '要確認') $reviewRequired = true;
        }

        if (empty($parts)) return $empty;

        return [
            'context' => "【社内FAQ（正式回答・最優先）】\n" . implode("\n\n", $parts),
            'sources' => $sources,
            'items' => $items,
            'matched' => true,
            'below_threshold' => false,
            'top_score' => $topScore,
            'top_faq_id' => $topFaqId,
            'threshold' => $threshold,
            'agent_review_required' => $reviewRequired,
        ];
    } catch (Throwable $e) {
        error_log('Chat FAQ context error: ' . $e->getMessage());
        return $empty;
    }
}

/**
 * 社内FAQブロックをシステムプロンプトへ差し込む形に整える。
 * 「回答本文と注意事項・禁止表現を同時にLLMへ渡す」ため、注意事項を無視しないよう明示する。
 */
function buildChatFaqPromptBlock($faq) {
    if (!is_array($faq)) return '';

    if (!empty($faq['matched']) && $faq['context'] !== '') {
        return "\n\n# 社内FAQ（会社の正式回答・一般知識より優先）\n"
            . "以下は、この会社がAI回答用に整備した正式FAQです。質問に該当する項目がある場合は、あなたの一般知識より優先し、記載の回答内容に沿って答えてください。\n"
            . "- 各FAQの「回答時の注意事項・禁止表現」は必ず守ってください。禁止された断定表現（『必ず』『絶対』『誰でも』等）は使わないでください。\n"
            . "- FAQの文章をそのまま読み上げるのではなく、お客様の質問に合わせて自然な会話文へ整えて回答してください。内容・結論は変えないでください。\n"
            . "- 信頼度が「要確認」の項目は、断定せず、私が最新情報を確認してご案内する旨を添えてください。\n"
            . "- FAQに無い部分は、一般的な不動産実務知識で補ってください。\n\n"
            . $faq['context'];
    }

    // 閾値未満（＝関連しそうだが十分に一致するFAQが無い）の場合の指示。
    if (!empty($faq['below_threshold'])) {
        return "\n\n# 社内FAQ 検索結果\n"
            . "この質問に十分に一致する社内FAQは見つかりませんでした。制度・税制・金利・費用・手続きなど事実確認が必要な内容については、推測で断定しないでください。\n"
            . "まずは判断に必要な条件を1つだけ質問して確認するか、「私が最新の内容を確認のうえご案内いたします」と第一人称で受けてください。\n";
    }

    return '';
}

/**
 * 実装仕様シートの「ログ」項目。利用者質問／採用FAQ_ID／類似度／最終回答／担当者確認有無を残す。
 * ログ失敗でチャット本体を落とさないよう、例外は握りつぶす。
 */
function chatLogFaqRetrieval($db, $sessionId, $businessCardId, $userMessage, $faq, $finalReply = null) {
    if (!$db instanceof PDO || !is_array($faq)) return;
    // 候補すら出なかった質問（FAQ領域外の雑談等）はログしない。
    if (empty($faq['matched']) && empty($faq['below_threshold'])) return;

    try {
        ensureChatFaqTables($db);
        $faqIds = [];
        foreach (($faq['items'] ?? []) as $item) {
            if (!empty($item['faq_id'])) $faqIds[] = $item['faq_id'];
        }
        $stmt = $db->prepare("INSERT INTO chat_faq_retrieval_logs
            (session_id, business_card_id, user_message, matched_faq_ids, top_faq_id, top_score, score_threshold, matched, agent_review_required, final_reply)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $sessionId !== '' ? $sessionId : null,
            $businessCardId ? (int)$businessCardId : null,
            mb_substr((string)$userMessage, 0, 2000),
            mb_substr(implode(',', $faqIds), 0, 500),
            $faq['top_faq_id'] ?? null,
            (int)($faq['top_score'] ?? 0),
            (int)($faq['threshold'] ?? chatFaqScoreThreshold()),
            !empty($faq['matched']) ? 1 : 0,
            !empty($faq['agent_review_required']) ? 1 : 0,
            $finalReply !== null ? mb_substr((string)$finalReply, 0, 8000) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Chat FAQ retrieval log error: ' . $e->getMessage());
    }
}

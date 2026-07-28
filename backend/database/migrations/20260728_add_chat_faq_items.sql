-- 不動産AI名刺 RAG統合マスター（Excel: RAG_FAQシート）を投入するFAQストア。
-- Excelの「エンジニア実装仕様」シートに従う:
--   データ単位   : 1行＝1FAQドキュメント（分割せず1チャンクとして保持）
--   本文フィールド: RAG投入テキスト（rag_text）
--   ドキュメントID: FAQ_ID（faq_id / 一意キーでupsert）
--   検索対象     : 質問／質問の言い換え／小カテゴリ／検索用キーワード／回答
--   メタデータ   : 大カテゴリ、小カテゴリ、優先度、信頼度、更新基準、参照URL、採用元、更新日
CREATE TABLE IF NOT EXISTS chat_faq_items (
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
    -- 別名FAQ_IDが「別の実在FAQ_ID」と衝突している行の目印。
    -- 衝突している別名は同一FAQ判定に使わない（無関係なFAQを取り違えるため）。
    alias_conflict TINYINT(1) NOT NULL DEFAULT 0,
    priority VARCHAR(20) NOT NULL DEFAULT '通常',
    -- 優先度＝最重要を通常より上位に出すための並べ替え用（小さいほど上位）。
    priority_rank TINYINT NOT NULL DEFAULT 50,
    adopted_from VARCHAR(80) NULL,
    adoption_status VARCHAR(20) NOT NULL DEFAULT '採用',
    -- 信頼度＝要確認の行は本番投入前レビュー対象。approved になるまで検索に出さない。
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 「ログ: 利用者質問、採用FAQ_ID、類似度、最終回答、担当者確認有無を保存」（実装仕様シート）
CREATE TABLE IF NOT EXISTS chat_faq_retrieval_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

<?php
/**
 * chat_faq_items の運用補助スクリプト。
 *
 * Excelの実装仕様シートのうち、投入後の運用にあたる項目を担当する:
 *   要確認行 : 信頼度＝要確認は本番投入前にレビュー
 *   制度情報 : 信頼度・更新基準・参照URLを使い、定期的に更新対象を抽出
 *   ログ     : 採用FAQ_ID／類似度／担当者確認有無から、未カバーの質問を洗い出す
 *
 * 使い方:
 *   php backend/scripts/faq_review_report.php                     # 要確認（pending）一覧
 *   php backend/scripts/faq_review_report.php --approve=CRI014,CRI021
 *   php backend/scripts/faq_review_report.php --approve-all
 *   php backend/scripts/faq_review_report.php --alias-conflicts   # 別名FAQ_IDの衝突一覧
 *   php backend/scripts/faq_review_report.php --update-targets    # 制度更新の確認対象
 *   php backend/scripts/faq_review_report.php --update-targets --days=90
 *   php backend/scripts/faq_review_report.php --unmatched         # FAQで拾えなかった質問
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/chat-faq-helper.php';

function faqOption($args, $name, $default = null) {
    foreach ($args as $arg) {
        if ($arg === '--' . $name) return true;
        if (strpos($arg, '--' . $name . '=') === 0) return substr($arg, strlen($name) + 3);
    }
    return $default;
}

function faqTrimLine($text, $max = 90) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
}

$args = array_slice($argv, 1);

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    exit('Error: database connection failed: ' . $e->getMessage() . "\n");
}
ensureChatFaqTables($db);

// --- 承認 ---------------------------------------------------------------
$approve = faqOption($args, 'approve', null);
$approveAll = faqOption($args, 'approve-all', false) !== false;
if ($approveAll || (is_string($approve) && $approve !== '')) {
    if ($approveAll) {
        $stmt = $db->prepare("UPDATE chat_faq_items
            SET review_status = 'approved', enabled = 1, reviewed_at = CURRENT_TIMESTAMP
            WHERE review_status = 'pending'");
        $stmt->execute();
    } else {
        $ids = array_values(array_filter(array_map('trim', explode(',', $approve))));
        if (empty($ids)) exit("Error: --approve needs at least one FAQ_ID.\n");
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE chat_faq_items
            SET review_status = 'approved', enabled = 1, reviewed_at = CURRENT_TIMESTAMP
            WHERE faq_id IN ({$in})");
        $stmt->execute($ids);
    }
    echo "approved rows: " . $stmt->rowCount() . "\n";
    exit(0);
}

// --- 別名FAQ_IDの衝突 ---------------------------------------------------
if (faqOption($args, 'alias-conflicts', false) !== false) {
    $rows = $db->query("SELECT a.faq_id, a.question, a.alias_faq_id, b.question AS alias_question
        FROM chat_faq_items a
        LEFT JOIN chat_faq_items b ON b.faq_id = a.alias_faq_id
        WHERE a.alias_conflict = 1
        ORDER BY a.faq_id")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== 別名FAQ_IDが別の実在FAQ_IDと衝突している行 (" . count($rows) . ") ===\n";
    echo "この別名は同一FAQ判定に使いません（無関係なFAQを取り違えるため）。\n\n";
    foreach ($rows as $r) {
        echo "{$r['faq_id']}  " . faqTrimLine($r['question'], 46) . "\n";
        echo "  別名 {$r['alias_faq_id']} は別FAQ: " . faqTrimLine($r['alias_question'] ?? '(未登録)', 46) . "\n";
    }
    exit(0);
}

// --- 制度更新の確認対象 -------------------------------------------------
if (faqOption($args, 'update-targets', false) !== false) {
    $days = (int)faqOption($args, 'days', 180);
    if ($days < 1) $days = 180;
    $stmt = $db->prepare("SELECT faq_id, category_major, question, confidence, update_rule, reference_url, updated_on
        FROM chat_faq_items
        WHERE enabled = 1
          AND (updated_on IS NULL OR updated_on < DATE_SUB(CURRENT_DATE, INTERVAL ? DAY))
        ORDER BY FIELD(confidence, '要確認', '中', '高', '最高'), updated_on ASC, faq_id ASC");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "=== {$days}日以上更新されていないFAQ (" . count($rows) . ") ===\n";
    echo "更新基準と参照URLを確認し、FAQ_ID単位で差し替えてください。\n\n";
    foreach ($rows as $r) {
        echo "{$r['faq_id']} [{$r['category_major']}/信頼度{$r['confidence']}/更新日" . ($r['updated_on'] ?: '未設定') . "]\n";
        echo "  Q: " . faqTrimLine($r['question'], 70) . "\n";
        if (trim((string)$r['update_rule']) !== '') echo "  更新基準: " . faqTrimLine($r['update_rule'], 70) . "\n";
        if (trim((string)$r['reference_url']) !== '') echo "  参照URL : " . $r['reference_url'] . "\n";
    }
    exit(0);
}

// --- FAQで拾えなかった質問 ----------------------------------------------
if (faqOption($args, 'unmatched', false) !== false) {
    $days = (int)faqOption($args, 'days', 30);
    if ($days < 1) $days = 30;
    $stmt = $db->prepare("SELECT user_message, top_faq_id, top_score, score_threshold, created_at
        FROM chat_faq_retrieval_logs
        WHERE matched = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY id DESC
        LIMIT 100");
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "=== 直近{$days}日でFAQ閾値に届かなかった質問 (" . count($rows) . "件 / 最大100) ===\n";
    echo "FAQの追加・言い換え追記の候補です。\n\n";
    foreach ($rows as $r) {
        echo "[{$r['created_at']}] score {$r['top_score']}/{$r['score_threshold']} (最上位: " . ($r['top_faq_id'] ?: '-') . ")\n";
        echo "  " . faqTrimLine($r['user_message'], 100) . "\n";
    }
    exit(0);
}

// --- 既定: 要確認（pending）一覧 ----------------------------------------
$rows = $db->query("SELECT faq_id, category_major, question, answer, caution, priority, confidence, adopted_from
    FROM chat_faq_items
    WHERE review_status = 'pending'
    ORDER BY faq_id")->fetchAll(PDO::FETCH_ASSOC);

$total = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items")->fetchColumn();
$active = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items WHERE enabled = 1 AND review_status = 'approved'")->fetchColumn();

echo "=== chat_faq_items ===\n";
echo "total      : {$total}\n";
echo "searchable : {$active}\n";
echo "pending    : " . count($rows) . "\n\n";

if (empty($rows)) {
    echo "レビュー待ちの行はありません。\n";
    exit(0);
}

echo "=== レビュー待ち（信頼度=要確認 / 検索対象外） ===\n";
echo "内容を確認し、問題なければ承認してください:\n";
echo "  php backend/scripts/faq_review_report.php --approve=FAQ_ID1,FAQ_ID2\n\n";
foreach ($rows as $r) {
    echo "{$r['faq_id']} [{$r['category_major']}/優先度{$r['priority']}/採用元{$r['adopted_from']}]\n";
    echo "  Q: " . faqTrimLine($r['question'], 80) . "\n";
    echo "  A: " . faqTrimLine($r['answer'], 120) . "\n";
    if (trim((string)$r['caution']) !== '') echo "  注意: " . faqTrimLine($r['caution'], 80) . "\n";
    echo "\n";
}

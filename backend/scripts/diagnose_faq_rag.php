<?php
/**
 * Read-only diagnostic for 社内FAQ RAG (chat_faq_items).
 * 何も変更しない。「投入したはずなのに空」の原因を切り分けるためのスクリプト。
 *
 * 確認する順番:
 *   [1] xlsxを正しく読めているか（DB不要）
 *   [2] このスクリプトが実際に接続するDB（migrationを流したDBと同じか）
 *   [3] テーブルの有無と件数
 *   [4] 実際の検索が通るか
 *
 * Usage:
 *   php backend/scripts/diagnose_faq_rag.php
 *   php backend/scripts/diagnose_faq_rag.php "住宅ローン控除とは何ですか？"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-rag-helper.php';
require_once __DIR__ . '/../includes/chat-faq-helper.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';

$message = $argv[1] ?? '住宅ローン控除とは何ですか？';

function line($s = '') { echo $s . "\n"; }

line('==================================================================');
line('FAQ RAG (chat_faq_items) DIAGNOSTIC');
line('==================================================================');

// [1] xlsx側のパース（DB不要） -----------------------------------------------
line('');
line('[1] xlsx parse check (no DB involved)');
$candidates = glob(dirname(__DIR__, 2) . '/assets/*RAG統合マスター*.xlsx') ?: [];
rsort($candidates);
if (empty($candidates)) {
    line('    >>> assets/ に RAG統合マスター の xlsx がありません。');
    line('        サーバーへファイルがデプロイされているか確認してください。');
} else {
    $path = $candidates[0];
    line('    file  : ' . $path);
    line('    size  : ' . number_format((int)filesize($path)) . ' bytes');
    try {
        list($rows, $sheetNames) = xlsxReadSheet($path, 'RAG_FAQ');
        line('    sheets: ' . implode(' / ', $sheetNames));
        line('    rows  : ' . count($rows) . ' (1行目=ヘッダー)');
        if (count($rows) >= 2) {
            $header = $rows[0];
            $nonEmptyHeaders = array_filter($header, function ($v) { return trim((string)$v) !== ''; });
            line('    header cells with text: ' . count($nonEmptyHeaders));
            if (count($nonEmptyHeaders) === 0) {
                line('    >>> ヘッダーが空。sharedStrings の読み取りに失敗しています。');
            } else {
                line('    header: ' . implode(' | ', array_slice(array_values($nonEmptyHeaders), 0, 8)) . ' ...');
                $faqIdLetter = null;
                foreach ($header as $letter => $label) {
                    if (trim((string)$label) === 'FAQ_ID') { $faqIdLetter = $letter; break; }
                }
                if ($faqIdLetter === null) {
                    line('    >>> FAQ_ID 列が見つかりません。シートのヘッダー行を確認してください。');
                } else {
                    $withId = 0;
                    foreach (array_slice($rows, 1) as $row) {
                        if (trim((string)($row[$faqIdLetter] ?? '')) !== '') $withId++;
                    }
                    line('    data rows with FAQ_ID: ' . $withId . ' (期待値: 352)');
                    line($withId > 0
                        ? '    >>> パースOK。import_faq_excel.php はこのファイルを読み込めます。'
                        : '    >>> FAQ_ID が全行空。sharedStrings の読み取りに失敗しています。');
                }
            }
        }
    } catch (Throwable $e) {
        line('    >>> PARSE FAILED: ' . $e->getMessage());
    }
}

// [2] どのconfig / どのDBへ繋いでいるか ---------------------------------------
line('');
line('[2] connection target');
$env = getenv('APP_ENV') ?: '(unset)';
$configName = (getenv('APP_ENV') === 'staging') ? 'config.staging.php' : 'config.production.php';
line('    APP_ENV     : ' . $env);
line('    config file : ' . $configName);
// Database::loadConfig() と同じ探索順（リポジトリの1つ上 → backend/config）。
foreach ([dirname(__DIR__, 3) . '/' . $configName, __DIR__ . '/../config/' . $configName] as $candidate) {
    line('      ' . (is_file($candidate) ? '[FOUND] ' : '[     ] ') . $candidate);
}

$db = null;
try {
    $db = (new Database())->getConnection();
} catch (Throwable $e) {
    line('    >>> DB CONNECTION FAILED: ' . $e->getMessage());
    line('');
    line('Done. (this script changed nothing)');
    exit(1);
}
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
line('    connected DB: ' . $dbName);
line('    >>> migration を流したDB名と一致しているか確認してください。');
line('        違う場合、投入は成功していてもそちらのDBには入りません。');

// [3] テーブルと件数 ---------------------------------------------------------
line('');
line('[3] tables in ' . $dbName);
$itemCount = 0;
foreach (['chat_faq_items', 'chat_faq_retrieval_logs'] as $table) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $stmt->execute([$dbName, $table]);
    $exists = (int)$stmt->fetchColumn() > 0;
    $count = $exists ? (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() : 0;
    if ($table === 'chat_faq_items') $itemCount = $count;
    line(sprintf('    %-26s exists=%s rows=%d', $table, $exists ? 'yes' : 'NO ', $count));
}
line('    ※ chat_faq_retrieval_logs は顧客がチャットを送ると増えます。投入では増えません。');

if ($itemCount > 0) {
    $active = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items WHERE enabled = 1 AND review_status = 'approved'")->fetchColumn();
    $pending = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items WHERE review_status = 'pending'")->fetchColumn();
    line('    searchable (enabled & approved): ' . $active);
    line('    pending review                 : ' . $pending);
    foreach ($db->query("SELECT source_file, MAX(last_seen_at) AS t FROM chat_faq_items GROUP BY source_file")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        line('    imported from ' . $r['source_file'] . ' at ' . $r['t']);
    }
} else {
    line('    >>> chat_faq_items が空です。[1] のパース結果が OK なら、');
    line('        import が別のDBへ書いたか、まだ実行できていません。');
}

// [4] 実際の検索 -------------------------------------------------------------
// 注意: 検索経路は ensureChatFaqTables() を通るため、テーブルが無いDBでは空テーブルを
// 作ってしまう。原因切り分けの邪魔になるので、データがある場合だけ実行する。
line('');
line('[4] retrieval test');
if ($itemCount === 0) {
    line('    skipped: chat_faq_items が空のため実行しません。');
    line('    (このDBに空テーブルを作らないよう、検索経路を呼びません)');
    line('');
    line('Done. (this script changed nothing)');
    exit(0);
}

line('    message  : ' . $message);
line('    threshold: ' . chatFaqScoreThreshold());
$terms = chatFaqSearchTerms($message);
line('    terms    : ' . (empty($terms) ? '(none)' : implode(', ', array_slice($terms, 0, 12))));

$faq = getChatFaqContextForChat($db, $message, 5);
line('    matched  : ' . (!empty($faq['matched']) ? 'yes' : 'no'));
line('    top score: ' . (int)$faq['top_score'] . ' (top FAQ: ' . ($faq['top_faq_id'] ?: '-') . ')');
if (!empty($faq['items'])) {
    foreach ($faq['items'] as $item) {
        line(sprintf('      %-12s score=%-4d 優先度=%-6s 信頼度=%-6s %s',
            $item['faq_id'], $item['score'], $item['priority'], $item['confidence'],
            mb_substr($item['question'], 0, 40)));
    }
} elseif (!empty($faq['below_threshold'])) {
    line('    >>> 候補はあったが閾値未満。プロンプトには「推測せず確認へ誘導」の指示が入ります。');
} else {
    line('    >>> 候補ゼロ。語句がどのFAQにも一致していません。');
}

line('');
line('prompt block length: ' . mb_strlen(buildChatFaqPromptBlock($faq)) . ' chars');
line('');
line('Done. (this script changed nothing)');

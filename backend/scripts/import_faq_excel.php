<?php
/**
 * 「不動産AI名刺 RAG統合マスター」Excel の RAG_FAQ シートを chat_faq_items へ投入する。
 *
 * Excelの「エンジニア実装仕様」「README_実装説明」シートの指定どおり:
 *   - 1行＝1FAQドキュメント（分割せず1チャンクとして登録）
 *   - FAQ_ID を一意キーに upsert。元ID・別IDは別名FAQ_ID列に保存
 *   - 信頼度＝要確認 の行は本番投入前レビュー対象（既定では検索に出さない）
 *
 * 使い方:
 *   php backend/scripts/import_faq_excel.php
 *   php backend/scripts/import_faq_excel.php --file="assets/不動産AI名刺_RAG統合マスター2026.7.28 (1).xlsx"
 *   php backend/scripts/import_faq_excel.php --dry-run
 *   php backend/scripts/import_faq_excel.php --include-unverified   # 信頼度=要確認 も有効化して投入
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/chat-faq-helper.php';

const XLSX_NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_NS_DOC_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const XLSX_NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

/** 「回答（AI回答用）」のようなヘッダー名 → DB列 の対応。ヘッダー文字列は完全一致で探す。 */
function faqHeaderMap() {
    return [
        'faq_id'          => ['FAQ_ID'],
        'category_major'  => ['大カテゴリ'],
        'category_minor'  => ['小カテゴリ'],
        'question'        => ['質問'],
        'question_aliases' => ['質問の言い換え（CRITICAL）', '質問の言い換え'],
        'answer'          => ['回答（AI回答用）', '回答'],
        'caution'         => ['注意事項・禁止表現'],
        'reference_name'  => ['参照元'],
        'reference_url'   => ['参照URL'],
        'confidence'      => ['信頼度'],
        'update_rule'     => ['更新基準'],
        'keywords'        => ['検索用キーワード'],
        'rag_text'        => ['RAG投入テキスト'],
        'alias_faq_id'    => ['別名FAQ_ID'],
        'priority'        => ['優先度'],
        'adopted_from'    => ['採用元'],
        'updated_on'      => ['更新日'],
        'adoption_status' => ['採用状態'],
    ];
}

function xlsxNodeText(SimpleXMLElement $node) {
    $node->registerXPathNamespace('m', XLSX_NS_MAIN);
    // ふりがな（rPh）配下の <t> は本文ではないので除外する。
    $found = $node->xpath('.//m:t[not(ancestor::m:rPh)]');
    if ($found === false || $found === null) return '';
    $text = '';
    foreach ($found as $t) $text .= (string)$t;
    return $text;
}

function xlsxLoadSharedStrings(ZipArchive $zip) {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') return [];
    $doc = new SimpleXMLElement($xml);
    $strings = [];
    foreach ($doc->children(XLSX_NS_MAIN)->si as $si) {
        $strings[] = xlsxNodeText($si);
    }
    return $strings;
}

function xlsxFindSheetPath(ZipArchive $zip, $sheetName) {
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if (!is_string($relsXml) || !is_string($workbookXml)) {
        throw new RuntimeException('workbook.xml / workbook.xml.rels を読み込めませんでした。');
    }

    $rels = [];
    foreach ((new SimpleXMLElement($relsXml))->children(XLSX_NS_PKG_REL)->Relationship as $rel) {
        $rels[(string)$rel['Id']] = (string)$rel['Target'];
    }

    $sheets = (new SimpleXMLElement($workbookXml))->children(XLSX_NS_MAIN)->sheets;
    foreach ($sheets->children(XLSX_NS_MAIN)->sheet as $sheet) {
        if ((string)$sheet['name'] !== $sheetName) continue;
        $attrs = $sheet->attributes(XLSX_NS_DOC_REL);
        $relId = ($attrs !== null && isset($attrs->id)) ? (string)$attrs->id : '';
        if ($relId === '' || !isset($rels[$relId])) break;
        $target = ltrim($rels[$relId], '/');
        return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
    }

    throw new RuntimeException("シート「{$sheetName}」が見つかりませんでした。");
}

function xlsxColumnLetter($cellRef) {
    return preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
}

/** シートを「列文字 => 値」の配列の配列として読み込む。空セルはキーごと存在しない。 */
function xlsxReadSheetRows(ZipArchive $zip, $sheetPath, array $strings) {
    $xml = $zip->getFromName($sheetPath);
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException("シートXMLを読み込めませんでした: {$sheetPath}");
    }
    $sheet = new SimpleXMLElement($xml);
    $sheetData = $sheet->children(XLSX_NS_MAIN)->sheetData;
    $rows = [];
    foreach ($sheetData->children(XLSX_NS_MAIN)->row as $row) {
        $cells = [];
        foreach ($row->children(XLSX_NS_MAIN)->c as $c) {
            $type = (string)$c['t'];
            $main = $c->children(XLSX_NS_MAIN);
            $value = '';
            if ($type === 's') {
                $idx = (int)(string)$main->v;
                $value = $strings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($main->is) ? xlsxNodeText($main->is) : '';
            } else {
                $value = isset($main->v) ? (string)$main->v : '';
            }
            $letter = xlsxColumnLetter((string)$c['r']);
            if ($letter !== '') $cells[$letter] = $value;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** Excelのシリアル値／文字列どちらの更新日でも Y-m-d に寄せる。判別できなければ null。 */
function faqNormalizeDate($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) return $m[0];
    if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $value)) {
        $ts = strtotime(str_replace('/', '-', $value));
        return $ts ? date('Y-m-d', $ts) : null;
    }
    if (is_numeric($value)) {
        // Excelのシリアル値。25569 = 1970-01-01 のシリアル値（1900年うるう年バグ込み）。
        $serial = (float)$value;
        if ($serial >= 61) return gmdate('Y-m-d', (int)round(($serial - 25569) * 86400));
    }
    return null;
}

function faqCliOption($args, $name, $default = null) {
    foreach ($args as $arg) {
        if ($arg === '--' . $name) return true;
        if (strpos($arg, '--' . $name . '=') === 0) return substr($arg, strlen($name) + 3);
    }
    return $default;
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$dryRun = faqCliOption($args, 'dry-run', false) !== false;
$includeUnverified = faqCliOption($args, 'include-unverified', false) !== false;
$sheetName = faqCliOption($args, 'sheet', 'RAG_FAQ');
if (!is_string($sheetName) || $sheetName === '') $sheetName = 'RAG_FAQ';
$path = faqCliOption($args, 'file', null);
if (!is_string($path) || $path === '') {
    // 位置引数でも受ける（--file= を付けない呼び方）。
    foreach ($args as $arg) {
        if (strpos($arg, '--') !== 0) { $path = $arg; break; }
    }
}
if (!is_string($path) || $path === '') {
    // 既定はassets配下のRAG統合マスター。ファイル名の版番号は変わるため後方一致で探す。
    $candidates = glob(__DIR__ . '/../../assets/*RAG統合マスター*.xlsx') ?: [];
    rsort($candidates);
    if (empty($candidates)) {
        exit("Error: assets/ に RAG統合マスター の xlsx が見つかりません。--file= で指定してください。\n");
    }
    $path = $candidates[0];
}
$resolved = realpath($path);
if ($resolved === false) {
    exit("Error: file not found: {$path}\n");
}
$path = $resolved;

echo "=== Import FAQ master into chat_faq_items ===\n";
echo "file : {$path}\n";
echo "sheet: {$sheetName}\n";
echo "mode : " . ($dryRun ? 'DRY RUN (no writes)' : 'WRITE') . ($includeUnverified ? ' / include-unverified' : '') . "\n\n";

if (!class_exists('ZipArchive')) {
    exit("Error: PHP ZipArchive extension is required.\n");
}

$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    exit("Error: could not open xlsx: {$path}\n");
}

try {
    $strings = xlsxLoadSharedStrings($zip);
    $sheetPath = xlsxFindSheetPath($zip, $sheetName);
    $rows = xlsxReadSheetRows($zip, $sheetPath, $strings);
} catch (Throwable $e) {
    $zip->close();
    exit('Error: ' . $e->getMessage() . "\n");
}
$zip->close();

if (count($rows) < 2) {
    exit("Error: sheet has no data rows.\n");
}

// 1行目のヘッダーから「DB列 => Excel列文字」を作る。
$headerRow = array_shift($rows);
$columns = [];
foreach (faqHeaderMap() as $field => $candidates) {
    foreach ($headerRow as $letter => $label) {
        $label = trim((string)$label);
        if (in_array($label, $candidates, true)) { $columns[$field] = $letter; break; }
    }
}
foreach (['faq_id', 'question', 'answer', 'rag_text'] as $required) {
    if (!isset($columns[$required])) {
        exit("Error: required column not found in header: {$required}\n");
    }
}

$get = function (array $row, $field) use ($columns) {
    if (!isset($columns[$field])) return '';
    return trim((string)($row[$columns[$field]] ?? ''));
};

// 別名FAQ_IDの衝突判定に使うため、まず全FAQ_IDを集める。
$records = [];
$allIds = [];
$skippedNotAdopted = 0;
$skippedEmpty = 0;
foreach ($rows as $row) {
    $faqId = $get($row, 'faq_id');
    if ($faqId === '') { $skippedEmpty++; continue; }
    $status = $get($row, 'adoption_status');
    if ($status !== '' && $status !== '採用') { $skippedNotAdopted++; continue; }
    $allIds[$faqId] = true;
    $records[] = $row;
}

$sourceFile = basename($path);
$batchStamp = date('Y-m-d H:i:s');

$prepared = [];
$aliasConflicts = [];
$pendingReview = [];
foreach ($records as $row) {
    $faqId = $get($row, 'faq_id');
    $alias = $get($row, 'alias_faq_id');
    $confidence = $get($row, 'confidence') ?: '中';
    $priority = $get($row, 'priority') ?: '通常';

    // 別名FAQ_IDが「別の実在FAQ_ID」を指している行は要注意。同一FAQ扱いにすると
    // 無関係なFAQを取り違えるため、印を付けて検索側の重複判定から除外する。
    $aliasConflict = ($alias !== '' && $alias !== $faqId && isset($allIds[$alias])) ? 1 : 0;
    if ($aliasConflict) $aliasConflicts[] = $faqId . ' -> ' . $alias;

    $needsReview = ($confidence === '要確認');
    if ($needsReview) $pendingReview[] = $faqId . ' / ' . $get($row, 'question');

    $fields = [
        'faq_id' => $faqId,
        'category_major' => mb_substr($get($row, 'category_major'), 0, 80),
        'category_minor' => mb_substr($get($row, 'category_minor'), 0, 120),
        'question' => mb_substr($get($row, 'question'), 0, 500),
        'question_aliases' => $get($row, 'question_aliases'),
        'answer' => $get($row, 'answer'),
        'caution' => $get($row, 'caution'),
        'reference_name' => mb_substr($get($row, 'reference_name'), 0, 255),
        'reference_url' => $get($row, 'reference_url'),
        'confidence' => mb_substr($confidence, 0, 20),
        'update_rule' => mb_substr($get($row, 'update_rule'), 0, 255),
        'keywords' => $get($row, 'keywords'),
        'rag_text' => $get($row, 'rag_text'),
        'alias_faq_id' => $alias !== '' ? mb_substr($alias, 0, 64) : null,
        'alias_conflict' => $aliasConflict,
        'priority' => mb_substr($priority, 0, 20),
        'priority_rank' => chatFaqPriorityRank($priority),
        'adopted_from' => mb_substr($get($row, 'adopted_from'), 0, 80),
        'adoption_status' => mb_substr($get($row, 'adoption_status') ?: '採用', 0, 20),
        'review_status' => ($needsReview && !$includeUnverified) ? 'pending' : 'approved',
        'enabled' => ($needsReview && !$includeUnverified) ? 0 : 1,
        'source_file' => mb_substr($sourceFile, 0, 255),
        'updated_on' => faqNormalizeDate($get($row, 'updated_on')),
    ];
    $fields['content_hash'] = hash('sha256', implode("\x1f", [
        $fields['question'], $fields['question_aliases'], $fields['answer'], $fields['caution'],
        $fields['keywords'], $fields['rag_text'], $fields['confidence'], $fields['priority'],
        $fields['reference_url'], $fields['update_rule'],
    ]));
    $prepared[] = $fields;
}

echo "parsed rows      : " . count($rows) . "\n";
echo "importable rows  : " . count($prepared) . "\n";
echo "skipped (no ID)  : {$skippedEmpty}\n";
echo "skipped (未採用) : {$skippedNotAdopted}\n";
echo "要確認 (review)  : " . count($pendingReview) . ($includeUnverified ? " -> enabled\n" : " -> review_status=pending / enabled=0\n");
echo "alias conflicts  : " . count($aliasConflicts) . "\n\n";

if ($dryRun) {
    if (!empty($aliasConflicts)) {
        echo "-- alias conflicts (別名FAQ_IDが別の実在FAQ_IDと衝突) --\n";
        foreach (array_slice($aliasConflicts, 0, 20) as $line) echo "  {$line}\n";
        if (count($aliasConflicts) > 20) echo '  ... +' . (count($aliasConflicts) - 20) . " more\n";
        echo "\n";
    }
    if (!empty($pendingReview)) {
        echo "-- 要確認 rows --\n";
        foreach (array_slice($pendingReview, 0, 20) as $line) echo "  {$line}\n";
        if (count($pendingReview) > 20) echo '  ... +' . (count($pendingReview) - 20) . " more\n";
    }
    echo "\nDry run finished. No database changes were made.\n";
    exit(0);
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    exit('Error: database connection failed: ' . $e->getMessage() . "\n");
}

ensureChatFaqTables($db);

// review_status は運用側でレビュー承認した結果を保持したいので、再取り込みでは
// 上書きしない（承認済みの行を毎回 pending へ戻さない）。新規行のみ初期値が入る。
$sql = "INSERT INTO chat_faq_items
    (faq_id, category_major, category_minor, question, question_aliases, answer, caution,
     reference_name, reference_url, confidence, update_rule, keywords, rag_text,
     alias_faq_id, alias_conflict, priority, priority_rank, adopted_from, adoption_status,
     review_status, enabled, source_file, updated_on, content_hash, last_seen_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        category_major = VALUES(category_major),
        category_minor = VALUES(category_minor),
        question = VALUES(question),
        question_aliases = VALUES(question_aliases),
        answer = VALUES(answer),
        caution = VALUES(caution),
        reference_name = VALUES(reference_name),
        reference_url = VALUES(reference_url),
        confidence = VALUES(confidence),
        update_rule = VALUES(update_rule),
        keywords = VALUES(keywords),
        rag_text = VALUES(rag_text),
        alias_faq_id = VALUES(alias_faq_id),
        alias_conflict = VALUES(alias_conflict),
        priority = VALUES(priority),
        priority_rank = VALUES(priority_rank),
        adopted_from = VALUES(adopted_from),
        adoption_status = VALUES(adoption_status),
        enabled = IF(review_status = 'pending', 0, VALUES(enabled)),
        source_file = VALUES(source_file),
        updated_on = VALUES(updated_on),
        content_hash = VALUES(content_hash),
        last_seen_at = VALUES(last_seen_at)";

$inserted = 0;
$updated = 0;
$failed = 0;

try {
    $db->beginTransaction();
    $stmt = $db->prepare($sql);
    foreach ($prepared as $f) {
        try {
            $stmt->execute([
                $f['faq_id'], $f['category_major'], $f['category_minor'], $f['question'],
                $f['question_aliases'], $f['answer'], $f['caution'], $f['reference_name'],
                $f['reference_url'], $f['confidence'], $f['update_rule'], $f['keywords'],
                $f['rag_text'], $f['alias_faq_id'], $f['alias_conflict'], $f['priority'],
                $f['priority_rank'], $f['adopted_from'], $f['adoption_status'],
                $f['review_status'], $f['enabled'], $f['source_file'], $f['updated_on'],
                $f['content_hash'], $batchStamp,
            ]);
            // MySQLのON DUPLICATE KEY UPDATEは、新規1件/更新2件/変更なし0件を返す。
            $rowCount = $stmt->rowCount();
            if ($rowCount === 1) $inserted++; else $updated++;
        } catch (Throwable $e) {
            $failed++;
            echo "  ! {$f['faq_id']}: " . $e->getMessage() . "\n";
        }
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    exit('Error: import failed: ' . $e->getMessage() . "\n");
}

// 同じExcelから取り込んだ行のうち、今回のファイルに存在しなくなったものを無効化する
// （削除はせず、履歴とレビュー結果を残す）。
$disabled = 0;
try {
    $stmt = $db->prepare("UPDATE chat_faq_items
        SET enabled = 0
        WHERE source_file = ? AND (last_seen_at IS NULL OR last_seen_at <> ?) AND enabled = 1");
    $stmt->execute([$sourceFile, $batchStamp]);
    $disabled = $stmt->rowCount();
} catch (Throwable $e) {
    echo 'Warning: could not disable stale rows: ' . $e->getMessage() . "\n";
}

echo "\n=== Result ===\n";
echo "inserted        : {$inserted}\n";
echo "updated         : {$updated}\n";
echo "failed          : {$failed}\n";
echo "disabled (stale): {$disabled}\n";

$total = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items")->fetchColumn();
$active = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items WHERE enabled = 1 AND review_status = 'approved'")->fetchColumn();
$pending = (int)$db->query("SELECT COUNT(*) FROM chat_faq_items WHERE review_status = 'pending'")->fetchColumn();
echo "rows in table   : {$total}\n";
echo "searchable      : {$active}\n";
echo "pending review  : {$pending}\n";

if ($pending > 0) {
    echo "\n信頼度=要確認 の行はレビュー承認まで検索に出ません。\n";
    echo "内容確認 : php backend/scripts/faq_review_report.php\n";
    echo "承認     : php backend/scripts/faq_review_report.php --approve=CRI014,CRI021\n";
}
if (!empty($aliasConflicts)) {
    echo "\n別名FAQ_IDが別の実在FAQ_IDと衝突している行が " . count($aliasConflicts) . " 件あります。\n";
    echo "これらの別名は同一FAQ判定に使いません（無関係なFAQの取り違えを防ぐため）。\n";
    echo "一覧 : php backend/scripts/faq_review_report.php --alias-conflicts\n";
}
echo "\nDone.\n";

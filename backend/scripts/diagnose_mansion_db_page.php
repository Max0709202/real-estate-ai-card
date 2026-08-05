<?php
/**
 * 全国マンションデータベース（db.self-in.com）実ページ参照の動作確認スクリプト。
 * チャット本体と同じ関数を同じ順序で呼ぶため、「どこで実ページ参照が止まったか」が
 * そのまま分かる。DBへの書き込みはマンションID解決の書き戻しのみ（--dry で抑止可）。
 *
 * 使い方:
 *   php backend/scripts/diagnose_mansion_db_page.php "エルザタワー55の販売履歴を教えて"
 *   php backend/scripts/diagnose_mansion_db_page.php --id=12345          （マンションID直接指定）
 *   php backend/scripts/diagnose_mansion_db_page.php --id=12345 --dump   （整形後テキストを表示）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-helpers.php';
require_once __DIR__ . '/../includes/openai-chat-helper.php';
require_once __DIR__ . '/../includes/chat-public-data-helper.php';

$message = '';
$mdbId = 0;
$dump = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) $mdbId = (int)$m[1];
    elseif ($arg === '--dump') $dump = true;
    elseif (strpos($arg, '--') !== 0) $message = $arg;
}
if ($message === '' && $mdbId === 0) $message = 'エルザタワー55について教えて';

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function line($s = '') { echo $s . "\n"; }

line('==================================================================');
line('MANSION DB PAGE DIAGNOSTIC');
line('message : ' . ($message !== '' ? $message : '(なし)'));
line('mdb_id  : ' . ($mdbId > 0 ? $mdbId : '(未指定 → 名称から解決)'));
line('==================================================================');

// 1) 設定 --------------------------------------------------------------------
line('');
line('[1] 設定');
line('    MANSION_DB_WEB_ENABLED    : ' . (MANSION_DB_WEB_ENABLED ? 'true' : 'false'));
line('    MANSION_DB_WEB_BASE_URL   : ' . MANSION_DB_WEB_BASE_URL);
line('    MANSION_DB_WEB_CID        : ' . MANSION_DB_WEB_CID);
line('    MANSION_DB_WEB_ON         : ' . MANSION_DB_WEB_ON . '  (必ず0／1は使用しない)');
line('    MANSION_DB_WEB_SEARCH_URL : ' . (MANSION_DB_WEB_SEARCH_URL !== '' ? MANSION_DB_WEB_SEARCH_URL : '(未設定 → ID検索は行わない)'));
line('    curl                      : ' . (function_exists('curl_init') ? 'あり' : 'なし'));
if (!chatMansionWebEnabled()) {
    line('    >>> 実ページ参照は無効です。従来のDB基礎情報のみで回答されます。');
    exit;
}

// 2) 連携列 ------------------------------------------------------------------
line('');
line('[2] mansion_buildings のマンションID連携列');
$hasCols = chatMansionWebHasIdColumns($db);
line('    mdb_id 列 : ' . ($hasCols ? 'あり' : 'なし'));
if (!$hasCols) {
    line('    >>> 未適用です。実行してください:');
    line('    >>> mysql < backend/database/migrations/add_mansion_db_page_link.sql');
} else {
    $linked = (int)$db->query('SELECT COUNT(*) FROM mansion_buildings WHERE mdb_id IS NOT NULL')->fetchColumn();
    $total = (int)$db->query('SELECT COUNT(*) FROM mansion_buildings')->fetchColumn();
    line('    マンションID保有件数 : ' . $linked . ' / ' . $total);
    if ($linked === 0 && MANSION_DB_WEB_SEARCH_URL === '') {
        line('    >>> マンションIDの入手経路がありません（マスタ配布・検索URLのどちらも未設定）。');
        line('    >>> 実ページ参照は行われず、外部リクエストも発生しません。');
    }
}

// 3) 建物の特定 --------------------------------------------------------------
$row = null;
if ($mdbId === 0) {
    line('');
    line('[3] 建物の特定（既存のマンション名RAG）');
    $resolved = chatResolveMansionAddress($db, $message);
    if ($resolved === null) {
        line('    >>> 建物を特定できませんでした。先に diagnose_mansion_lookup.php で確認してください。');
        exit;
    }
    $row = $resolved['row'];
    line('    building_name : ' . ($row['building_name'] ?? ''));
    line('    full_address  : ' . ($row['full_address'] ?? ''));
    line('    local id      : ' . ($row['id'] ?? ''));

    line('');
    line('[4] マンションIDの解決');
    $mdbId = (int)chatMansionWebResolveId($db, $row);
    line('    mdb_id : ' . ($mdbId > 0 ? $mdbId : '解決できず'));
    if ($mdbId <= 0) {
        line('    >>> マンションIDが無いため実ページは取得しません（従来回答へフォールバック）。');
        exit;
    }
}

// 5) ページ取得 --------------------------------------------------------------
$url = chatMansionWebPageUrl($mdbId);
line('');
line('[5] ページ取得');
line('    URL : ' . $url);
$page = chatMansionWebCachedHtml($db, $url, MANSION_DB_WEB_CACHE_TTL);
line('    HTTP : ' . ($page['status'] ?? 0) . ' / cached=' . (!empty($page['cached']) ? 'yes' : 'no') . ' / bytes=' . strlen((string)$page['html']));
if (empty($page['ok'])) {
    line('    >>> 取得に失敗しました。');
    exit;
}

// 6) 整形 --------------------------------------------------------------------
$sections = chatMansionWebHtmlToSections($page['html']);
line('');
line('[6] セクション抽出 : ' . count($sections) . ' 件');
foreach ($sections as $s) {
    line('    ・' . $s['title'] . '（' . count($s['lines']) . ' 行）');
}
if ($row !== null) {
    line('    建物照合（名称＋住所） : ' . (chatMansionWebPageMatchesRow($sections, $row) ? '一致' : '不一致 → 採用しない'));
}

// 7) プロンプトへ渡す内容 ------------------------------------------------------
$context = chatMansionWebSelectSections($sections, $message, 12000);
line('');
line('[7] プロンプトへ渡す文字数 : ' . mb_strlen($context));
if ($dump) {
    line('------------------------------------------------------------------');
    line($context);
    line('------------------------------------------------------------------');
}

line('');
line('完了。実際の回答文まで確認する場合は CHAT_MANSION_DEBUG=1 でチャットを実行してください。');

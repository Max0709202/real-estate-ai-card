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
line('    MANSION_DB_WEB_SEARCH_URL : ' . (MANSION_DB_WEB_SEARCH_URL !== '' ? MANSION_DB_WEB_SEARCH_URL : '(未設定 → 検索窓から自動生成)'));
line('    MANSION_DB_WEB_SEARCH_PAGE_URL : ' . MANSION_DB_WEB_SEARCH_PAGE_URL);
line('    User-Agent                : ' . MANSION_DB_WEB_USER_AGENT);
line('    curl                      : ' . (function_exists('curl_init') ? 'あり' : 'なし'));
if (!chatMansionWebEnabled()) {
    line('    >>> 実ページ参照は無効です。従来のDB基礎情報のみで回答されます。');
    exit;
}

// 1b) 検索窓の自動解析 --------------------------------------------------------
line('');
line('[1b] 検索URLの特定（マンションID取得の入口）');
$template = chatMansionWebSearchTemplate($db);
if ($template === null || $template === '') {
    line('    >>> 検索URLを自動生成できませんでした。');
    line('    >>> 検索窓がPOST送信、またはJavaScriptで候補を取得している可能性があります。');
    line('');
    line('    --- 検索窓ページの調査結果 ---');
    $probe = chatMansionWebProbeSearchEndpoints($db);
    line('    ページ取得 : ' . ($probe['ok'] ? 'OK' : '失敗') . ' (HTTP ' . $probe['status'] . ')');
    if ($probe['ok']) {
        line('    <form> : ' . count($probe['forms']) . ' 件');
        foreach ($probe['forms'] as $i => $form) {
            line('      [' . ($i + 1) . '] method=' . $form['method'] . ' action=' . $form['action']);
            foreach ($form['inputs'] as $input) {
                line('           input name=' . $input['name'] . ' type=' . $input['type']
                    . ($input['value'] !== '' ? ' value=' . $input['value'] : ''));
            }
        }
        line('    候補取得URLらしき文字列 : ' . count($probe['endpoints']) . ' 件');
        foreach ($probe['endpoints'] as $endpoint) line('      - ' . $endpoint);
        line('    読み込まれているJS :');
        foreach ($probe['scripts'] as $script) line('      - ' . $script);
    }
    line('    ------------------------------');
    line('    >>> 上記に候補取得URLが見当たらない場合は、ブラウザで検索窓に');
    line('    >>> マンション名を入力し、開発者ツールの[ネットワーク]タブに現れる');
    line('    >>> リクエストURLを MANSION_DB_WEB_SEARCH_URL に設定してください');
    line('    >>> （マンション名の箇所を {name} に置き換える）。');
} else {
    line('    テンプレート : ' . $template);
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
    line('    ※ 一括解決は backend/scripts/backfill_mansion_mdb_id.php で行えます。');
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
    line('[4] マンションIDの解決（検索窓 → 候補 → 実ページで裏取り）');
    if ($template !== null && $template !== '') {
        $searchUrl = str_replace('{name}', rawurlencode((string)$row['building_name']), $template);
        line('    検索URL : ' . $searchUrl);
        $searchResponse = chatMansionWebCachedHtml($db, $searchUrl, MANSION_DB_WEB_CACHE_TTL);
        line('    HTTP    : ' . ($searchResponse['status'] ?? 0) . ' / bytes=' . strlen((string)$searchResponse['html']));
        if ((int)($searchResponse['status'] ?? 0) === 403) {
            line('      >>> 403 Forbidden。このサーバーからのアクセスが拒否されています。');
            line('      >>> db.self-in.com はサーバー経由のアクセスをIPで拒否することがあります');
            line('      >>> （nginx の素の403が返る＝User-Agent等のヘッダーでは回避できません）。');
            line('      >>> 先方に、このサーバーのIPアドレスからのアクセス許可をご依頼ください。');
        }
        $candidates = chatMansionWebExtractCandidates($searchResponse['html']);
        line('    候補    : ' . count($candidates) . ' 件');
        foreach (array_slice($candidates, 0, 10) as $candidate) {
            line('      - id=' . $candidate['id'] . ' ' . $candidate['label']);
        }
        if (empty($candidates) && !empty($searchResponse['ok'])) {
            line('      >>> 応答は取得できましたが候補リンクが見つかりません。');
            line('      >>> 候補がJavaScriptで後から描画されている可能性があります。');
            line('      >>> ブラウザの開発者ツール（ネットワーク）で候補取得のURLを確認し、');
            line('      >>> そのURLを MANSION_DB_WEB_SEARCH_URL に設定してください。');
        }
    }
    $mdbId = (int)chatMansionWebResolveId($db, $row);
    line('    mdb_id  : ' . ($mdbId > 0 ? $mdbId : '解決できず'));
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

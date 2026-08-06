<?php
/**
 * 全国マンションデータベース（db.self-in.com）のマンションIDを、公開されている
 * マンション名の検索窓を使って自力で解決し、mansion_buildings.mdb_id に保存する。
 *
 * ベンダーからID一覧の提供を受けると高額な費用が発生するため、検索窓 →候補 →
 * 実ページで裏取り、という人が手でやる操作をそのまま自動化している。
 * 一度解決したIDは保存され、以後そのマンションで検索が走ることはない。
 *
 * 全件（数十万棟）を一括で回す必要はない。チャットで問い合わせのあった棟は
 * その場で自動解決されるため、本スクリプトは「よく聞かれるエリアを先に温めておく」
 * 用途で、都道府県や市区町村を絞って流すのが実用的。
 *
 * 使い方:
 *   php backend/scripts/backfill_mansion_mdb_id.php --pref=東京都 --limit=500
 *   php backend/scripts/backfill_mansion_mdb_id.php --pref=東京都 --city=足立区
 *   php backend/scripts/backfill_mansion_mdb_id.php --name=エルザタワー --dry
 *   php backend/scripts/backfill_mansion_mdb_id.php --retry-notfound --limit=200
 *
 * オプション:
 *   --limit=N          処理する最大棟数（既定100）
 *   --pref=東京都      都道府県で絞り込む
 *   --city=足立区      市区町村で絞り込む
 *   --name=○○         建物名の部分一致で絞り込む
 *   --sleep=200        リクエスト間隔（ミリ秒。既定は MANSION_DB_WEB_SEARCH_DELAY_MS）
 *   --retry-notfound   前回「見つからなかった」棟も再試行する
 *   --dry              DBへ書き込まず、解決結果の表示のみ行う
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-helpers.php';
require_once __DIR__ . '/../includes/openai-chat-helper.php';
require_once __DIR__ . '/../includes/chat-public-data-helper.php';

$opt = ['limit' => 100, 'pref' => '', 'city' => '', 'name' => '', 'sleep' => null, 'retry' => false, 'dry' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $opt['limit'] = max(1, (int)$m[1]);
    elseif (preg_match('/^--pref=(.+)$/u', $arg, $m)) $opt['pref'] = $m[1];
    elseif (preg_match('/^--city=(.+)$/u', $arg, $m)) $opt['city'] = $m[1];
    elseif (preg_match('/^--name=(.+)$/u', $arg, $m)) $opt['name'] = $m[1];
    elseif (preg_match('/^--sleep=(\d+)$/', $arg, $m)) $opt['sleep'] = (int)$m[1];
    elseif ($arg === '--retry-notfound') $opt['retry'] = true;
    elseif ($arg === '--dry') $opt['dry'] = true;
    else { fwrite(STDERR, "unknown option: {$arg}\n"); exit(1); }
}
if ($opt['sleep'] === null) {
    $opt['sleep'] = defined('MANSION_DB_WEB_SEARCH_DELAY_MS') ? (int)MANSION_DB_WEB_SEARCH_DELAY_MS : 200;
}

function line($s = '') { echo $s . "\n"; }

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

line('==================================================================');
line('MANSION ID BACKFILL');
line('limit=' . $opt['limit'] . ' pref=' . ($opt['pref'] ?: '-') . ' city=' . ($opt['city'] ?: '-')
    . ' name=' . ($opt['name'] ?: '-') . ' sleep=' . $opt['sleep'] . 'ms'
    . ($opt['retry'] ? ' retry-notfound' : '') . ($opt['dry'] ? ' DRY-RUN' : ''));
line('==================================================================');

if (!chatMansionWebEnabled()) {
    line('実ページ参照が無効です（MANSION_DB_WEB_ENABLED / curl を確認してください）。');
    exit(1);
}
if (!chatMansionWebHasIdColumns($db)) {
    line('mansion_buildings に mdb_id 列がありません。先に以下を実行してください:');
    line('  mysql < backend/database/migrations/add_mansion_db_page_link.sql');
    exit(1);
}

$template = chatMansionWebSearchTemplate($db);
if ($template === null || $template === '') {
    line('検索URLを特定できませんでした。');
    line('  MANSION_DB_WEB_SEARCH_PAGE_URL : ' . MANSION_DB_WEB_SEARCH_PAGE_URL);
    line('  検索窓がPOST送信・JavaScript専用の場合は自動生成できません。');
    line('  ブラウザで1件検索し、アドレスバーのURLを MANSION_DB_WEB_SEARCH_URL に');
    line('  設定してください（マンション名の箇所を {name} に置き換える）。');
    exit(1);
}
line('検索URLテンプレート : ' . $template);
line('');

// 未解決の棟を拾う。--retry-notfound を付けない限り、直近に「見つからなかった」と
// 判定済みの棟は飛ばす（同じ空振りを何度も先方サーバーへ投げないため）。
$where = ['mdb_id IS NULL'];
$params = [];
if (!$opt['retry']) {
    $where[] = "(mdb_id_status IS NULL OR mdb_id_status <> 'notfound')";
}
if ($opt['pref'] !== '') { $where[] = 'prefecture = :pref'; $params[':pref'] = $opt['pref']; }
if ($opt['city'] !== '') { $where[] = 'city LIKE :city'; $params[':city'] = '%' . $opt['city'] . '%'; }
if ($opt['name'] !== '') { $where[] = 'building_name LIKE :name'; $params[':name'] = '%' . $opt['name'] . '%'; }

$sql = 'SELECT id, building_name, postal_code, prefecture, city, town, address_detail, full_address
        FROM mansion_buildings
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id ASC
        LIMIT ' . (int)$opt['limit'];
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    line('対象の棟がありません（すべて解決済み、または条件に一致なし）。');
    exit;
}
line('対象 ' . count($rows) . ' 棟');
line('');

$found = 0;
$missed = 0;
$startedAt = microtime(true);
foreach ($rows as $index => $row) {
    $label = ($row['building_name'] ?? '') . '（' . ($row['prefecture'] ?? '') . ($row['city'] ?? '') . '）';
    try {
        $mdbId = chatMansionWebSearchId($db, $row);
    } catch (Throwable $e) {
        $mdbId = null;
        line(sprintf('[%d/%d] ERROR %s : %s', $index + 1, count($rows), $label, $e->getMessage()));
    }
    if ($mdbId) {
        $found++;
        line(sprintf('[%d/%d] OK    %s -> mdb_id=%d', $index + 1, count($rows), $label, $mdbId));
    } else {
        $missed++;
        line(sprintf('[%d/%d] ----  %s -> 見つからず', $index + 1, count($rows), $label));
    }
    if (!$opt['dry']) {
        chatMansionWebStoreId($db, (int)$row['id'], $mdbId, $mdbId ? 'confirmed' : 'notfound');
    }
    if ($opt['sleep'] > 0 && $index < count($rows) - 1) {
        usleep($opt['sleep'] * 1000);
    }
}

$elapsed = microtime(true) - $startedAt;
line('');
line('------------------------------------------------------------------');
line(sprintf('完了 : 解決 %d 棟 / 未解決 %d 棟 / 所要 %.1f 秒', $found, $missed, $elapsed));
if ($opt['dry']) line('※ DRY-RUN のため mdb_id は保存していません。');
$remaining = (int)$db->query('SELECT COUNT(*) FROM mansion_buildings WHERE mdb_id IS NULL')->fetchColumn();
$linked = (int)$db->query('SELECT COUNT(*) FROM mansion_buildings WHERE mdb_id IS NOT NULL')->fetchColumn();
line(sprintf('mdb_id 保有 %d 棟 / 未解決 %d 棟', $linked, $remaining));

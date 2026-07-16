<?php
/**
 * Read-only diagnostic for the マンション名 RAG lookup. Does NOT modify the DB.
 * Runs the exact chat retrieval pipeline against the live mansion_buildings table
 * so we can see WHERE a "見つかりませんでした" answer actually originates.
 *
 * Usage:
 *   php backend/scripts/diagnose_mansion_lookup.php "カーサ新宿の詳しい情報を教えて"
 *   php backend/scripts/diagnose_mansion_lookup.php            (defaults to カーサ新宿)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-helpers.php';
// Load the OpenAI helper so callOpenAIChat()/chatOpenAIModelMansion() exist EXACTLY
// as they do in the live send.php request. Without it, the LLM formatting step is
// skipped and a crash inside that step (which nulls the whole mansion answer) is
// invisible — which is the difference between this diagnostic and the live chat.
require_once __DIR__ . '/../includes/openai-chat-helper.php';
require_once __DIR__ . '/../includes/chat-public-data-helper.php';

$message = $argv[1] ?? 'カーサ新宿の詳しい情報を教えて';
// The bare building name we expect to exist, used for the raw existence probe.
$probeName = $argv[2] ?? 'カーサ新宿';

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function line($s = '') { echo $s . "\n"; }

line('==================================================================');
line('MANSION LOOKUP DIAGNOSTIC');
line('message  : ' . $message);
line('probe    : ' . $probeName);
line('==================================================================');

// 1) Table population --------------------------------------------------------
$total = (int)$db->query('SELECT COUNT(*) FROM mansion_buildings')->fetchColumn();
line('');
line('[1] mansion_buildings row count : ' . $total);
if ($total === 0) {
    line('    >>> TABLE IS EMPTY. The import was never run on this server.');
    line('    >>> Run: php backend/scripts/import_mansion_buildings.php');
    exit;
}

// 2) Normalized columns present & populated ----------------------------------
$hasCols = chatMansionDbHasNormalizedColumns($db);
line('');
line('[2] name_norm/search_norm columns present : ' . ($hasCols ? 'YES' : 'NO'));
if ($hasCols) {
    $nullNorm = (int)$db->query('SELECT COUNT(*) FROM mansion_buildings WHERE name_norm IS NULL OR name_norm = \'\'')->fetchColumn();
    line('    rows with empty name_norm : ' . $nullNorm . ($nullNorm > 0 ? '  <<< run migrate_mansion_name_norm.php' : ''));
} else {
    line('    >>> Search will use the LEGACY path (building_name/search_text LIKE).');
}

// 3) Raw existence probe (does the building physically exist?) ----------------
line('');
line('[3] Raw existence probe: building_name LIKE %' . $probeName . '%');
$stmt = $db->prepare('SELECT id, building_name, name_norm, full_address FROM mansion_buildings WHERE building_name LIKE ? ORDER BY CHAR_LENGTH(building_name) LIMIT 10');
$stmt->execute(['%' . $probeName . '%']);
$probeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$probeRows) {
    line('    >>> NO ROW physically contains "' . $probeName . '".');
    line('    >>> This DB does not hold that building (partial/old import).');
} else {
    foreach ($probeRows as $r) {
        line(sprintf('    id=%-7s name=%-24s name_norm=%-20s addr=%s',
            $r['id'], $r['building_name'], $r['name_norm'] ?? '(null)', $r['full_address'] ?? ''));
    }
}

// 4) Query normalization -----------------------------------------------------
line('');
line('[4] Query pipeline');
$terms = chatExtractMansionSearchTerms($message);
line('    extracted terms   : ' . json_encode($terms, JSON_UNESCAPED_UNICODE));
line('    specific enough?  : ' . (chatMansionTermLooksSpecific($terms, $message) ? 'YES' : 'NO'));
foreach ($terms as $t) {
    line('    term "' . $t . '" -> name_norm form: "' . chatMansionNormalizeText($t) . '"'
        . '  tokens: ' . json_encode(chatMansionTokenizeForMatch($t), JSON_UNESCAPED_UNICODE));
}

// 5) Retrieval (the exact chat search) ---------------------------------------
line('');
line('[5] chatMansionDbSearchRows() recall');
$rows = chatMansionDbSearchRows($db, $terms, 5);
line('    rows recalled : ' . count($rows));
foreach ($rows as $r) {
    line(sprintf('      - id=%-7s %s  |  %s', $r['id'] ?? '', $r['building_name'] ?? '', $r['full_address'] ?? ''));
}

// 5b) LLM formatting environment (live-only variables) -----------------------
line('');
line('[5b] LLM formatting environment (this is what the CLI-only run skipped)');
line('    callOpenAIChat() defined        : ' . (function_exists('callOpenAIChat') ? 'YES' : 'NO'));
$model = function_exists('chatOpenAIModelMansion') ? chatOpenAIModelMansion()
    : (defined('OPENAI_CHAT_MODEL') ? OPENAI_CHAT_MODEL : '(none)');
line('    mansion model                   : ' . $model);
$apiKey = function_exists('chatOpenAIApiKeyForModel') ? chatOpenAIApiKeyForModel($model)
    : (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
line('    api key for that model present  : ' . ($apiKey !== '' ? 'YES (len ' . strlen($apiKey) . ')' : 'NO  <<< empty key'));
if (function_exists('chatMansionGenerateIntroduction')) {
    line('    -- calling chatMansionGenerateIntroduction() on カーサ新宿 facts --');
    try {
        $probe = $probeRows[0] ?? ($rows[0] ?? null);
        if ($probe) {
            $intro = chatMansionGenerateIntroduction(chatMansionGatherFacts($probe), 'テスト担当');
            line('    intro result : ' . ($intro === null ? 'NULL (LLM skipped/failed -> deterministic fallback used)' : 'OK, ' . mb_strlen($intro) . ' chars'));
        }
    } catch (Throwable $e) {
        line('    !!! EXCEPTION in LLM formatting: ' . get_class($e) . ': ' . $e->getMessage());
        line('    !!! ' . $e->getFile() . ':' . $e->getLine());
        line('    >>> THIS is why the live mansion answer is nulled and the chat falls to GPT.');
    }
}

// 6) Full answer path, wrapped so a live-only exception is visible -----------
line('');
line('[6] chatMansionDbDirectAnswer() outcome (full live path, LLM active)');
try {
    $ans = chatMansionDbDirectAnswer($db, $message, 'テスト担当');
    if ($ans === null) {
        line('    >>> RETURNED NULL  (chat then falls to GPT / not-found).');
    } elseif (!empty($ans['ambiguous'])) {
        line('    >>> DISAMBIGUATION list (clickable candidates):');
        foreach ($ans['quick_replies'] ?? [] as $q) {
            line('        [' . ($q['label'] ?? '') . ']  value=' . ($q['value'] ?? ''));
        }
    } else {
        line('    >>> DIRECT ANSWER produced. reply preview:');
        line('    ' . str_replace("\n", "\n    ", mb_substr($ans['reply'] ?? '', 0, 600)));
    }
    // Show the clickable candidate/sibling buttons attached to the answer.
    $qr = $ans['quick_replies'] ?? [];
    $siblings = array_filter($qr, function ($q) { return ($q['field'] ?? '') === 'mansion_lookup'; });
    line('');
    line('    clickable sibling candidates (field=mansion_lookup): ' . count($siblings));
    foreach ($siblings as $q) {
        line('        [' . ($q['label'] ?? '') . ']  value=' . ($q['value'] ?? ''));
    }
} catch (Throwable $e) {
    line('    !!! UNCAUGHT EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage());
    line('    !!! ' . $e->getFile() . ':' . $e->getLine());
}
// 7) The hard not-found guard gate -------------------------------------------
// This is what decides whether an ordinary question gets hijacked by
// 「該当するマンションが見つかりませんでした」 instead of being answered by the AI.
line('');
line('[7] 「該当するマンションが見つかりませんでした」 guard gate');
$isLand = (bool)preg_match('/(土地情報|ハザード|用途地域|建ぺい率|容積率|都市計画|浸水|土砂|液状化)/u', $message);
$specific = chatMansionTermLooksSpecific($terms, $message);
$namesBuilding = function_exists('chatMansionMessageNamesBuilding')
    ? chatMansionMessageNamesBuilding($message, $terms)
    : null;
$intent = !$isLand && !empty($terms) && $specific && $namesBuilding;
line('    land/hazard request?       : ' . ($isLand ? 'YES' : 'no'));
line('    term looks specific?       : ' . ($specific ? 'YES' : 'no'));
line('    message NAMES a building?  : ' . ($namesBuilding === null ? '(helper missing - OLD CODE)' : ($namesBuilding ? 'YES' : 'no')));
line('    => isMansionLookupIntent   : ' . ($intent
        ? 'YES  (mansion lookup; hard not-found guard may fire)'
        : 'NO   (ordinary question -> normal AI answers it)'));
line('');
line('=== end of diagnostic ===');

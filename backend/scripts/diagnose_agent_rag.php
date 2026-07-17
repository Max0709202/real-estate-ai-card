<?php
/**
 * Read-only diagnostic for 担当者追加RAG (edit.php「登録済みRAG」) retrieval.
 * Does NOT modify anything. Shows exactly why a question does or does not pull
 * the agent's registered RAG into the chat prompt.
 *
 * Usage:
 *   php backend/scripts/diagnose_agent_rag.php                       # list cards holding RAG
 *   php backend/scripts/diagnose_agent_rag.php 12 "夏季休業はいつからですか?"
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat-rag-helper.php';

$cardId  = isset($argv[1]) ? (int)$argv[1] : 0;
$message = $argv[2] ?? '夏季休業はいつからですか?';

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function line($s = '') { echo $s . "\n"; }

line('==================================================================');
line('AGENT RAG (担当者追加RAG) DIAGNOSTIC');
line('==================================================================');

// [1] Which cards actually hold RAG rows -------------------------------------
line('');
line('[1] agent_custom_rag_items per business_card_id');
$rows = $db->query("SELECT business_card_id, COUNT(*) n, SUM(enabled = 1) enabled_n
                    FROM agent_custom_rag_items GROUP BY business_card_id
                    ORDER BY n DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    line('    >>> NO RAG ROWS AT ALL. Nothing was registered (or a different DB).');
    exit;
}
foreach ($rows as $r) {
    line(sprintf('    card_id=%-6s items=%-5s enabled=%s', $r['business_card_id'], $r['n'], $r['enabled_n']));
}
if ($cardId <= 0) {
    line('');
    line('Re-run with a card id, e.g.:');
    line('  php backend/scripts/diagnose_agent_rag.php ' . $rows[0]['business_card_id'] . ' "夏季休業はいつからですか?"');
    exit;
}

line('');
line('message : ' . $message);
line('card_id : ' . $cardId);

// [2] The registered rows for this card --------------------------------------
line('');
line('[2] registered RAG for this card (enabled only)');
$stmt = $db->prepare("SELECT id, title, LEFT(content, 60) AS head, enabled
                      FROM agent_custom_rag_items
                      WHERE business_card_id = ? AND enabled = 1
                      ORDER BY updated_at DESC LIMIT 10");
$stmt->execute([$cardId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    line(sprintf('    id=%-5s title=%-24s content=%s…', $r['id'], $r['title'], $r['head']));
}

// [3] Term extraction (this is where the old code broke) ---------------------
line('');
line('[3] search terms extracted from the message');
$legacy = [];
if (preg_match_all('/[一-龥ぁ-んァ-ンA-Za-z0-9０-９]{2,}/u', $message, $m)) $legacy = $m[0];
line('    OLD (buggy, whole sentence = 1 token) : ' . json_encode($legacy, JSON_UNESCAPED_UNICODE));
$tokens = function_exists('chatRagTokenizeMessage') ? chatRagTokenizeMessage($message) : null;
line('    NEW chatRagTokenizeMessage()          : '
    . ($tokens === null ? '(function missing — OLD FILE DEPLOYED)' : json_encode($tokens, JSON_UNESCAPED_UNICODE)));
line('    chatExtractSearchTerms() (keyword list): ' . json_encode(chatExtractSearchTerms($message), JSON_UNESCAPED_UNICODE));

// [4] The real retrieval -----------------------------------------------------
line('');
line('[4] getAgentCustomContextForChat() result');
$ctx = getAgentCustomContextForChat($db, $cardId, $message, 5);
$context = trim((string)($ctx['context'] ?? ''));
if ($context === '') {
    line('    >>> EMPTY. The agent RAG will NOT appear in the chat prompt.');
} else {
    line('    >>> CONTEXT BUILT (' . mb_strlen($context) . ' chars) — this text is injected into the prompt:');
    line('    ' . str_replace("\n", "\n    ", mb_substr($context, 0, 800)));
    line('');
    line('    sources: ' . count($ctx['sources'] ?? []));
}
line('');
line('NOTE: even a correct context here is discarded if send.php\'s マンション gate');
line('      answers first — getBotReplyWithOpenAI() (the only caller) never runs.');
line('');
line('=== end of diagnostic ===');

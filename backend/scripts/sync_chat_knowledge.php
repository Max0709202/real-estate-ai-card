<?php
/**
 * Sync local chatbot RAG knowledge from official/reference web sources.
 * Recommended cron: php backend/scripts/sync_chat_knowledge.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/openai-chat-helper.php';

$limit = isset($argv[1]) ? (int)$argv[1] : null;

try {
    $db = (new Database())->getConnection();
    $results = syncChatKnowledgeSources($db, $limit);
    $ok = 0;
    $failed = 0;
    foreach ($results as $result) {
        if ($result['status'] === 'ok') {
            $ok++;
            echo "OK {$result['source_key']} chunks={$result['chunks']} {$result['url']}\n";
        } else {
            $failed++;
            $error = isset($result['error']) ? $result['error'] : 'unknown error';
            echo "FAILED {$result['source_key']} {$result['url']} :: {$error}\n";
        }
    }
    echo "\nKnowledge sync complete. ok={$ok} failed={$failed}\n";
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Knowledge sync fatal error: " . $e->getMessage() . "\n");
    exit(1);
}

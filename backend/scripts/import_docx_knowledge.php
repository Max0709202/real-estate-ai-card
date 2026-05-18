<?php
/**
 * Import a DOCX archive into the local chatbot RAG cache.
 * Usage: php backend/scripts/import_docx_knowledge.php assets/blogs.docx
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/openai-chat-helper.php';

$docxPath = $argv[1] ?? (__DIR__ . '/../../assets/blogs.docx');
$docxPath = realpath($docxPath) ?: $docxPath;

function extractDocxParagraphs($path) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZipArchive is not available.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open DOCX file: ' . $path);
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('word/document.xml was not found in DOCX.');
    }
    $xml = preg_replace('/<w:tab\/?[^>]*>/u', ' ', $xml);
    $xml = preg_replace('/<\/w:p>/u', "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $lines = array_map('trim', explode("\n", $text));
    return array_values(array_filter($lines, function ($line) {
        return $line !== '';
    }));
}

function findDocxBodyStart($lines) {
    $lastToc = -1;
    foreach ($lines as $idx => $line) {
        if (preg_match('/^\d+\. .+（更新日: \d{4}-\d{2}-\d{2}）$/u', $line)) {
            $lastToc = $idx;
        }
    }
    return $lastToc >= 0 ? $lastToc + 1 : 0;
}

function parseDocxArticles($lines) {
    $start = findDocxBodyStart($lines);
    $articles = [];
    $i = $start;
    $count = count($lines);
    while ($i < $count - 2) {
        $title = $lines[$i];
        $dateLine = $lines[$i + 1] ?? '';
        if (!preg_match('/^更新日:\s*(\d{4}-\d{2}-\d{2})$/u', $dateLine, $dateMatch)) {
            $i++;
            continue;
        }
        $date = $dateMatch[1];
        $bodyParts = [];
        $i += 2;
        while ($i < $count) {
            $next = $lines[$i];
            $nextDate = $lines[$i + 1] ?? '';
            if (preg_match('/^更新日:\s*\d{4}-\d{2}-\d{2}$/u', $nextDate)) {
                break;
            }
            $bodyParts[] = $next;
            $i++;
        }
        $body = trim(implode("\n", $bodyParts));
        if ($title !== '' && mb_strlen($body) > 80) {
            $articles[] = [
                'title' => mb_substr($title, 0, 255),
                'date' => $date,
                'body' => $body,
            ];
        }
    }
    return $articles;
}

try {
    if (!is_file($docxPath)) {
        throw new RuntimeException('DOCX file not found: ' . $docxPath);
    }

    $db = (new Database())->getConnection();
    ensureChatRagTables($db);

    $lines = extractDocxParagraphs($docxPath);
    $articles = parseDocxArticles($lines);
    if (empty($articles)) {
        throw new RuntimeException('No article-like sections were found in DOCX.');
    }

    $sourceKey = 'company_blog_docx_archive';
    $sourceTitle = '自社確認済みブログ記事DOCX: 戸建てリノベINFO厳選記事集';
    $sourceUrl = 'file:assets/blogs.docx';

    $stmt = $db->prepare("INSERT INTO chat_knowledge_sources (source_key, title, url, source_type, priority, enabled, last_fetched_at, last_status)
                          VALUES (?, ?, ?, 'company_docx', 80, 1, CURRENT_TIMESTAMP, 'ok')
                          ON DUPLICATE KEY UPDATE title = VALUES(title), url = VALUES(url), source_type = 'company_docx', priority = 80, enabled = 1, last_fetched_at = CURRENT_TIMESTAMP, last_status = 'ok', last_error = NULL");
    $stmt->execute([$sourceKey, $sourceTitle, $sourceUrl]);
    $sourceId = (int)$db->lastInsertId();
    if ($sourceId === 0) {
        $stmt = $db->prepare('SELECT id FROM chat_knowledge_sources WHERE source_key = ?');
        $stmt->execute([$sourceKey]);
        $sourceId = (int)$stmt->fetchColumn();
    }

    $db->beginTransaction();
    $delete = $db->prepare('DELETE FROM chat_knowledge_chunks WHERE source_id = ?');
    $delete->execute([$sourceId]);
    $insert = $db->prepare('INSERT INTO chat_knowledge_chunks (source_id, chunk_index, title, url, content, content_hash) VALUES (?, ?, ?, ?, ?, ?)');

    $chunkIndex = 0;
    foreach ($articles as $idx => $article) {
        $baseTitle = $article['title'] . '（更新日: ' . $article['date'] . '）';
        $content = $baseTitle . "\n" . $article['body'];
        $chunks = chatSplitIntoChunks($content, 1600, 120);
        foreach ($chunks as $partIdx => $chunk) {
            $chunkTitle = $baseTitle;
            if (count($chunks) > 1) {
                $chunkTitle .= ' #' . ($partIdx + 1);
            }
            $url = $sourceUrl . '#article-' . ($idx + 1);
            $insert->execute([$sourceId, $chunkIndex, mb_substr($chunkTitle, 0, 255), $url, $chunk, hash('sha256', $chunk)]);
            $chunkIndex++;
        }
    }
    $db->commit();

    echo "DOCX_IMPORT=ok\n";
    echo "file={$docxPath}\n";
    echo "articles=" . count($articles) . "\n";
    echo "chunks={$chunkIndex}\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "DOCX_IMPORT=failed\n" . $e->getMessage() . "\n");
    exit(1);
}

<?php
/**
 * One-off migration: add name_norm/search_norm to mansion_buildings and backfill
 * existing rows so 表記ブレ-insensitive matching works without a full re-import.
 * Idempotent: safe to re-run. Recomputes all rows so normalization rule changes
 * (for example 2/２/Ⅱ suffix equivalence) also reach existing data.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/chat-public-data-helper.php';

$db = (new Database())->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = $db->query("SHOW COLUMNS FROM mansion_buildings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('name_norm', $cols, true)) {
    echo "Adding column name_norm...\n";
    $db->exec("ALTER TABLE mansion_buildings ADD COLUMN name_norm VARCHAR(255) NULL");
}
if (!in_array('search_norm', $cols, true)) {
    echo "Adding column search_norm...\n";
    $db->exec("ALTER TABLE mansion_buildings ADD COLUMN search_norm TEXT NULL");
}

$total = (int)$db->query("SELECT COUNT(*) FROM mansion_buildings")->fetchColumn();
echo "Rows to backfill: {$total}\n";

$upd = $db->prepare("UPDATE mansion_buildings SET name_norm = :nn, search_norm = :sn WHERE id = :id");
$done = 0;
while (true) {
    $batch = $db->prepare("SELECT id, building_name, search_text FROM mansion_buildings WHERE id > ? ORDER BY id LIMIT 3000");
    $batch->execute([$lastId ?? 0]);
    $batch = $batch->fetchAll(PDO::FETCH_ASSOC);
    if (!$batch) break;
    $db->beginTransaction();
    foreach ($batch as $r) {
        $upd->execute([
            ':nn' => chatMansionNormalizeText($r['building_name'] ?? ''),
            ':sn' => chatMansionNormalizeText($r['search_text'] ?? ''),
            ':id' => $r['id'],
        ]);
        $lastId = (int)$r['id'];
    }
    $db->commit();
    $done += count($batch);
    echo "  backfilled {$done}/{$total}\n";
}

// Add index after backfill (idempotent).
$idx = $db->query("SHOW INDEX FROM mansion_buildings WHERE Key_name = 'idx_mansion_name_norm'")->fetchAll();
if (!$idx) {
    echo "Adding index idx_mansion_name_norm...\n";
    $db->exec("ALTER TABLE mansion_buildings ADD INDEX idx_mansion_name_norm (name_norm)");
}
$remain = (int)$db->query("SELECT COUNT(*) FROM mansion_buildings WHERE name_norm IS NULL")->fetchColumn();
echo "DONE. remaining NULL name_norm: {$remain}\n";

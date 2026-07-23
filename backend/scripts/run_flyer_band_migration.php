<?php
/**
 * business_cards.flyer_band 列を追加するマイグレーション適用スクリプト。
 * 自社帯（販売図面）機能で使用する。冪等: 既に列があれば SKIP する。
 *
 * 実行方法（いずれか）:
 *   - CLI:     php backend/scripts/run_flyer_band_migration.php
 *   - ブラウザ: /backend/scripts/run_flyer_band_migration.php にアクセス
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain; charset=UTF-8');
echo "=== flyer_band Migration ===\n";

try {
    $database = new Database();
    $db = $database->getConnection();

    // 既存列の確認（冪等化）
    $stmt = $db->query("SHOW COLUMNS FROM business_cards LIKE 'flyer_band'");
    if ($stmt && $stmt->rowCount() > 0) {
        echo "SKIP: business_cards.flyer_band は既に存在します。\n";
    } else {
        $db->exec("ALTER TABLE business_cards ADD COLUMN flyer_band VARCHAR(500) NULL AFTER company_logo");
        echo "OK: business_cards.flyer_band を追加しました。\n";
    }

    // 結果確認
    $check = $db->query("SHOW COLUMNS FROM business_cards LIKE 'flyer_band'");
    $exists = $check && $check->rowCount() > 0;
    echo "\nColumn status:\n";
    echo "  business_cards.flyer_band: " . ($exists ? "✓ exists" : "✗ missing") . "\n";

    echo "\n=== Done ===\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}

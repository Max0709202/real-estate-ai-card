<?php
/**
 * 既存・ＥＲＡ会員（月額請求なし）の利用期限を business_cards.usage_expires_at へ復元する。
 *
 * 管理画面で「振込予定 → 振込済」に変更する際に入力した利用期限は、月額請求が無いユーザーでは
 * 保存先が無く破棄されていた。そのため利用期限を過ぎても名刺が公開されたままになっていた。
 * 入力値そのものは admin_change_logs の説明文に「利用期限: YYYY-MM-DD」として残っているため、
 * そこから読み戻して usage_expires_at に設定する。
 *
 * 対象は「月額請求が無い（user_type='existing' もしくは ＥＲＡ会員）」かつ
 * 「usage_expires_at が未設定」の名刺のみ。既に設定済みの名刺は上書きしない。
 *
 * 事前に backend/database/migrations/add_usage_expires_at_to_business_cards.sql を適用しておくこと。
 *
 * 使い方:
 *   php backend/scripts/backfill_usage_expires_at.php --dry     # 変更せず対象を確認
 *   php backend/scripts/backfill_usage_expires_at.php           # 実際に反映する
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$dryRun = in_array('--dry', array_slice($argv, 1), true);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--dry') {
        fwrite(STDERR, "unknown option: {$arg}\n");
        exit(1);
    }
}

$db = (new Database())->getConnection();

// 月額請求が無く、利用期限が未設定のまま入金済みになっている名刺を集める。
$stmt = $db->prepare("
    SELECT bc.id, bc.url_slug, u.email, u.user_type, COALESCE(u.is_era_member, 0) AS is_era_member
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    WHERE bc.usage_expires_at IS NULL
      AND COALESCE(bc.payment_status, 'UNUSED') IN ('CR', 'BANK_PAID', 'ST')
      AND NOT (u.user_type = 'new' AND COALESCE(u.is_era_member, 0) = 0)
    ORDER BY bc.id
");
$stmt->execute();
$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "対象候補: " . count($cards) . " 件" . ($dryRun ? "（--dry: 書き込みなし）" : "") . "\n";

// 直近の「利用期限: YYYY-MM-DD」を含む入金状況変更ログを引く。
// 説明文には "URL: {url_slug}, 利用期限: YYYY-MM-DD" の形で残る。url_slug は名刺ごとに一意なので
// これで突き合わせる（target_type / change_type は記録側の値が揺れているため条件に使わない）。
$logStmt = $db->prepare("
    SELECT description
    FROM admin_change_logs
    WHERE description LIKE '%利用期限: %'
      AND description LIKE ?
    ORDER BY changed_at DESC, id DESC
    LIMIT 1
");
$updateStmt = $db->prepare("UPDATE business_cards SET usage_expires_at = ? WHERE id = ? AND usage_expires_at IS NULL");

$updated = 0;
$notFound = 0;

foreach ($cards as $card) {
    $logStmt->execute(['%URL: ' . $card['url_slug'] . ',%']);
    $description = $logStmt->fetchColumn();

    if (!$description || !preg_match('/利用期限:\s*(\d{4}-\d{2}-\d{2})/u', $description, $m)) {
        $notFound++;
        continue;
    }

    $expirationDate = $m[1];
    $label = sprintf('business_card_id=%d slug=%s email=%s 利用期限=%s',
        $card['id'], $card['url_slug'], $card['email'], $expirationDate);

    if ($dryRun) {
        echo "  [dry] {$label}\n";
        $updated++;
        continue;
    }

    $updateStmt->execute([$expirationDate, $card['id']]);
    if ($updateStmt->rowCount() > 0) {
        echo "  [set] {$label}\n";
        $updated++;
    }
}

echo "復元: {$updated} 件 / ログに利用期限の記録が無く復元できず: {$notFound} 件\n";
if ($notFound > 0) {
    echo "※ 復元できなかった分は、管理画面で「振込済」を付け直して利用期限を入力してください。\n";
}

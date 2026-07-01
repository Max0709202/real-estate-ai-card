<?php
/**
 * 営業向けメール通知の送信処理（バッチ）。
 * cron で5分毎に実行する想定（エックスサーバーは毎分cronを非推奨のため）。
 * crontab 例（分フィールドに 5分間隔を指定）:
 *   （分）/5 （時）* （日）* （月）* （曜）*  →  php .../process-notification-queue.php
 *
 * 送信期限（最後の操作から NOTIFY_WAIT_SECONDS 秒）を過ぎた pending ジョブを
 * 1件1通でまとめて送信する。詳細ロジックは notification-helper.php を参照。
 * ※メールは元来「数分の遅延」が前提のため、5分間隔でも要望（通知の集約）は満たせる。
 *   待機時間内に届いた同種操作は1通に集約され、期限到来後の次回cronで送信される。
 */
// crontab 設定行（コピー用）:
//   [アスタリスク]/5 * * * * /usr/bin/php /home/xs013436/ai-fcard.com/public_html/backend/api/cron/process-notification-queue.php
//   ※先頭は「アスタリスク/5」（5分毎）。例: */5 を分フィールドに指定。
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/notification-helper.php';
require_once __DIR__ . '/../../includes/customer-notification-helper.php';

// 1回の実行で送る上限（SMTP負荷・取りこぼし防止のバランス）。
$maxPerRun = (int)(getenv('NOTIFY_MAX_PER_RUN') ?: 50);

try {
    $db = (new Database())->getConnection();
    // 担当（営業）向け通知
    $result = notifyFlushDue($db, $maxPerRun);
    echo "Notification flush (agent): {$result['sent']} sent, {$result['failed']} failed\n";
    // 顧客向け通知（物件追加 / 担当連絡）
    $cust = customerNotifyFlushDue($db, $maxPerRun);
    echo "Notification flush (customer): {$cust['sent']} sent, {$cust['failed']} failed\n";
    exit(0);
} catch (Exception $e) {
    error_log('Notification Queue Processor Error: ' . $e->getMessage());
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

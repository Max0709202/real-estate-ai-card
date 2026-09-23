<?php
/**
 * 内見リマインドメールの送信処理（バッチ）。
 * 内見前日18:00・当日08:00（日本時間）に、予約済みのリマインドを送信する。
 *
 * 通常は process-notification-queue.php（5分毎）に相乗りしているため、このcronは任意。
 * 送信時刻ちょうどに近づけたい場合に、より短い間隔で別途実行する。
 * crontab 例（1分毎）:
 *   （アスタリスク）/1 * * * * /usr/bin/php /path/to/backend/api/cron/process-viewing-reminders.php
 *
 * 同じ回を二重送信しないよう、予約行を1件ずつ確保してから送る。
 * 送信直前に案件の状態と対象日時を取り直し、キャンセル・日時変更・内見不可の案件には送らない。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/customer-notification-helper.php';
require_once __DIR__ . '/../../includes/property-email-helper.php';
require_once __DIR__ . '/../../includes/viewing-reminder-helper.php';

$maxPerRun = (int)(getenv('VIEWING_REMINDER_MAX_PER_RUN') ?: 50);

try {
    $db = (new Database())->getConnection();
    $r = viewingReminderFlushDue($db, $maxPerRun);
    echo "Viewing reminder: {$r['sent']} sent, {$r['cancelled']} cancelled, {$r['failed']} failed\n";
    exit(0);
} catch (Exception $e) {
    error_log('Viewing Reminder Processor Error: ' . $e->getMessage());
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

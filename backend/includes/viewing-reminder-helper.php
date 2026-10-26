<?php
/**
 * 内見リマインドメール（M12 前日18:00 / M13 当日08:00）の予約と送信（仕様 §7）。
 * -------------------------------------------------------------
 * 目的は、買主が内見日時と待ち合わせ場所・時間を忘れずに確認できるようにすること。
 * URLだけの案内にせず、予定の内容をメール本文に表示する（文面は viewing-email-helper.php）。
 *
 * 送信時刻は「24時間前」「2時間前」ではなく、内見前日18:00と当日08:00の固定時刻。
 * すべて日本時間（Asia/Tokyo）で計算する。
 *
 * 送信対象は「最新の内見日時が確定し、その日時のM06が買主へ送信成功している案件」。
 * 予約は property_viewing_reminders に持ち、
 *   案件ID × 確定日時の版（slot_version）× 送信回（kind）× 宛先
 * で一意にすることで、連打・再実行・M06の再送があっても同じ回を二重送信しない。
 * 待ち合わせ案内だけを更新しても、送信済みの回は再送しない（版は変わらないため）。
 */

require_once __DIR__ . '/viewing-helper.php';
require_once __DIR__ . '/viewing-email-helper.php';

if (!function_exists('viewingReminderSendTimes')) {
    /**
     * 確定日時から、前日18:00・当日08:00の送信予定時刻を求める。
     * すでに過ぎた回は予約しない（過ぎた回を後から送らず、M06で確定内容を案内する）。
     *
     * @return array<string, DateTimeImmutable> kind => 送信予定時刻
     */
    function viewingReminderSendTimes(string $confirmedStartAt, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: viewingNow();
        try {
            $start = new DateTimeImmutable($confirmedStartAt, viewingTz());
        } catch (Throwable $e) {
            return [];
        }
        $times = [
            'prev_day' => $start->modify('-1 day')->setTime(18, 0),
            'same_day' => $start->setTime(8, 0),
        ];
        // 送信時刻を過ぎている回、および内見開始後になる回は予約しない。
        return array_filter($times, fn($t) => $t > $now && $t < $start);
    }
}

if (!function_exists('viewingReminderSchedule')) {
    /**
     * M06 の送信成功後に、未到来の送信時刻だけを予約する。
     * 宛先は買主と担当エージェント（仕様 §7 送信先の補足案）。
     *
     * @param array $recipients [['email'=>..,'role'=>'buyer'|'agent'], ...]
     * @return int 予約した件数
     */
    function viewingReminderSchedule(PDO $db, array $case, array $recipients): int
    {
        viewingEnsureTables($db);
        $start = trim((string)($case['confirmed_start_at'] ?? ''));
        if ($start === '') return 0;
        $version = (int)$case['confirmed_version'];
        $times = viewingReminderSendTimes($start);
        if (!$times || !$recipients) return 0;

        $sql = "INSERT INTO property_viewing_reminders
                  (viewing_id, slot_version, kind, target_start_at, send_at, recipient, recipient_role, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ON DUPLICATE KEY UPDATE
                  send_at = IF(status = 'scheduled', VALUES(send_at), send_at),
                  target_start_at = IF(status = 'scheduled', VALUES(target_start_at), target_start_at)";
        $stmt = $db->prepare($sql);
        $n = 0;
        foreach ($times as $kind => $at) {
            foreach ($recipients as $r) {
                $email = trim((string)($r['email'] ?? ''));
                if ($email === '') continue;
                $stmt->execute([
                    (int)$case['id'], $version, $kind, $start,
                    $at->format('Y-m-d H:i:s'), $email, ($r['role'] ?? 'buyer') === 'agent' ? 'agent' : 'buyer',
                ]);
                $n++;
            }
        }
        viewingLogEvent($db, (int)$case['id'], 'reminder_scheduled', [
            'detail' => '版' . $version . '／' . implode('・', array_keys($times)) . ' を予約',
        ]);
        return $n;
    }
}

if (!function_exists('viewingReminderCancelAll')) {
    /**
     * 未送信のリマインドをすべて取り消す。
     * 日時変更の候補送信・キャンセル・内見不可のときに呼ぶ。
     */
    function viewingReminderCancelAll(PDO $db, int $viewingId, string $reason = ''): int
    {
        try {
            viewingEnsureTables($db);
            $stmt = $db->prepare("UPDATE property_viewing_reminders SET status = 'cancelled', error_message = ?
                                  WHERE viewing_id = ? AND status = 'scheduled'");
            $stmt->execute([mb_substr($reason, 0, 500) ?: null, $viewingId]);
            $n = $stmt->rowCount();
            if ($n > 0) viewingLogEvent($db, $viewingId, 'reminder_cancelled', ['detail' => $reason ?: null]);
            return $n;
        } catch (Throwable $e) {
            error_log('viewingReminderCancelAll error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('viewingReminderSendable')) {
    /**
     * 送信直前の再確認。状態と対象日時が今も送ってよいものかを判定する。
     * 送信待ちの処理を取り消すだけでなく、メール送信の直前にもここを通す。
     *
     * @return string '' なら送信可。空でなければ送らない理由。
     */
    function viewingReminderSendable(PDO $db, array $job, ?array $case = null): string
    {
        $case = $case ?: viewingLoad($db, (int)$job['viewing_id']);
        if (!$case) return '案件が見つかりません';

        // キャンセル済み・内見不可には送信しない（売主側への連絡がまだでも対象から外す）。
        if (in_array((string)$case['status'], ['cancelled', 'unavailable'], true)) return '案件が' . (viewingStatusDefs()[$case['status']]['label'] ?? '終了');
        // 日時変更の調整中は送信しない。
        if ((int)$case['is_rescheduling'] === 1 && (string)$case['status'] !== 'buyer_notified') return '日時変更の調整中';
        // M06 未送信には送信しない。
        if ((string)$case['status'] !== 'buyer_notified' || empty($case['buyer_notified_at'])) return '買主への確定連絡（M06）が未送信';
        // 古い確定日時の版は送らない。
        if ((int)$job['slot_version'] !== (int)$case['confirmed_version']) return '確定日時が変更されたため対象外';
        if ((string)$job['target_start_at'] !== (string)$case['confirmed_start_at']) return '確定日時が変更されたため対象外';

        // 内見開始後には送信しない。
        try {
            if (new DateTimeImmutable((string)$case['confirmed_start_at'], viewingTz()) <= viewingNow()) return '内見開始時刻を過ぎています';
        } catch (Throwable $e) {
            return '確定日時を判定できません';
        }
        // 前日分は当日8時以降、当日分は内見開始以降に再送しない。
        if ($job['kind'] === 'prev_day') {
            try {
                $sameDay8 = (new DateTimeImmutable((string)$case['confirmed_start_at'], viewingTz()))->setTime(8, 0);
                if (viewingNow() >= $sameDay8) return '前日分の送信時刻を過ぎています';
            } catch (Throwable $e) {
                return '確定日時を判定できません';
            }
        }
        return '';
    }
}

if (!function_exists('viewingReminderFlushDue')) {
    /**
     * 送信時刻を過ぎた予約を送信する（cron から呼ぶ）。
     * 1行＝1宛先＝1通。送信直前に最新の案件状態を取り直し、
     * 古い日時・古い宛先・古い待ち合わせ案内を送らないようにする。
     *
     * @return array{sent:int, cancelled:int, failed:int}
     */
    function viewingReminderFlushDue(PDO $db, int $limit = 50): array
    {
        $out = ['sent' => 0, 'cancelled' => 0, 'failed' => 0];
        viewingEnsureTables($db);

        $stmt = $db->prepare("SELECT * FROM property_viewing_reminders
                              WHERE status = 'scheduled' AND send_at <= ?
                              ORDER BY send_at ASC LIMIT " . max(1, min(200, $limit)));
        $stmt->execute([viewingNow()->format('Y-m-d H:i:s')]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($jobs as $job) {
            // 同じ回を別プロセスが拾わないよう、まず自分のものにする。
            $claim = $db->prepare("UPDATE property_viewing_reminders
                                   SET attempts = attempts + 1 WHERE id = ? AND status = 'scheduled'");
            $claim->execute([(int)$job['id']]);
            if ($claim->rowCount() === 0) continue;

            $case = viewingLoad($db, (int)$job['viewing_id']);
            $reason = viewingReminderSendable($db, $job, $case);
            if ($reason !== '') {
                $db->prepare("UPDATE property_viewing_reminders SET status = 'cancelled', error_message = ? WHERE id = ?")
                   ->execute([mb_substr($reason, 0, 500), (int)$job['id']]);
                $out['cancelled']++;
                continue;
            }

            $code = $job['kind'] === 'prev_day' ? 'M12' : 'M13';
            $res = viewingMailSend($db, $case, $code, [], [(string)$job['recipient']]);
            if ($res['sent'] > 0) {
                $db->prepare("UPDATE property_viewing_reminders SET status = 'sent', sent_at = ?, error_message = NULL WHERE id = ?")
                   ->execute([viewingNow()->format('Y-m-d H:i:s'), (int)$job['id']]);
                $out['sent']++;
            } else {
                // 送れなかったものは「送信済み」にしない。担当者画面に失敗として表示する。
                $db->prepare("UPDATE property_viewing_reminders SET status = 'failed', error_message = ? WHERE id = ?")
                   ->execute(['メールを送信できませんでした。', (int)$job['id']]);
                $out['failed']++;
            }
        }
        return $out;
    }
}

/* ──────────────────────────────────────────────────────────
 * 売主仲介会社への内見調整リマインド（★2026/10/2 追加ご依頼）
 * 売主仲介会社へ調整依頼（M03／M08）を送ってから回答がない場合、
 *   48時間後・72時間後・96時間後に売主仲介会社へリマインド（R01〜R03）を送り、
 *   同時に不動産AI名刺所有者（担当エージェント）へ通知（R11〜R13）する。
 * 売主側が回答した（状態が「売主側回答待ち」でなくなった）時点で以降の回は送らない。
 * 調整依頼を送り直した場合は、未送信の回を取り消し、新しい依頼の送信時刻から数え直す。
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingSellerReminderHours')) {
    /** 送信回 => 調整依頼の送信からの経過時間（時間）。 */
    function viewingSellerReminderHours(): array
    {
        return [1 => 48, 2 => 72, 3 => 96];
    }
}

if (!function_exists('viewingSellerReminderEnsureTable')) {
    function viewingSellerReminderEnsureTable(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        // 調整依頼1回（request_at）× 送信回（seq）で1行＝1回だけ送る。
        $db->exec("CREATE TABLE IF NOT EXISTS property_viewing_seller_reminders (
          id INT AUTO_INCREMENT PRIMARY KEY,
          viewing_id INT NOT NULL,
          request_at DATETIME NOT NULL,
          seq TINYINT NOT NULL,
          send_at DATETIME NOT NULL,
          status ENUM('scheduled','sending','sent','cancelled','failed') NOT NULL DEFAULT 'scheduled',
          attempts INT NOT NULL DEFAULT 0,
          sent_at DATETIME NULL DEFAULT NULL,
          error_message VARCHAR(500) NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uk_property_viewing_seller_reminders (viewing_id, request_at, seq),
          INDEX idx_property_viewing_seller_reminders_due (status, send_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('viewingSellerReminderSchedule')) {
    /**
     * 売主仲介会社への調整依頼（M03／M08）の送信成功後に呼ぶ。
     * 以前の依頼に対する未送信の回は取り消し、今回の送信時刻から48・72・96時間後を予約する。
     */
    function viewingSellerReminderSchedule(PDO $db, int $viewingId): int
    {
        try {
            viewingSellerReminderEnsureTable($db);
            $db->prepare("UPDATE property_viewing_seller_reminders SET status = 'cancelled', error_message = ?
                          WHERE viewing_id = ? AND status = 'scheduled'")
               ->execute(['調整依頼を送り直したため取り消し', $viewingId]);

            $now = viewingNow();
            $ins = $db->prepare("INSERT IGNORE INTO property_viewing_seller_reminders (viewing_id, request_at, seq, send_at)
                                 VALUES (?, ?, ?, ?)");
            $n = 0;
            foreach (viewingSellerReminderHours() as $seq => $hours) {
                $ins->execute([$viewingId, $now->format('Y-m-d H:i:s'), $seq, $now->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s')]);
                $n += $ins->rowCount();
            }
            viewingLogEvent($db, $viewingId, 'seller_reminder_scheduled', ['detail' => '48・72・96時間後の売主側リマインドを予約']);
            return $n;
        } catch (Throwable $e) {
            error_log('viewingSellerReminderSchedule error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('viewingSellerReminderFlushDue')) {
    /**
     * 送信時刻を過ぎた売主側リマインドを送る（cron から呼ぶ）。
     * 送信直前に案件の状態を取り直し、回答済み・キャンセル済みの案件には送らない。
     * 売主仲介会社へ送れた回だけ、担当エージェントへ通知する（「送信しました」と事実どおり伝えるため）。
     *
     * @return array{sent:int, cancelled:int, failed:int}
     */
    function viewingSellerReminderFlushDue(PDO $db, int $limit = 50): array
    {
        $out = ['sent' => 0, 'cancelled' => 0, 'failed' => 0];
        viewingEnsureTables($db);
        viewingSellerReminderEnsureTable($db);

        $stmt = $db->prepare("SELECT * FROM property_viewing_seller_reminders
                              WHERE status = 'scheduled' AND send_at <= ?
                              ORDER BY send_at ASC LIMIT " . max(1, min(200, $limit)));
        $stmt->execute([viewingNow()->format('Y-m-d H:i:s')]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mark = $db->prepare("UPDATE property_viewing_seller_reminders
                              SET status = ?, sent_at = ?, error_message = ? WHERE id = ?");
        foreach ($jobs as $job) {
            // 2つのcron（process-notification-queue / process-viewing-reminders）が同時に動いても
            // 同じ回を二重送信しないよう、送信中（sending）へ切り替えられた1プロセスだけが送る。
            $claim = $db->prepare("UPDATE property_viewing_seller_reminders
                                   SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'scheduled'");
            $claim->execute([(int)$job['id']]);
            if ($claim->rowCount() === 0) continue;

            $case = viewingLoad($db, (int)$job['viewing_id']);
            $reason = '';
            if (!$case) {
                $reason = '案件が見つかりません';
            } elseif ((string)$case['status'] !== 'seller_pending') {
                $reason = '売主側の回答待ちではないため送信しません（' . (viewingStatusDefs()[$case['status']]['label'] ?? $case['status']) . '）';
            } else {
                // 停止していた等で後の回もすでに送信時刻を過ぎている場合は、後の回だけを送る（続けて何通も送らない）。
                $later = $db->prepare("SELECT COUNT(*) FROM property_viewing_seller_reminders
                                       WHERE viewing_id = ? AND request_at = ? AND seq > ? AND status = 'scheduled' AND send_at <= ?");
                $later->execute([(int)$job['viewing_id'], $job['request_at'], (int)$job['seq'], viewingNow()->format('Y-m-d H:i:s')]);
                if ((int)$later->fetchColumn() > 0) $reason = '後の回の送信時刻を過ぎているため省略';
            }
            if ($reason !== '') {
                $mark->execute(['cancelled', null, mb_substr($reason, 0, 500), (int)$job['id']]);
                $out['cancelled']++;
                continue;
            }

            $seq = (int)$job['seq'];
            $res = viewingMailSend($db, $case, sprintf('R%02d', $seq));
            if ($res['sent'] > 0) {
                $mark->execute(['sent', viewingNow()->format('Y-m-d H:i:s'), null, (int)$job['id']]);
                $out['sent']++;
                viewingMailSend($db, $case, sprintf('R%02d', 10 + $seq));
            } else {
                $mark->execute(['failed', null, 'メールを送信できませんでした。', (int)$job['id']]);
                $out['failed']++;
            }
        }
        return $out;
    }
}

if (!function_exists('viewingReminderList')) {
    /** 担当者画面に出すリマインドの予約状況。 */
    function viewingReminderList(PDO $db, int $viewingId): array
    {
        viewingEnsureTables($db);
        $stmt = $db->prepare("SELECT kind, target_start_at, send_at, recipient, recipient_role, status, sent_at, error_message
                              FROM property_viewing_reminders WHERE viewing_id = ? ORDER BY send_at ASC");
        $stmt->execute([$viewingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

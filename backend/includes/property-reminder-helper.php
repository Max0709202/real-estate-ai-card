<?php
/**
 * 物件提案の「未閲覧リマインドメール」（2026/9/1 修正依頼）。
 * -------------------------------------------------------------
 * 担当が物件を提案したあと、お客様が物件情報をまだご覧になっていない場合に、
 * 12時間ごと・最大8回（＝4日間）ご確認をお願いするメールを送る。
 *
 * ・サイクルの開始／リセット
 *     担当が物件を提案する（customerNotifyDispatch(..., 'property')）たびに
 *     propertyReminderSchedule() を呼び、1通目を12時間後に予約する。
 *     新しい提案が入ったら回数を0に戻して仕切り直す（提案ごとに最大8回）。
 *
 * ・未閲覧の判定
 *     property-unread-helper.php の未読件数と同じ条件で数える（propertyReminderUnviewedCount）。
 *     ＝ 担当が提案した物件のうち、顧客が物件選定を開いてもおらず、詳細も見ていないもの。
 *     顧客が物件選定を開く / 物件詳細を開くと未読は0になるため、送信時点の判定だけで停止できる。
 *     （閲覧側にフックを足さないので、閲覧経路が増えても取りこぼしが起きない）
 *
 * ・停止条件
 *     ①未閲覧が0件になった（viewed） ②8回送り切った（done）
 *     ③案件が削除された／宛先メールが無くなった／送信に繰り返し失敗した（stopped）
 *
 * 実際の配信は cron（process-notification-queue.php）から propertyReminderFlushDue() を呼ぶ。
 * 顧客向け通知（customer-notification-helper.php）と同じ宛先解決・同じ本文レイアウトを使う。
 */

require_once __DIR__ . '/functions.php';                   // sendEmail()
require_once __DIR__ . '/customer-notification-helper.php'; // 宛先解決・件名プレフィックス・リンク組み立て
require_once __DIR__ . '/property-unread-helper.php';       // property_customer_reads（物件選定の既読位置）
require_once __DIR__ . '/property-view-helper.php';         // propertyViewTokenFor()
require_once __DIR__ . '/property-email-helper.php';        // propertyEmailCompose()

if (!defined('PROPERTY_REMINDER_INTERVAL_HOURS')) {
    // 送信間隔（時間）。依頼は12時間ごと。
    define('PROPERTY_REMINDER_INTERVAL_HOURS', (int)(getenv('PROPERTY_REMINDER_INTERVAL_HOURS') ?: 12));
}
if (!defined('PROPERTY_REMINDER_MAX_COUNT')) {
    // 最大送信回数。依頼は8回（12時間 × 8 = 4日間）。
    define('PROPERTY_REMINDER_MAX_COUNT', (int)(getenv('PROPERTY_REMINDER_MAX_COUNT') ?: 8));
}
if (!defined('PROPERTY_REMINDER_MAX_FAILURES')) {
    // 連続送信失敗の上限。これを超えたら打ち切る（無限リトライ防止）。
    define('PROPERTY_REMINDER_MAX_FAILURES', (int)(getenv('PROPERTY_REMINDER_MAX_FAILURES') ?: 3));
}
if (!defined('PROPERTY_REMINDER_RETRY_MINUTES')) {
    // 送信に失敗したときの再試行までの待ち時間（分）。回数は消費しない。
    define('PROPERTY_REMINDER_RETRY_MINUTES', (int)(getenv('PROPERTY_REMINDER_RETRY_MINUTES') ?: 60));
}

if (!function_exists('propertyReminderEnsureTable')) {
    /** テーブルが無ければ作成（冪等）。 */
    function propertyReminderEnsureTable(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS property_view_reminders (
          session_id CHAR(36) NOT NULL PRIMARY KEY,
          business_card_id INT NOT NULL DEFAULT 0,
          recipient_email VARCHAR(255) NOT NULL DEFAULT '',
          agent_name VARCHAR(255) NULL DEFAULT NULL,
          card_slug VARCHAR(255) NULL DEFAULT NULL,
          sent_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
          fail_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
          status ENUM('active','viewed','done','stopped') NOT NULL DEFAULT 'active',
          started_at TIMESTAMP NULL DEFAULT NULL,
          next_send_at TIMESTAMP NULL DEFAULT NULL,
          last_sent_at TIMESTAMP NULL DEFAULT NULL,
          stopped_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_property_view_reminders_due (status, next_send_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('propertyReminderSchedule')) {
    /**
     * リマインドのサイクルを開始（既にあればリセット）する。担当が物件を提案した時点で呼ぶ。
     * 1通目は提案から PROPERTY_REMINDER_INTERVAL_HOURS 時間後。
     * 宛先メールが無い顧客には送れないため、その場合は何もしない。
     *
     * 通知が業務処理を壊さないよう、例外は内部で握りつぶしログのみ。
     */
    function propertyReminderSchedule(PDO $db, string $sessionId): bool
    {
        try {
            $sessionId = trim($sessionId);
            if ($sessionId === '') return false;
            $r = customerNotifyResolveRecipient($db, $sessionId);
            if ($r === null) return false; // 宛先が無ければリマインドも送れない。

            propertyReminderEnsureTable($db);
            $hours = (int)PROPERTY_REMINDER_INTERVAL_HOURS;
            $stmt = $db->prepare(
                "INSERT INTO property_view_reminders
                   (session_id, business_card_id, recipient_email, agent_name, card_slug,
                    sent_count, fail_count, status, started_at, next_send_at, last_sent_at, stopped_at)
                 VALUES
                   (?, ?, ?, ?, ?, 0, 0, 'active', NOW(), DATE_ADD(NOW(), INTERVAL {$hours} HOUR), NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                   business_card_id = VALUES(business_card_id),
                   recipient_email  = VALUES(recipient_email),
                   agent_name       = VALUES(agent_name),
                   card_slug        = VALUES(card_slug),
                   sent_count   = 0,
                   fail_count   = 0,
                   started_at   = NOW(),
                   next_send_at = VALUES(next_send_at),
                   last_sent_at = NULL,
                   stopped_at   = NULL,
                   status       = 'active'"
            );
            $stmt->execute([
                $sessionId,
                (int)$r['business_card_id'],
                (string)$r['recipient_email'],
                (string)$r['agent_name'],
                (string)$r['card_slug'],
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('propertyReminderSchedule error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('propertyReminderStop')) {
    /**
     * リマインドを止める（送信中のサイクルのみ）。
     * 通常は送信時の未閲覧判定で自動停止するため、明示的に止めたい場合にだけ使う。
     *
     * @param string $status 'viewed' | 'done' | 'stopped'
     */
    function propertyReminderStop(PDO $db, string $sessionId, string $status = 'stopped'): int
    {
        try {
            $sessionId = trim($sessionId);
            if ($sessionId === '' || !in_array($status, ['viewed', 'done', 'stopped'], true)) return 0;
            propertyReminderEnsureTable($db);
            $stmt = $db->prepare(
                "UPDATE property_view_reminders
                 SET status = ?, next_send_at = NULL, stopped_at = NOW()
                 WHERE session_id = ? AND status = 'active'"
            );
            $stmt->execute([$status, $sessionId]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('propertyReminderStop error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('propertyReminderSubject')) {
    /**
     * 回数ごとの件名（依頼書のとおり2種類）。
     *   1〜4回目: 物件をご提案いたしましたのでご確認ください
     *   5〜8回目: まだご覧になっていない物件があります
     */
    function propertyReminderSubject(int $seq): string
    {
        $body = $seq <= 4
            ? '物件をご提案いたしましたのでご確認ください'
            : 'まだご覧になっていない物件があります';
        return NOTIFY_SUBJECT_PREFIX . $body;
    }
}

if (!function_exists('propertyReminderLeadText')) {
    /** 回数ごとの本文（依頼書の文案どおり。1〜8回目）。範囲外は空文字。 */
    function propertyReminderLeadText(int $seq): string
    {
        $texts = [
            1 => "物件をご提案しておりますが、もうご覧いただけましたでしょうか？\n"
               . "お客様のご希望条件をもとにお選びした物件です。\n"
               . "ぜひ一度、物件情報をご覧ください。\n"
               . "気になる点やご希望などございましたら、お気軽にお聞かせください。",
            2 => "物件をご提案しておりますが、もうご覧いただけましたでしょうか？\n"
               . "お時間のあるときに、ぜひ一度ご確認ください。\n"
               . "「気になる」「ちょっと違う」など、簡単なご感想だけでも今後の物件選びに役立ちますのでご感想もお待ちしております。",
            3 => "ご提案中の物件、ご覧いただけましたでしょうか？\n"
               . "物件探しでは、実際にいくつかの物件をご覧いただくことで、ご希望の条件がより明確になってきます。\n"
               . "ぜひ今回の物件もチェックしてみてください。",
            4 => "まだご確認いただいていない物件があります。\n"
               . "お客様のご希望条件に近い物件としてご提案していますので、ぜひ一度ご覧ください。\n"
               . "ご覧いただくだけでも大丈夫です。",
            5 => "ご提案した物件をまだご覧になっていないようです。\n"
               . "「この物件は好き」「ここは希望と違う」など、物件をご覧いただくほど、次のご提案をよりお客様の好みに近づけることができます。\n"
               . "ぜひチェックしてみてください。",
            6 => "ご提案中の物件を、念のため再度ご案内いたします。\n"
               . "物件情報は状況が変わることもありますので、気になる物件がございましたら、お早めにご確認ください。",
            7 => "ご提案した物件をまだご覧になっていないようでしたので、見逃し防止のため再度ご案内いたします。\n"
               . "お時間のある際に、ぜひ物件情報をご確認ください。",
            8 => "今回ご提案した物件について、最後のご案内です。\n"
               . "まだご覧になっていない物件がございますので、よろしければ一度ご確認ください。\n"
               . "今回の物件がご希望と違っていても問題ありません。\n"
               . "ご覧いただいた結果をもとに、今後さらにご希望に近い物件をご提案してまいります。",
        ];
        return $texts[$seq] ?? '';
    }
}

if (!function_exists('propertyReminderUnviewedCount')) {
    /**
     * まだご覧になっていない提案物件の件数。判定できなかった場合は null。
     *
     * 条件は propertyUnreadCountFor() と同じだが、あちらは表示用バッジのため
     * 失敗時も 0 を返す。ここで 0 と失敗を取り違えると、DBの一時障害で
     * 「閲覧済み」と誤判定してリマインドを永久に止めてしまうため、null で区別する。
     */
    function propertyReminderUnviewedCount(PDO $db, string $sessionId): ?int
    {
        try {
            propertyUnreadEnsureTable($db);
            propertyViewEnsureTables($db);
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM properties p
                 WHERE p.session_id = ?
                   AND p.created_by = 'agent'
                   AND p.ocr_status <> 'draft'
                   AND p.id > COALESCE((SELECT r.last_seen_property_id FROM property_customer_reads r
                                        WHERE r.session_id = p.session_id), 0)
                   AND NOT EXISTS (SELECT 1 FROM property_views pv
                                   WHERE pv.property_id = p.id AND pv.session_id = p.session_id)"
            );
            $stmt->execute([$sessionId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('propertyReminderUnviewedCount error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('propertyReminderSessionIsActive')) {
    /**
     * 案件（チャットセッション）が生きているか。削除済み・存在しない場合は false。
     * 判定できなかった場合は null（一時的な障害でサイクルを打ち切らないため、false と区別する）。
     */
    function propertyReminderSessionIsActive(PDO $db, string $sessionId): ?bool
    {
        try {
            $stmt = $db->prepare("SELECT deleted_at FROM chat_sessions WHERE id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return false;
            return empty($row['deleted_at']);
        } catch (Throwable $e) {
            error_log('propertyReminderSessionIsActive error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('propertyReminderFlushDue')) {
    /**
     * 送信時刻を過ぎたリマインドを送信する（cron から呼ぶ）。
     * 1サイクル＝最大8通。送信のたびに次回（+12時間）を予約し、8回目で done にする。
     *
     * @return array ['sent'=>int, 'failed'=>int, 'stopped'=>int]
     */
    function propertyReminderFlushDue(PDO $db, int $limit = 50): array
    {
        $sent = 0;
        $failed = 0;
        $stopped = 0;

        try {
            propertyReminderEnsureTable($db);
        } catch (Throwable $e) {
            error_log('propertyReminderFlushDue ensure error: ' . $e->getMessage());
            return ['sent' => 0, 'failed' => 0, 'stopped' => 0];
        }

        $stmt = $db->prepare(
            "SELECT session_id, business_card_id, sent_count, fail_count
             FROM property_view_reminders
             WHERE status = 'active' AND next_send_at IS NOT NULL AND next_send_at <= NOW()
             ORDER BY next_send_at ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $interval = (int)PROPERTY_REMINDER_INTERVAL_HOURS;
        $retry    = (int)PROPERTY_REMINDER_RETRY_MINUTES;

        foreach ($rows as $row) {
            $sessionId = (string)$row['session_id'];
            $seq = (int)$row['sent_count'] + 1;

            // ① 送り切った → 終了。
            if ($seq > (int)PROPERTY_REMINDER_MAX_COUNT) {
                propertyReminderMark($db, $sessionId, 'done');
                $stopped++;
                continue;
            }

            // ② 案件が削除済み → 終了。判定できなかった場合（null）は止めず、後で再判定する。
            $alive = propertyReminderSessionIsActive($db, $sessionId);
            if ($alive === null) {
                propertyReminderPostponeOnFailure($db, $sessionId, (int)$row['fail_count'], $retry);
                $failed++;
                continue;
            }
            if ($alive === false) {
                propertyReminderMark($db, $sessionId, 'stopped');
                $stopped++;
                continue;
            }

            // ③ お客様が物件をご覧になった（未閲覧0件）→ 終了。
            //    物件選定を開く / 物件詳細を開く のいずれでも未読は解消される。
            //    判定できなかった場合（null）は止めず、後で再判定する。
            $unviewed = propertyReminderUnviewedCount($db, $sessionId);
            if ($unviewed === null) {
                propertyReminderPostponeOnFailure($db, $sessionId, (int)$row['fail_count'], $retry);
                $failed++;
                continue;
            }
            if ($unviewed === 0) {
                propertyReminderMark($db, $sessionId, 'viewed');
                $stopped++;
                continue;
            }

            // ④ 宛先を毎回引き直す（提案後にメールが登録・変更されることがあるため）。
            $r = customerNotifyResolveRecipient($db, $sessionId);
            if ($r === null) {
                propertyReminderMark($db, $sessionId, 'stopped');
                $stopped++;
                continue;
            }

            $lead = propertyReminderLeadText($seq);
            if ($lead === '') {
                propertyReminderMark($db, $sessionId, 'done');
                $stopped++;
                continue;
            }

            $agentName = (string)$r['agent_name'];
            $cardSlug  = (string)$r['card_slug'];
            $cardId    = (int)$r['business_card_id'];
            $subject   = propertyReminderSubject($seq);
            // 提案メールと同じく、SMS認証なしで物件を閲覧できる閲覧トークン付きのリンクにする。
            $viewToken = propertyViewTokenFor($db, $sessionId);
            $url = customerNotifyDeepLinkUrl('property', $cardSlug, $viewToken);

            try {
                [$html, $text] = propertyEmailCompose($db, $sessionId, $cardId, $lead, $url, $viewToken, $agentName);
            } catch (Throwable $e) {
                error_log('propertyReminderFlushDue compose error: ' . $e->getMessage());
                $failed++;
                propertyReminderPostponeOnFailure($db, $sessionId, (int)$row['fail_count'], $retry);
                continue;
            }

            // 2名対応: 案件の参加者全員へ個別に送る（本人＋招待されたご家族）。
            $recipients = customerNotifyAllRecipientEmails($db, $sessionId, (string)$r['recipient_email']);
            if (empty($recipients)) {
                $recipients = [(string)$r['recipient_email']];
            }
            $ok = false;
            foreach ($recipients as $recipient) {
                if (trim($recipient) === '') continue;
                if (sendEmail($recipient, $subject, $html, $text, 'customer_property_reminder')) {
                    $ok = true;
                }
            }

            if (!$ok) {
                $failed++;
                propertyReminderPostponeOnFailure($db, $sessionId, (int)$row['fail_count'], $retry);
                continue;
            }

            // 送信成功 → 回数を1つ進め、次回を予約する。最終回なら終了。
            $isLast = $seq >= (int)PROPERTY_REMINDER_MAX_COUNT;
            try {
                $upd = $db->prepare(
                    "UPDATE property_view_reminders
                     SET sent_count = ?,
                         fail_count = 0,
                         last_sent_at = NOW(),
                         next_send_at = " . ($isLast ? "NULL" : "DATE_ADD(NOW(), INTERVAL {$interval} HOUR)") . ",
                         status = " . ($isLast ? "'done'" : "'active'") . ",
                         stopped_at = " . ($isLast ? "NOW()" : "NULL") . "
                     WHERE session_id = ? AND status = 'active'"
                );
                $upd->execute([$seq, $sessionId]);
            } catch (Throwable $e) {
                error_log('propertyReminderFlushDue update error: ' . $e->getMessage());
            }
            $sent++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'stopped' => $stopped];
    }
}

if (!function_exists('propertyReminderMark')) {
    /** サイクルを終了状態にする（内部用）。 */
    function propertyReminderMark(PDO $db, string $sessionId, string $status): void
    {
        try {
            $stmt = $db->prepare(
                "UPDATE property_view_reminders
                 SET status = ?, next_send_at = NULL, stopped_at = NOW()
                 WHERE session_id = ? AND status = 'active'"
            );
            $stmt->execute([$status, $sessionId]);
        } catch (Throwable $e) {
            error_log('propertyReminderMark error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('propertyReminderPostponeOnFailure')) {
    /**
     * 送信に失敗したときの後始末（内部用）。
     * 回数（sent_count）は消費せず、再試行を先送りする。連続失敗が上限に達したら打ち切る。
     */
    function propertyReminderPostponeOnFailure(PDO $db, string $sessionId, int $failCount, int $retryMinutes): void
    {
        try {
            if ($failCount + 1 >= (int)PROPERTY_REMINDER_MAX_FAILURES) {
                $stmt = $db->prepare(
                    "UPDATE property_view_reminders
                     SET fail_count = fail_count + 1, status = 'stopped', next_send_at = NULL, stopped_at = NOW()
                     WHERE session_id = ? AND status = 'active'"
                );
                $stmt->execute([$sessionId]);
                return;
            }
            $stmt = $db->prepare(
                "UPDATE property_view_reminders
                 SET fail_count = fail_count + 1,
                     next_send_at = DATE_ADD(NOW(), INTERVAL {$retryMinutes} MINUTE)
                 WHERE session_id = ? AND status = 'active'"
            );
            $stmt->execute([$sessionId]);
        } catch (Throwable $e) {
            error_log('propertyReminderPostponeOnFailure error: ' . $e->getMessage());
        }
    }
}

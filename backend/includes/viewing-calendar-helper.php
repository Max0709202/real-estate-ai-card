<?php
/**
 * 内見候補枠の「選択不可」判定（Googleカレンダー連携の接続口）。
 * -------------------------------------------------------------
 * 担当エージェントのGoogleカレンダーと連携し、既存予定とその前後1時間に重なる枠を選択不可にする。
 *   例）予定が13:00〜14:00 → 12:00〜15:00 に重なる内見枠は選べない。
 *       11:00〜12:00 と 15:00〜16:00 は、他の予定に重ならなければ選べる。
 *
 * Googleカレンダーを利用していない（＝連携用の認証情報が未設定、または担当者が未連携）場合は、
 * 既存予定を返さない。カレンダー自体は表示し、希望日時は選べる（仕様どおり）。
 *
 * 買主や売主仲介会社には予定の件名・参加者などを渡さず、「選択不可」の時間帯だけを返す。
 *
 * 接続情報（未設定なら未連携として扱う）:
 *   GOOGLE_CALENDAR_CLIENT_ID / GOOGLE_CALENDAR_CLIENT_SECRET … OAuthクライアント
 *   viewing_calendar_links テーブル … 担当者ごとのリフレッシュトークン
 */

require_once __DIR__ . '/viewing-helper.php';

if (!function_exists('viewingCalendarEnsureTables')) {
    /** 担当エージェントごとのGoogleカレンダー連携情報。 */
    function viewingCalendarEnsureTables(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $db->exec("CREATE TABLE IF NOT EXISTS viewing_calendar_links (
          business_card_id INT NOT NULL PRIMARY KEY,
          provider VARCHAR(16) NOT NULL DEFAULT 'google',
          calendar_id VARCHAR(255) NULL DEFAULT NULL,
          refresh_token TEXT NULL DEFAULT NULL,
          access_token TEXT NULL DEFAULT NULL,
          access_expires_at DATETIME NULL DEFAULT NULL,
          status ENUM('linked','revoked','error') NOT NULL DEFAULT 'linked',
          last_error VARCHAR(500) NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }
}

if (!function_exists('viewingCalendarCredentials')) {
    /** OAuthクライアント情報。未設定なら null（＝連携機能を使わない）。 */
    function viewingCalendarCredentials(): ?array
    {
        $id = (string)(getenv('GOOGLE_CALENDAR_CLIENT_ID') ?: (defined('GOOGLE_CALENDAR_CLIENT_ID') ? GOOGLE_CALENDAR_CLIENT_ID : ''));
        $secret = (string)(getenv('GOOGLE_CALENDAR_CLIENT_SECRET') ?: (defined('GOOGLE_CALENDAR_CLIENT_SECRET') ? GOOGLE_CALENDAR_CLIENT_SECRET : ''));
        if ($id === '' || $secret === '') return null;
        return ['client_id' => $id, 'client_secret' => $secret];
    }
}

if (!function_exists('viewingCalendarLink')) {
    /** 担当者の連携状態。未連携なら null。 */
    function viewingCalendarLink(PDO $db, int $businessCardId): ?array
    {
        if ($businessCardId <= 0) return null;
        try {
            viewingCalendarEnsureTables($db);
            $stmt = $db->prepare("SELECT * FROM viewing_calendar_links WHERE business_card_id = ? AND status = 'linked' LIMIT 1");
            $stmt->execute([$businessCardId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('viewingCalendarLink error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('viewingCalendarIsConnected')) {
    /** 担当者がGoogleカレンダーを連携済みか。 */
    function viewingCalendarIsConnected(PDO $db, int $businessCardId): bool
    {
        if (viewingCalendarCredentials() === null) return false;
        return viewingCalendarLink($db, $businessCardId) !== null;
    }
}

if (!function_exists('viewingCalendarAccessToken')) {
    /** リフレッシュトークンからアクセストークンを取り直す。失敗時は空文字。 */
    function viewingCalendarAccessToken(PDO $db, array $link): string
    {
        $cred = viewingCalendarCredentials();
        if ($cred === null) return '';

        $token = trim((string)($link['access_token'] ?? ''));
        $expires = trim((string)($link['access_expires_at'] ?? ''));
        if ($token !== '' && $expires !== '') {
            try {
                if (new DateTimeImmutable($expires, viewingTz()) > viewingNow()->modify('+60 seconds')) return $token;
            } catch (Throwable $e) {
                // 期限が読めない場合は取り直す。
            }
        }

        $refresh = trim((string)($link['refresh_token'] ?? ''));
        if ($refresh === '') return '';

        $post = http_build_query([
            'client_id'     => $cred['client_id'],
            'client_secret' => $cred['client_secret'],
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) {
            viewingCalendarMarkError($db, (int)$link['business_card_id'], 'アクセストークンの取得に失敗しました（HTTP ' . $code . '）');
            return '';
        }
        $json = json_decode((string)$body, true);
        $access = trim((string)($json['access_token'] ?? ''));
        if ($access === '') return '';
        $ttl = (int)($json['expires_in'] ?? 3600);
        try {
            $stmt = $db->prepare("UPDATE viewing_calendar_links SET access_token = ?, access_expires_at = ?, last_error = NULL
                                  WHERE business_card_id = ?");
            $stmt->execute([$access, viewingNow()->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s'), (int)$link['business_card_id']]);
        } catch (Throwable $e) {
            error_log('viewingCalendarAccessToken store error: ' . $e->getMessage());
        }
        return $access;
    }
}

if (!function_exists('viewingCalendarMarkError')) {
    /** 連携エラーを記録する（担当者画面で連携し直しを案内するため）。 */
    function viewingCalendarMarkError(PDO $db, int $businessCardId, string $message): void
    {
        try {
            viewingCalendarEnsureTables($db);
            $stmt = $db->prepare("UPDATE viewing_calendar_links SET status = 'error', last_error = ? WHERE business_card_id = ?");
            $stmt->execute([mb_substr($message, 0, 500), $businessCardId]);
        } catch (Throwable $e) {
            error_log('viewingCalendarMarkError error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('viewingCalendarBusy')) {
    /**
     * 期間内の既存予定（開始・終了のみ）を返す。未連携・失敗時は空配列。
     * 件名・参加者などは取得しない（買主・売主仲介会社に渡さないため）。
     *
     * @return array<int, array{start:string, end:string}> "Y-m-d H:i:s"（JST）
     */
    function viewingCalendarBusy(PDO $db, int $businessCardId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $link = viewingCalendarLink($db, $businessCardId);
        if ($link === null) return [];
        $access = viewingCalendarAccessToken($db, $link);
        if ($access === '') return [];

        $calendarId = trim((string)($link['calendar_id'] ?? '')) ?: 'primary';
        $payload = json_encode([
            'timeMin' => $from->format(DATE_RFC3339),
            'timeMax' => $to->format(DATE_RFC3339),
            'timeZone' => VIEWING_TZ,
            'items' => [['id' => $calendarId]],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://www.googleapis.com/calendar/v3/freeBusy');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) {
            viewingCalendarMarkError($db, $businessCardId, 'カレンダーの取得に失敗しました（HTTP ' . $code . '）');
            return [];
        }
        $json = json_decode((string)$body, true);
        $busy = $json['calendars'][$calendarId]['busy'] ?? [];
        $out = [];
        foreach (is_array($busy) ? $busy : [] as $b) {
            try {
                $s = (new DateTimeImmutable((string)$b['start']))->setTimezone(viewingTz());
                $e = (new DateTimeImmutable((string)$b['end']))->setTimezone(viewingTz());
                $out[] = ['start' => $s->format('Y-m-d H:i:s'), 'end' => $e->format('Y-m-d H:i:s')];
            } catch (Throwable $ex) {
                // 読めない予定は無視する。
            }
        }
        return $out;
    }
}

if (!function_exists('viewingCalendarBlockedStarts')) {
    /**
     * 選択不可にする開始時刻の一覧（"Y-m-d H:i:s"）。
     * 既存予定の前後1時間に重なる枠を落とす。
     *   予定 13:00〜14:00 → 12:00〜15:00 と重なる枠（＝開始 11:01〜14:59 の1時間枠）は選択不可。
     *
     * @param array $busy viewingCalendarBusy() の戻り値
     */
    function viewingCalendarBlockedStarts(array $busy, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$busy) return [];
        $buffer = VIEWING_BUSY_BUFFER_MINUTES * 60;
        $slotLen = VIEWING_SLOT_MINUTES * 60;

        $ranges = [];
        foreach ($busy as $b) {
            try {
                $s = (new DateTimeImmutable($b['start'], viewingTz()))->getTimestamp() - $buffer;
                $e = (new DateTimeImmutable($b['end'], viewingTz()))->getTimestamp() + $buffer;
                $ranges[] = [$s, $e];
            } catch (Throwable $ex) {
                // 無視。
            }
        }
        if (!$ranges) return [];

        $blocked = [];
        $starts = viewingSlotStartCandidates();
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            foreach ($starts as $hhmm) {
                [$h, $i] = array_map('intval', explode(':', $hhmm));
                $slotStart = $d->setTime($h, $i)->getTimestamp();
                $slotEnd = $slotStart + $slotLen;
                foreach ($ranges as [$rs, $re]) {
                    // 半開区間で重なりを判定する（接している時刻は重なりとみなさない）。
                    if ($slotStart < $re && $slotEnd > $rs) {
                        $blocked[] = $d->setTime($h, $i)->format('Y-m-d H:i:s');
                        break;
                    }
                }
            }
        }
        return array_values(array_unique($blocked));
    }
}

if (!function_exists('viewingCalendarBlockedFor')) {
    /**
     * 担当者の予定から「選択不可」の開始時刻を求める。
     * 未連携なら空配列（＝既存予定を表示せず、カレンダー自体は表示する）。
     */
    function viewingCalendarBlockedFor(PDO $db, int $businessCardId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            $busy = viewingCalendarBusy($db, $businessCardId, $from->setTime(0, 0), $to->setTime(23, 59, 59));
            return viewingCalendarBlockedStarts($busy, $from, $to);
        } catch (Throwable $e) {
            error_log('viewingCalendarBlockedFor error: ' . $e->getMessage());
            return [];
        }
    }
}

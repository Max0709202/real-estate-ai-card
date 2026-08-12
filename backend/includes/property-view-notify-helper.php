<?php
/**
 * 顧客が提案物件を閲覧したときの担当エージェントへのメール通知。
 * -------------------------------------------------------------
 * 仕様（要望）:
 *   ① 顧客が提案物件の詳細を開いたら、担当エージェントへ「閲覧中」をメール通知する。
 *      タイムリーな連絡が目的のため、cron のバッチ送信（5分間隔）ではなく閲覧時に即送信する。
 *      ただしメール送信で顧客の画面表示を待たせないよう、呼び出し側（property/get.php）は
 *      レスポンス送出後（register_shutdown_function）に実行する。
 *   ② 同じ顧客が短時間に何度も閲覧してもメールが大量に届かないよう、
 *      前回の閲覧通知から PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS（既定3時間）以内は再通知しない。
 *      最後の通知から3時間以上経過した後の閲覧は、改めて通知する。
 *      抑止は「顧客（session_id）単位」。物件ごとではないため、3時間の間に別の物件を
 *      閲覧した場合もメールは1通に収まる。
 *
 * 宛先は notification-helper.php と同じ「その名刺の所有ユーザー（担当営業）」の users.email。
 * 抑止状態は property_view_notifications（property-view-helper.php で作成）に持つ。
 */

require_once __DIR__ . '/functions.php';            // sendEmail()
require_once __DIR__ . '/notification-helper.php';  // notifyResolveRecipient() / notifyDeepLinkUrl() / NOTIFY_SUBJECT_PREFIX
require_once __DIR__ . '/property-view-helper.php'; // propertyViewEnsureTables()

if (!defined('PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS')) {
    // 同じ顧客への閲覧通知の最短間隔（既定3時間）。
    define('PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS', (int)(getenv('PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS') ?: 10800));
}

if (!function_exists('propertyViewNotifyPropertyName')) {
    /** メール本文に出す物件名（物件名 → 旧データの property_name → 所在地 → 既定文言）。 */
    function propertyViewNotifyPropertyName(array $row): string
    {
        foreach (['building_name', 'property_name', 'address'] as $key) {
            $v = trim((string)($row[$key] ?? ''));
            if ($v !== '') return $v;
        }
        return '提案物件';
    }
}

if (!function_exists('propertyViewNotifyClaim')) {
    /**
     * 通知枠を確保する（3時間以内の再通知を防ぐ）。
     * 同時アクセスで二重送信しないよう、判定と更新を1クエリ（原子的）で行う。
     *
     * @return array|null 確保できたら直前の状態（送信失敗時の巻き戻し用）、抑止中なら null
     */
    function propertyViewNotifyClaim(PDO $db, string $sessionId, int $propertyId): ?array
    {
        propertyViewEnsureTables($db);

        // 送信に失敗したときに元へ戻せるよう、直前の状態を控える。
        $stmt = $db->prepare("SELECT last_notified_at, notify_count FROM property_view_notifications
                              WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['last_notified_at' => null, 'notify_count' => 0];

        $window = (int)PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS;
        // ON DUPLICATE KEY UPDATE の右辺は「更新前」の値を参照するため、
        // last_notified_at（判定に使う）は最後に代入する。
        // 影響行数: 1=新規 / 2=更新（通知する） / 0=変更なし（3時間以内のため抑止）。
        $sql = "INSERT INTO property_view_notifications
                  (session_id, last_property_id, last_notified_at, notify_count)
                VALUES (?, ?, NOW(), 1)
                ON DUPLICATE KEY UPDATE
                  notify_count = notify_count + IF(last_notified_at IS NULL
                      OR last_notified_at < DATE_SUB(NOW(), INTERVAL {$window} SECOND), 1, 0),
                  last_property_id = IF(last_notified_at IS NULL
                      OR last_notified_at < DATE_SUB(NOW(), INTERVAL {$window} SECOND), VALUES(last_property_id), last_property_id),
                  last_notified_at = IF(last_notified_at IS NULL
                      OR last_notified_at < DATE_SUB(NOW(), INTERVAL {$window} SECOND), NOW(), last_notified_at)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$sessionId, $propertyId]);
        if ($stmt->rowCount() < 1) return null; // 前回通知から3時間以内 → 通知しない

        return [
            'last_notified_at' => $prev['last_notified_at'] ?? null,
            'notify_count'     => (int)($prev['notify_count'] ?? 0),
        ];
    }
}

if (!function_exists('propertyViewNotifyRelease')) {
    /**
     * メール送信に失敗したときに通知枠を元へ戻す（次の閲覧で再度通知できるようにする）。
     * 自分が確保した枠のままのとき（notify_count が +1 のまま）だけ戻す。
     */
    function propertyViewNotifyRelease(PDO $db, string $sessionId, array $prev): void
    {
        try {
            $stmt = $db->prepare(
                "UPDATE property_view_notifications
                 SET last_notified_at = ?, notify_count = ?
                 WHERE session_id = ? AND notify_count = ?"
            );
            $stmt->execute([
                $prev['last_notified_at'],
                (int)$prev['notify_count'],
                $sessionId,
                (int)$prev['notify_count'] + 1,
            ]);
        } catch (Throwable $e) {
            error_log('propertyViewNotifyRelease error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('propertyViewNotifyBuildBody')) {
    /** メール本文（HTML / テキスト）を組み立てる。 */
    function propertyViewNotifyBuildBody(string $customerName, string $propertyName, string $sessionId): array
    {
        $name = $customerName !== '' ? $customerName : 'お客';
        $url = notifyDeepLinkUrl('property', $sessionId);
        $hours = (int)round(PROPERTY_VIEW_NOTIFY_INTERVAL_SECONDS / 3600);
        $lead = "{$name}様が、提案物件『{$propertyName}』の物件情報を閲覧中です。";
        $note = "※この通知は、同じお客様について{$hours}時間に1回までに制限しています。";

        $safeLead = htmlspecialchars($lead, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeNote = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

        $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.8;color:#333;">'
            . '<p>' . $safeLead . '</p>'
            . '<p>ご関心が高いタイミングです。よろしければご連絡をご検討ください。</p>'
            . '<p style="margin:24px 0;">'
            . '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;background:#0066cc;color:#fff;text-decoration:none;border-radius:4px;">物件選定を開く</a>'
            . '</p>'
            . '<p style="font-size:12px;color:#888;">' . $safeNote . '<br>'
            . '※ログインされていない場合は、ログイン後に該当画面へ自動的に移動します。</p>'
            . '</div>';

        $text = "{$lead}\n"
            . "ご関心が高いタイミングです。よろしければご連絡をご検討ください。\n\n"
            . "物件選定を開く: {$url}\n\n"
            . "{$note}\n";

        return [$html, $text];
    }
}

if (!function_exists('propertyViewNotifyOnView')) {
    /**
     * 顧客が物件詳細を開いたときに呼ぶ（担当エージェントへ即時通知）。
     * 通知の失敗で物件詳細の表示を壊さないよう、例外は握りつぶしてログのみ残す。
     *
     * @param array $propertyRow properties の行（id / session_id / building_name 等）
     * @return bool メールを送ったとき true（抑止中・宛先無し・送信失敗は false）
     */
    function propertyViewNotifyOnView(PDO $db, array $propertyRow): bool
    {
        try {
            $propertyId = (int)($propertyRow['id'] ?? 0);
            $sessionId  = trim((string)($propertyRow['session_id'] ?? ''));
            if ($propertyId <= 0 || $sessionId === '') return false;

            // 宛先（担当営業のメール）と顧客名を解決してから通知枠を確保する。
            $r = notifyResolveRecipient($db, $sessionId);
            if ($r === null) return false;

            $prev = propertyViewNotifyClaim($db, $sessionId, $propertyId);
            if ($prev === null) return false; // 前回通知から3時間以内 → 送らない

            $customerName = (string)($r['customer_name'] ?? '');
            $displayName  = $customerName !== '' ? $customerName : 'お客';
            $propertyName = propertyViewNotifyPropertyName($propertyRow);
            $subject = NOTIFY_SUBJECT_PREFIX . "{$displayName}様が提案物件を閲覧中です";
            [$html, $text] = propertyViewNotifyBuildBody($customerName, $propertyName, $sessionId);

            $ok = sendEmail(
                (string)$r['recipient_email'],
                $subject,
                $html,
                $text,
                'agent_property_view',
                (int)$r['recipient_user_id'],
                $propertyId
            );
            if (!$ok) {
                // 送信できなかった分は抑止を解除し、次の閲覧で改めて通知できるようにする。
                propertyViewNotifyRelease($db, $sessionId, $prev);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            error_log('propertyViewNotifyOnView error: ' . $e->getMessage());
            return false;
        }
    }
}

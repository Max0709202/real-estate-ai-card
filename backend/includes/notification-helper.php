<?php
/**
 * 営業担当へのメール通知（担当連絡 / 物件選定 / 日程調整）
 * -------------------------------------------------------------
 * 顧客の操作で notifyEnqueue() を呼ぶと chat_notification_jobs に
 * 「送信待ち(pending)」ジョブを作る／集約する。実際の送信は cron から
 * notifyFlushDue() が行う（最後の操作から NOTIFY_WAIT_SECONDS 秒経過後に1通）。
 *
 * 仕様（社内要望）:
 *   ① 操作後すぐ送らず一定時間待機（NOTIFY_WAIT_SECONDS）
 *   ② 待機中の同種操作は1通にまとめる（最後の操作から待機時間を再計測）
 *   ③ 送信済み・未読(status='sent')の間は追加通知しない
 *   ④ 営業が画面を開いたら未読解除(status='read')、以降の新操作は再び通知対象
 *
 * 宛先は「その名刺の所有ユーザー（担当営業）」の users.email のみ。
 */

require_once __DIR__ . '/functions.php';        // sendEmail()
require_once __DIR__ . '/chat-phone-helper.php'; // chatResolveCustomerNameForSession()

if (!defined('NOTIFY_WAIT_SECONDS')) {
    // 将来「待機時間の変更」を設定化しやすいよう定数化。
    define('NOTIFY_WAIT_SECONDS', (int)(getenv('NOTIFY_WAIT_SECONDS') ?: 60));
}
if (!defined('NOTIFY_SUBJECT_PREFIX')) {
    // 今後のシステムメールは件名先頭にこの接頭辞を統一付与する。
    define('NOTIFY_SUBJECT_PREFIX', '【不動産AI名刺】');
}

/** 通知対象の機能キー一覧 */
function notifyFeatures(): array
{
    return ['contact', 'property', 'schedule'];
}

/**
 * テーブルが無ければ作成（マイグレーション未適用環境でも動くように冪等化）。
 */
function notifyEnsureTable(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS chat_notification_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id CHAR(36) NOT NULL,
            feature ENUM('contact','property','schedule') NOT NULL,
            business_card_id INT NOT NULL,
            recipient_user_id INT NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) NULL,
            status ENUM('pending','sent','read') NOT NULL DEFAULT 'pending',
            event_count INT NOT NULL DEFAULT 0,
            first_event_at TIMESTAMP NULL DEFAULT NULL,
            last_event_at  TIMESTAMP NULL DEFAULT NULL,
            scheduled_at   TIMESTAMP NULL DEFAULT NULL,
            sent_at TIMESTAMP NULL DEFAULT NULL,
            read_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_session_feature (session_id, feature),
            INDEX idx_due (status, scheduled_at),
            INDEX idx_recipient (recipient_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

/**
 * セッションから担当営業（宛先）と顧客名を解決する。
 * @return array|null ['business_card_id','recipient_user_id','recipient_email','customer_name'] または null
 */
function notifyResolveRecipient(PDO $db, string $sessionId): ?array
{
    $stmt = $db->prepare(
        "SELECT cs.business_card_id, bc.user_id, u.email
         FROM chat_sessions cs
         JOIN business_cards bc ON bc.id = cs.business_card_id
         JOIN users u ON u.id = bc.user_id
         WHERE cs.id = ?
         LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['email'])) {
        return null;
    }
    $cardId = (int)$row['business_card_id'];
    $name = '';
    if (function_exists('chatResolveCustomerNameForSession')) {
        $name = (string)(chatResolveCustomerNameForSession($db, $sessionId, $cardId) ?: '');
    }
    return [
        'business_card_id'  => $cardId,
        'recipient_user_id' => (int)$row['user_id'],
        'recipient_email'   => (string)$row['email'],
        'customer_name'     => $name,
    ];
}

/**
 * 顧客操作を通知ジョブへ積む（②③④の状態遷移を単一クエリで原子的に処理）。
 * 通知失敗が業務処理を壊さないよう、例外は内部で握りつぶしログのみ。
 *
 * @param string $feature 'contact' | 'property' | 'schedule'
 * @return bool 積めた/集約できたら true
 */
function notifyEnqueue(PDO $db, string $sessionId, string $feature): bool
{
    try {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !in_array($feature, notifyFeatures(), true)) {
            return false;
        }
        $r = notifyResolveRecipient($db, $sessionId);
        if ($r === null) {
            // 宛先（担当営業のメール）が無ければ通知しない。
            return false;
        }
        notifyEnsureTable($db);

        $wait = (int)NOTIFY_WAIT_SECONDS;
        // ON DUPLICATE KEY UPDATE の右辺で参照する列は「更新前」の値。
        // status を最後に代入することで、全ての IF(status=...) が旧statusを見る。
        //  - status='sent'（未読）: 何も変えない（③ 追加通知しない）
        //  - status='pending'   : 集約（②）→ event_count++ / 待機を延長
        //  - status='read'      : 再通知対象（④）→ pending にリセット
        $sql = "INSERT INTO chat_notification_jobs
                  (session_id, feature, business_card_id, recipient_user_id, recipient_email,
                   customer_name, status, event_count, first_event_at, last_event_at, scheduled_at)
                VALUES
                  (?, ?, ?, ?, ?, ?, 'pending', 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL {$wait} SECOND))
                ON DUPLICATE KEY UPDATE
                  recipient_user_id = VALUES(recipient_user_id),
                  recipient_email   = VALUES(recipient_email),
                  customer_name     = VALUES(customer_name),
                  event_count    = IF(status='sent', event_count, IF(status='pending', event_count + 1, 1)),
                  first_event_at = IF(status='sent', first_event_at, IF(status='pending', first_event_at, NOW())),
                  last_event_at  = IF(status='sent', last_event_at, NOW()),
                  scheduled_at   = IF(status='sent', scheduled_at, DATE_ADD(NOW(), INTERVAL {$wait} SECOND)),
                  sent_at        = IF(status='sent', sent_at, NULL),
                  read_at        = IF(status='sent', read_at, NULL),
                  status         = IF(status='sent', 'sent', 'pending')";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $sessionId,
            $feature,
            $r['business_card_id'],
            $r['recipient_user_id'],
            $r['recipient_email'],
            $r['customer_name'],
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('notifyEnqueue error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 営業が該当画面を開いた → 未読解除（④）。
 * pending（未送信）も read にして送信をキャンセルする（営業が見ているならメール不要）。
 *
 * @return int 更新した行数
 */
function notifyMarkRead(PDO $db, string $sessionId, string $feature): int
{
    try {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !in_array($feature, notifyFeatures(), true)) {
            return 0;
        }
        notifyEnsureTable($db);
        $stmt = $db->prepare(
            "UPDATE chat_notification_jobs
             SET status='read', read_at=NOW()
             WHERE session_id = ? AND feature = ? AND status IN ('pending','sent')"
        );
        $stmt->execute([$sessionId, $feature]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('notifyMarkRead error: ' . $e->getMessage());
        return 0;
    }
}

/** 機能キー → 日本語ラベル */
function notifyFeatureLabel(string $feature): string
{
    switch ($feature) {
        case 'contact':  return '担当連絡';
        case 'property': return '物件選定';
        case 'schedule': return '日程調整';
        default:         return '連絡';
    }
}

/** 件名（接頭辞付き） */
function notifySubject(string $feature, string $customerName): string
{
    $name = $customerName !== '' ? $customerName : 'お客';
    switch ($feature) {
        case 'contact':
            $body = "{$name}様よりメッセージが届いています";
            break;
        case 'property':
            $body = "{$name}様より物件共有があります";
            break;
        case 'schedule':
            $body = "{$name}様より日程調整の連絡があります";
            break;
        default:
            $body = "{$name}様より新しい連絡があります";
    }
    return NOTIFY_SUBJECT_PREFIX . $body;
}

/** メール内の確認リンク（未ログインならログインを挟んで該当画面へ自動遷移） */
function notifyDeepLinkUrl(string $feature, string $sessionId): string
{
    $target = 'edit.php?type=existing&focus=' . rawurlencode($feature) . '&session=' . rawurlencode($sessionId);
    return rtrim(BASE_URL, '/') . '/login.php?redirect=' . rawurlencode($target);
}

/** メール本文（HTML / テキスト）を組み立てる */
function notifyBuildBody(string $feature, string $customerName, string $sessionId): array
{
    $name = $customerName !== '' ? $customerName : 'お客';
    $label = notifyFeatureLabel($feature);
    $url = notifyDeepLinkUrl($feature, $sessionId);
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.8;color:#333;">'
        . '<p>' . $safeName . '様より新しい連絡（' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '）があります。</p>'
        . '<p>以下のリンクより内容をご確認ください。</p>'
        . '<p style="margin:24px 0;">'
        . '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;background:#0066cc;color:#fff;text-decoration:none;border-radius:4px;">内容を確認する</a>'
        . '</p>'
        . '<p style="font-size:12px;color:#888;">※詳細内容は管理画面でご確認ください。<br>※ログインされていない場合は、ログイン後に該当画面へ自動的に移動します。</p>'
        . '</div>';

    $text = "{$name}様より新しい連絡（{$label}）があります。\n"
        . "以下のリンクより内容をご確認ください。\n\n"
        . "内容を確認する: {$url}\n\n"
        . "※詳細内容は管理画面でご確認ください。\n";

    return [$html, $text];
}

/**
 * 送信期限を過ぎた pending ジョブを送信する（cron から呼ぶ）。
 * 1ジョブ＝1メール。送信成功で status='sent' に遷移（未読状態）。
 *
 * @return array ['sent'=>int, 'failed'=>int]
 */
function notifyFlushDue(PDO $db, int $limit = 20): array
{
    $sent = 0;
    $failed = 0;
    notifyEnsureTable($db);

    $stmt = $db->prepare(
        "SELECT id, session_id, feature, recipient_email, recipient_user_id, customer_name
         FROM chat_notification_jobs
         WHERE status='pending' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
         ORDER BY scheduled_at ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($jobs as $job) {
        $feature = (string)$job['feature'];
        $name = (string)($job['customer_name'] ?? '');
        $subject = notifySubject($feature, $name);
        [$html, $text] = notifyBuildBody($feature, $name, (string)$job['session_id']);

        $ok = sendEmail(
            (string)$job['recipient_email'],
            $subject,
            $html,
            $text,
            'agent_' . $feature,
            (int)$job['recipient_user_id'],
            (int)$job['id']
        );

        if ($ok) {
            // status='pending' の間のみ送信済みへ（その間に markRead された場合は据え置き）。
            $upd = $db->prepare(
                "UPDATE chat_notification_jobs
                 SET status='sent', sent_at=NOW()
                 WHERE id = ? AND status='pending'"
            );
            $upd->execute([(int)$job['id']]);
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

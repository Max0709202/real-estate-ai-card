<?php
/**
 * 顧客へのメール通知（物件選定で物件が追加された / 担当連絡にメッセージが届いた）
 * -------------------------------------------------------------
 * 担当（営業）の操作で customerNotifyEnqueue() を呼ぶと
 * customer_notification_jobs に「送信待ち(pending)」ジョブを作る／集約する。
 * 実際の送信は cron から customerNotifyFlushDue() が行う
 * （最後の操作から NOTIFY_WAIT_SECONDS 秒経過後に1通）。
 *
 * notification-helper.php（担当向け）と対になる「顧客向け」実装。
 * 仕様は同じ:
 *   ① 操作後すぐ送らず一定時間待機（NOTIFY_WAIT_SECONDS）
 *   ② 待機中の同種操作は1通にまとめる（最後の操作から待機時間を再計測）
 *   ③ 送信済み・未読(status='sent')の間は追加通知しない
 *   ④ 顧客が該当画面を開いたら未読解除(status='read')、以降の新操作は再び通知対象
 *
 * 宛先は「その顧客のメールアドレス」（chat_lead_contacts.email、無ければ
 * chat_leads.structured_data の customer_email）。メールが無ければ通知しない。
 */

require_once __DIR__ . '/functions.php'; // sendEmail()

if (!defined('CUSTOMER_NOTIFY_WAIT_SECONDS')) {
    // 顧客向け通知のバッチ集約時間（既定5分）。担当向け（NOTIFY_WAIT_SECONDS）とは独立。
    define('CUSTOMER_NOTIFY_WAIT_SECONDS', (int)(getenv('CUSTOMER_NOTIFY_WAIT_SECONDS') ?: 300));
}
if (!defined('NOTIFY_SUBJECT_PREFIX')) {
    define('NOTIFY_SUBJECT_PREFIX', '【不動産AI名刺】');
}

/** 顧客向けメールで使う担当者の表示名（実名があれば「様」を付与、無ければ「担当者」）。 */
function customerNotifyAgentDisplay(string $agentName): string
{
    $name = trim($agentName);
    return $name !== '' ? $name . '様' : '担当者';
}

/** 顧客通知の対象機能キー一覧（物件追加 / 担当連絡） */
function customerNotifyFeatures(): array
{
    return ['property', 'contact'];
}

/** テーブルが無ければ作成（冪等）。 */
function customerNotifyEnsureTable(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS customer_notification_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id CHAR(36) NOT NULL,
            feature ENUM('property','contact') NOT NULL,
            business_card_id INT NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            agent_name VARCHAR(255) NULL,
            card_slug VARCHAR(255) NULL,
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
            INDEX idx_due (status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $done = true;
}

/**
 * セッションから顧客のメール・担当名・カードslugを解決する。
 * @return array|null ['business_card_id','recipient_email','agent_name','card_slug'] または null（メール無し等）
 */
function customerNotifyResolveRecipient(PDO $db, string $sessionId): ?array
{
    $stmt = $db->prepare(
        "SELECT cs.business_card_id, bc.name AS agent_name, bc.url_slug
         FROM chat_sessions cs
         JOIN business_cards bc ON bc.id = cs.business_card_id
         WHERE cs.id = ?
         LIMIT 1"
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $cardId = (int)$row['business_card_id'];
    $email = customerNotifyResolveEmail($db, $sessionId, $cardId);
    if ($email === '') {
        // 顧客のメールアドレスが無ければ通知しない。
        return null;
    }
    $agentName = trim((string)($row['agent_name'] ?? ''));
    return [
        'business_card_id' => $cardId,
        'recipient_email'  => $email,
        'agent_name'       => $agentName !== '' ? $agentName : '担当者',
        'card_slug'        => (string)($row['url_slug'] ?? ''),
    ];
}

/** 顧客のメールアドレスを解決する（chat_lead_contacts → chat_leads の順）。 */
function customerNotifyResolveEmail(PDO $db, string $sessionId, int $cardId): string
{
    // 1) chat_lead_contacts.email（連絡先として保存されたもの）
    try {
        $stmt = $db->prepare(
            "SELECT email FROM chat_lead_contacts
             WHERE session_id = ? AND email IS NOT NULL AND email <> ''
             ORDER BY updated_at DESC LIMIT 1"
        );
        $stmt->execute([$sessionId]);
        $email = trim((string)($stmt->fetchColumn() ?: ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    } catch (Throwable $e) {
        // テーブル未作成等は無視して次の手段へ。
    }

    // 2) chat_leads.structured_data の customer_email
    try {
        $stmt = $db->prepare("SELECT structured_data FROM chat_leads WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $sd = $stmt->fetchColumn();
        if ($sd) {
            $data = json_decode((string)$sd, true);
            if (is_array($data) && !empty($data['customer_email'])) {
                $email = trim((string)$data['customer_email']);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $email;
                }
            }
        }
    } catch (Throwable $e) {
        // 無視。
    }

    return '';
}

/**
 * 担当の操作を顧客通知ジョブへ積む（②③④の状態遷移を単一クエリで原子的に処理）。
 * 通知失敗が業務処理を壊さないよう、例外は内部で握りつぶしログのみ。
 *
 * @param string $feature 'property' | 'contact'
 * @return bool 積めた/集約できたら true
 */
function customerNotifyEnqueue(PDO $db, string $sessionId, string $feature): bool
{
    try {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !in_array($feature, customerNotifyFeatures(), true)) {
            return false;
        }
        $r = customerNotifyResolveRecipient($db, $sessionId);
        if ($r === null) {
            // 宛先（顧客のメール）が無ければ通知しない。
            return false;
        }
        customerNotifyEnsureTable($db);

        $wait = (int)CUSTOMER_NOTIFY_WAIT_SECONDS;
        // notification-helper.php と同じく、ON DUPLICATE KEY の右辺は「更新前」の値を参照し、
        // status を最後に代入することで全ての IF(status=...) が旧statusを見る。
        // 担当連絡は新しいメッセージごとに再通知可能にする。従来は一度 sent になると、
        // 顧客が画面を開くまで後続メッセージが永久に抑止され、最初のメールを見失った
        // 顧客へ以後まったく通知できなかった。待機中の複数送信は従来どおり1通に集約する。
        $keepSent = $feature === 'contact' ? '0' : "status='sent'";
        $sql = "INSERT INTO customer_notification_jobs
                  (session_id, feature, business_card_id, recipient_email, agent_name, card_slug,
                   status, event_count, first_event_at, last_event_at, scheduled_at)
                VALUES
                  (?, ?, ?, ?, ?, ?, 'pending', 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL {$wait} SECOND))
                ON DUPLICATE KEY UPDATE
                  business_card_id = VALUES(business_card_id),
                  recipient_email  = VALUES(recipient_email),
                  agent_name       = VALUES(agent_name),
                  card_slug        = VALUES(card_slug),
                  event_count    = IF({$keepSent}, event_count, IF(status='pending', event_count + 1, 1)),
                  first_event_at = IF({$keepSent}, first_event_at, IF(status='pending', first_event_at, NOW())),
                  last_event_at  = IF({$keepSent}, last_event_at, NOW()),
                  scheduled_at   = IF({$keepSent}, scheduled_at, DATE_ADD(NOW(), INTERVAL {$wait} SECOND)),
                  sent_at        = IF({$keepSent}, sent_at, NULL),
                  read_at        = IF({$keepSent}, read_at, NULL),
                  status         = IF({$keepSent}, 'sent', 'pending')";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $sessionId,
            $feature,
            $r['business_card_id'],
            $r['recipient_email'],
            $r['agent_name'],
            $r['card_slug'],
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('customerNotifyEnqueue error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 顧客が該当画面を開いた → 未読解除（④）。
 * pending（未送信）も read にして送信をキャンセルする（顧客が見ているならメール不要）。
 *
 * @return int 更新した行数
 */
function customerNotifyMarkRead(PDO $db, string $sessionId, string $feature): int
{
    try {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !in_array($feature, customerNotifyFeatures(), true)) {
            return 0;
        }
        customerNotifyEnsureTable($db);
        $stmt = $db->prepare(
            "UPDATE customer_notification_jobs
             SET status='read', read_at=NOW()
             WHERE session_id = ? AND feature = ? AND status IN ('pending','sent')"
        );
        $stmt->execute([$sessionId, $feature]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('customerNotifyMarkRead error: ' . $e->getMessage());
        return 0;
    }
}

/** 機能キー → 件名 */
function customerNotifySubject(string $feature, string $agentName): string
{
    $name = customerNotifyAgentDisplay($agentName);
    switch ($feature) {
        case 'property':
            $body = "{$name}より物件のご提案が届いています";
            break;
        case 'contact':
            $body = "{$name}よりメッセージが届いています";
            break;
        default:
            $body = "{$name}より新しいお知らせがあります";
    }
    return NOTIFY_SUBJECT_PREFIX . $body;
}

/** メール内の確認リンク（カードページを開き、該当タブを自動表示）。 */
function customerNotifyDeepLinkUrl(string $feature, string $cardSlug): string
{
    $base = rtrim(BASE_URL, '/') . '/card.php?slug=' . rawurlencode($cardSlug);
    $open = $feature === 'property' ? 'property' : 'contact';
    return $base . '&open=' . rawurlencode($open);
}

/** メール本文（HTML / テキスト）を組み立てる。文面は社内要望どおり。 */
function customerNotifyBuildBody(string $feature, string $agentName, string $cardSlug): array
{
    $name = customerNotifyAgentDisplay($agentName);
    $url = customerNotifyDeepLinkUrl($feature, $cardSlug);
    $lead = $feature === 'property'
        ? "{$name}より、物件の提案が届いています。"
        : "{$name}より、メッセージが届いています。";

    $safeLead = htmlspecialchars($lead, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.8;color:#333;">'
        . '<p>' . $safeLead . '</p>'
        . '<p>以下のリンクより内容をご確認ください。</p>'
        . '<p style="margin:24px 0;">'
        . '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;background:#0066cc;color:#fff;text-decoration:none;border-radius:4px;">内容を確認する</a>'
        . '</p>'
        . '<p style="font-size:12px;color:#888;">※このメールに心当たりがない場合は破棄してください。</p>'
        . '</div>';

    $text = "{$lead}\n"
        . "以下のリンクより内容をご確認ください。\n\n"
        . "内容を確認する: {$url}\n\n"
        . "※このメールに心当たりがない場合は破棄してください。\n";

    return [$html, $text];
}

/**
 * 送信期限を過ぎた pending ジョブを送信する（cron から呼ぶ）。
 * 1ジョブ＝1メール。送信成功で status='sent' に遷移（未読状態）。
 *
 * @return array ['sent'=>int, 'failed'=>int]
 */
function customerNotifyFlushDue(PDO $db, int $limit = 20): array
{
    $sent = 0;
    $failed = 0;
    customerNotifyEnsureTable($db);

    $stmt = $db->prepare(
        "SELECT id, session_id, feature, recipient_email, agent_name, card_slug
         FROM customer_notification_jobs
         WHERE status='pending' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
         ORDER BY scheduled_at ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($jobs as $job) {
        $feature = (string)$job['feature'];
        $agentName = (string)($job['agent_name'] ?? '');
        $cardSlug = (string)($job['card_slug'] ?? '');
        $subject = customerNotifySubject($feature, $agentName);
        [$html, $text] = customerNotifyBuildBody($feature, $agentName, $cardSlug);

        $ok = sendEmail(
            (string)$job['recipient_email'],
            $subject,
            $html,
            $text,
            'customer_' . $feature,
            null,
            (int)$job['id']
        );

        if ($ok) {
            $upd = $db->prepare(
                "UPDATE customer_notification_jobs
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

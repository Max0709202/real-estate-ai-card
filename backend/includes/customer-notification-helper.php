<?php
/**
 * 顧客へのメール通知（物件選定で物件が追加された / 担当連絡にメッセージが届いた）
 * -------------------------------------------------------------
 * 担当（営業）の操作で customerNotifyDispatch() を呼ぶと
 *   ① customer_notification_jobs に「送信待ち(pending)」ジョブを作る／集約し、
 *   ② レスポンスを返し切ってから customerNotifySendNow() で即時にメールを送る。
 * 即時送信に失敗したジョブだけが送信待ちのまま残り、cron（customerNotifyFlushDue）が再送する。
 *
 * notification-helper.php（担当向け）と対になる「顧客向け」実装。
 * ただし配信タイミングだけは担当向けと異なり、こちらは即時送信:
 *   ・事業者（担当）からのメッセージ・物件提案は必ず・すぐに通知メールを届ける要件のため、
 *     待機（CUSTOMER_NOTIFY_WAIT_SECONDS）＋cron間隔（5分）ぶんの遅延を挟まない。
 *   ・待機時間と集約は cron 再送（取りこぼし救済）の経路にだけ残す。
 *   ・担当の新しい操作があれば、送信済み(sent)でも再び通知対象に戻す
 *     （物件提案・担当連絡とも共通。2件目以降の取りこぼしを作らない）
 *   ・顧客が該当画面を開いたら送信済み(sent)を未読解除(status='read')する。
 *     送信待ち(pending)はキャンセルせず必ず送る（担当の操作は必ず通知する）
 *
 * 宛先は「その顧客のメールアドレス」（chat_lead_contacts.email、無ければ
 * chat_leads.structured_data の customer_email）。メールが無ければ通知しない。
 */

require_once __DIR__ . '/functions.php'; // sendEmail()
require_once __DIR__ . '/session-participant-helper.php'; // participantActiveEmails()（2名対応の通知配信先）
require_once __DIR__ . '/property-view-helper.php'; // propertyViewTokenFor()（物件提案リンクの閲覧トークン）

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

    // 3) 案件の参加者（2名対応: primary/partner）のメール。本人の連絡先が未登録でも、
    //    もう一方にメールがあれば通知できるようにする。
    if (function_exists('participantActiveEmails')) {
        try {
            $emails = participantActiveEmails($db, $sessionId);
            if (!empty($emails)) {
                return $emails[0];
            }
        } catch (Throwable $e) {
            // 無視。
        }
    }

    // 4) 担当が事前作成した顧客ページ（招待メール）の宛先。
    //    お客様ご本人のご登録（SMS認証＋お名前＋メール）がまだの段階では 1)〜3) がすべて空になり、
    //    担当が物件を提案しても通知メールが1通も出せない＝お客様は招待メールのリンクを開いて
    //    SMS認証を済ませない限り提案物件に辿り着けなかった。招待メールを実際にお届けした宛先へ
    //    物件提案のご案内も送り、リンク（&pv=）から認証なしで提案物件を閲覧いただけるようにする。
    //    ここでの値は担当の申告値のため、お客様ご本人のご登録が済めば 1)〜3) が優先される。
    try {
        $stmt = $db->prepare(
            "SELECT email FROM chat_customer_invitations
             WHERE session_id = ? AND business_card_id = ? AND email IS NOT NULL AND email <> ''
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$sessionId, $cardId]);
        $email = trim((string)($stmt->fetchColumn() ?: ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    } catch (Throwable $e) {
        // テーブル未作成等は無視。
    }

    return '';
}

/**
 * 案件の通知配信先メール（重複排除）を全参加者ぶん返す。
 * 夫婦など2名で参加している場合、それぞれのメールアドレスへ個別に届けるために使う。
 * @return string[]
 */
function customerNotifyAllRecipientEmails(PDO $db, string $sessionId, string $primaryEmail = ''): array
{
    $out = [];
    $primaryEmail = trim($primaryEmail);
    if ($primaryEmail !== '' && filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
        $out[strtolower($primaryEmail)] = $primaryEmail;
    }
    if (function_exists('participantActiveEmails')) {
        try {
            foreach (participantActiveEmails($db, $sessionId) as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $out[strtolower($email)] = $email;
                }
            }
        } catch (Throwable $e) {
            // 無視。
        }
    }
    return array_values($out);
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
        // 担当連絡・物件提案とも、担当の新しい操作ごとに再通知できるよう pending へ戻す。
        // 従来は物件提案だけ「一度 sent になったら据え置き」だったため、顧客が物件選定を
        // 開かない限り2件目以降の提案が永久に抑止され、メールがまったく届かなかった。
        // （担当連絡は先に抑止を解除済み。物件提案も同じ扱いに揃える。）
        // 待機中（pending）の複数操作は従来どおり1通に集約する。
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
                  event_count    = IF(status='pending', event_count + 1, 1),
                  first_event_at = IF(status='pending', first_event_at, NOW()),
                  last_event_at  = NOW(),
                  scheduled_at   = DATE_ADD(NOW(), INTERVAL {$wait} SECOND),
                  sent_at        = NULL,
                  read_at        = NULL,
                  status         = 'pending'";
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
 * 担当の操作を「その場で」顧客へメール通知する（即時送信）。
 * -------------------------------------------------------------
 * 従来は customerNotifyEnqueue() で送信待ちに積むだけで、実際の配信は cron
 * （process-notification-queue.php）任せだった。このため
 *   ・待機時間（CUSTOMER_NOTIFY_WAIT_SECONDS 既定5分）
 *   ・cron の実行間隔（5分）
 * が積み上がり、担当が物件を提案／メッセージを送っても、お客様にメールが届くのは
 * 最大で約10分後になっていた（cron が止まっていれば永久に届かない）。
 * 「事業者からのメッセージ・物件提案は必ず通知メールを届ける」という要件に合わせ、
 * 担当エージェントへの物件閲覧通知（property-view-notify-helper.php）と同じく即時送信する。
 *
 * 送信できたジョブは status='sent' にして scheduled_at を消すため、cron が二重送信することはない。
 * 送信できなかった場合はジョブを pending のまま残し、cron の再送に委ねる。
 *
 * @param string $feature 'property' | 'contact'
 * @return bool 1通でも送信できたら true
 */
function customerNotifySendNow(PDO $db, string $sessionId, string $feature): bool
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

        // 送信後に「送信済み」へ倒すジョブを控える。送信中に担当の新しい操作が入った場合は
        // last_event_at が変わるため、そのジョブは pending のまま残して cron に送らせる。
        $jobId = 0;
        $lastEventAt = null;
        $stmt = $db->prepare(
            "SELECT id, last_event_at FROM customer_notification_jobs
             WHERE session_id = ? AND feature = ? LIMIT 1"
        );
        $stmt->execute([$sessionId, $feature]);
        if ($job = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobId = (int)$job['id'];
            $lastEventAt = $job['last_event_at'];
        }

        $agentName = (string)$r['agent_name'];
        $cardSlug  = (string)$r['card_slug'];
        $subject   = customerNotifySubject($feature, $agentName);
        // 物件提案のリンクには、顧客ごとの閲覧トークンを付ける（SMS認証なしで物件詳細を閲覧できるようにする）。
        $viewToken = $feature === 'property' ? propertyViewTokenFor($db, $sessionId) : '';
        [$html, $text] = customerNotifyBuildBody($feature, $agentName, $cardSlug, $viewToken);

        // 2名対応: 案件の参加者全員へ個別に送る（本人＋招待されたご家族）。
        $recipients = customerNotifyAllRecipientEmails($db, $sessionId, (string)$r['recipient_email']);
        if (empty($recipients)) {
            $recipients = [(string)$r['recipient_email']];
        }
        $ok = false;
        foreach ($recipients as $recipient) {
            if (trim($recipient) === '') continue;
            if (sendEmail($recipient, $subject, $html, $text, 'customer_' . $feature, null, $jobId ?: null)) {
                $ok = true;
            }
        }
        if (!$ok) {
            // 送れなかった分は pending のまま。cron（customerNotifyFlushDue）が再送する。
            return false;
        }

        if ($jobId > 0) {
            $upd = $db->prepare(
                "UPDATE customer_notification_jobs
                 SET status='sent', sent_at=NOW(), scheduled_at=NULL
                 WHERE id = ? AND status='pending' AND last_event_at <=> ?"
            );
            $upd->execute([$jobId, $lastEventAt]);
        }
        return true;
    } catch (Throwable $e) {
        error_log('customerNotifySendNow error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 担当の操作を顧客へ通知する（本機能の入口。担当連絡・物件提案の呼び出し側はこれを使う）。
 *
 * ① まず customerNotifyEnqueue() で送信待ちジョブを作る
 *    → 即時送信に失敗しても cron が必ず再送する（取りこぼしを作らない）。
 * ② レスポンスを返し切ってから customerNotifySendNow() で即時送信する
 *    → メール送信（SMTP接続）で担当の画面を待たせない。
 *
 * 同一リクエスト内で同じ (session_id, feature) が複数回呼ばれても、送信は1回だけ行う
 * （物件登録は propertyCreate() と save.php の双方から呼ばれるため）。
 */
function customerNotifyDispatch(PDO $db, string $sessionId, string $feature): bool
{
    static $queued = [];

    $sessionId = trim($sessionId);
    if ($sessionId === '' || !in_array($feature, customerNotifyFeatures(), true)) {
        return false;
    }
    // 送信待ちジョブは毎回積む（即時送信が失敗したときに cron が再送できるようにするため）。
    $enqueued = customerNotifyEnqueue($db, $sessionId, $feature);

    $key = $sessionId . '|' . $feature;
    if (isset($queued[$key])) {
        return $enqueued;
    }
    $queued[$key] = true;

    register_shutdown_function(function () use ($db, $sessionId, $feature) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        customerNotifySendNow($db, $sessionId, $feature);
    });
    return $enqueued;
}

/**
 * 顧客が該当画面を開いた → 送信済み(sent)の未読解除（④）。
 *
 * 送信待ち(pending)はキャンセルしない。担当（事業者）からのメッセージ・物件提案は
 * 必ず通知メールを届ける要件のため、顧客がその場で画面を開いていても送信を取り消さない。
 * 顧客のカードページは担当連絡タブのポーリング／物件選定タブの再読込で本APIを叩き続けるため、
 * 従来は待機時間（既定5分）＋cron間隔の間に read へ倒れ、通知が1通も届かないことがあった。
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
             WHERE session_id = ? AND feature = ? AND status = 'sent'"
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

/**
 * メール内の確認リンク（カードページを開き、該当タブを自動表示）。
 * 物件提案は、SMS認証なしで提案物件の詳細を閲覧できるよう、顧客ごとの閲覧トークン（pv）を付ける。
 * トークンは物件情報の閲覧のみに使え、他の機能はこれまで通りSMS認証が必要。
 */
function customerNotifyDeepLinkUrl(string $feature, string $cardSlug, string $viewToken = ''): string
{
    $base = rtrim(BASE_URL, '/') . '/card.php?slug=' . rawurlencode($cardSlug);
    $open = $feature === 'property' ? 'property' : 'contact';
    $url = $base . '&open=' . rawurlencode($open);
    if ($feature === 'property' && $viewToken !== '') {
        $url .= '&pv=' . rawurlencode($viewToken);
    }
    return $url;
}

/** メール本文（HTML / テキスト）を組み立てる。文面は社内要望どおり。 */
function customerNotifyBuildBody(string $feature, string $agentName, string $cardSlug, string $viewToken = ''): array
{
    $name = customerNotifyAgentDisplay($agentName);
    $url = customerNotifyDeepLinkUrl($feature, $cardSlug, $viewToken);
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
        // 物件提案のリンクには、顧客ごとの閲覧トークンを付ける（SMS認証なしで物件詳細を閲覧できるようにする）。
        $viewToken = $feature === 'property' ? propertyViewTokenFor($db, (string)$job['session_id']) : '';
        [$html, $text] = customerNotifyBuildBody($feature, $agentName, $cardSlug, $viewToken);

        // 2名対応: 案件の参加者全員へ個別に送る（本人＋招待された家族）。
        // 参加者が1名なら従来どおり1通（recipient_email のみ）。
        $recipients = customerNotifyAllRecipientEmails($db, (string)$job['session_id'], (string)$job['recipient_email']);
        if (empty($recipients)) {
            $recipients = [(string)$job['recipient_email']];
        }
        $ok = false;
        foreach ($recipients as $recipient) {
            if (trim($recipient) === '') continue;
            $sentOne = sendEmail(
                $recipient,
                $subject,
                $html,
                $text,
                'customer_' . $feature,
                null,
                (int)$job['id']
            );
            // 1人でも送信できればジョブは送信済みとして扱う（未読抑制の状態遷移を進める）。
            if ($sentOne) $ok = true;
        }

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

<?php
/**
 * エージェントによる顧客ページ（AIエージェントページ）の事前作成
 * -------------------------------------------------------------
 * 従来の導線:
 *   顧客が名刺へアクセス → SMS認証 → マイページ上に顧客名が表示 → 提案開始
 * 追加する導線（従来の導線はそのまま残す）:
 *   エージェントがマイページで氏名・メールを入力 → 確認 → 専用URLをメール送信
 *   → 顧客がURLを開くと名刺ページではなく AIエージェントページが最初に表示される
 *   → そこからいつもどおり SMS認証 → 氏名 → メールアドレスを登録する
 *
 * 設計上の要点:
 *   ここで入力された氏名・メールアドレスは「エージェントの申告値」であり、
 *   顧客本人の確認を経ていない。エージェントが氏名を間違えている場合や、
 *   顧客が別のメールアドレスで受け取りたい場合があるため、
 *   chat_lead_contacts（＝顧客本人が登録した連絡先）へは書き込まない。
 *   本人登録の結果が常に正となり、この表の値は「宛先」と「呼びかけ」だけに使う。
 */

require_once __DIR__ . '/../config/config.php'; // BASE_URL
require_once __DIR__ . '/functions.php';        // sendEmail()

/** テーブルが無ければ作成（冪等）。migrations/20260720_add_customer_invitations.sql と同じ定義。 */
function customerInviteEnsureTable(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS chat_customer_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id CHAR(36) NOT NULL,
            business_card_id INT NOT NULL,
            invite_token CHAR(64) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            status ENUM('sent','opened','registered') NOT NULL DEFAULT 'sent',
            sent_at TIMESTAMP NULL DEFAULT NULL,
            opened_at TIMESTAMP NULL DEFAULT NULL,
            registered_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_chat_customer_invitation_session (session_id),
            UNIQUE KEY uniq_chat_customer_invitation_token (invite_token),
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
            INDEX idx_chat_customer_invitation_card (business_card_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

/** 推測できない専用URL用トークン（64桁の16進）。 */
function customerInviteGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

/** トークンの形式チェック（DBへ問い合わせる前に弾く）。 */
function customerInviteIsValidToken(string $token): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}$/', $token);
}

/** 姓・名を「姓　名」（全角スペース区切り）に整形する。 */
function customerInviteFullName(string $lastName, string $firstName): string
{
    $last = trim($lastName);
    $first = trim($firstName);
    if ($last !== '' && $first !== '') return $last . '　' . $first;
    return $last !== '' ? $last : $first;
}

/** 招待レコードを取得（トークン検索）。名刺のslugも一緒に返す。 */
function customerInviteFindByToken(PDO $db, string $token): ?array
{
    $token = trim($token);
    if (!customerInviteIsValidToken($token)) return null;
    try {
        customerInviteEnsureTable($db);
        $stmt = $db->prepare(
            "SELECT ci.*, bc.url_slug, bc.name AS agent_name
             FROM chat_customer_invitations ci
             JOIN business_cards bc ON bc.id = ci.business_card_id
             WHERE ci.invite_token = ?
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('customerInviteFindByToken error: ' . $e->getMessage());
        return null;
    }
}

/** 招待レコードを取得（セッション検索）。 */
function customerInviteFindBySession(PDO $db, string $sessionId): ?array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') return null;
    try {
        customerInviteEnsureTable($db);
        $stmt = $db->prepare("SELECT * FROM chat_customer_invitations WHERE session_id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('customerInviteFindBySession error: ' . $e->getMessage());
        return null;
    }
}

/**
 * 顧客専用URL。名刺ページではなく AIエージェントページを最初に表示させるため
 * chat=1 を付ける（card.php の $chatOnly）。
 */
function customerInviteUrl(string $cardSlug, string $token): string
{
    return rtrim(BASE_URL, '/') . '/card.php?slug=' . rawurlencode($cardSlug)
        . '&chat=1&invite=' . rawurlencode($token);
}

/** 顧客が専用URLを開いた印。 */
function customerInviteMarkOpened(PDO $db, string $sessionId): void
{
    try {
        customerInviteEnsureTable($db);
        $stmt = $db->prepare(
            "UPDATE chat_customer_invitations
             SET status = 'opened', opened_at = COALESCE(opened_at, NOW())
             WHERE session_id = ? AND status = 'sent'"
        );
        $stmt->execute([$sessionId]);
    } catch (Throwable $e) {
        error_log('customerInviteMarkOpened error: ' . $e->getMessage());
    }
}

/** 顧客本人の登録（SMS認証＋氏名＋メール）が完了した印。 */
function customerInviteMarkRegistered(PDO $db, string $sessionId): void
{
    try {
        customerInviteEnsureTable($db);
        $stmt = $db->prepare(
            "UPDATE chat_customer_invitations
             SET status = 'registered', registered_at = COALESCE(registered_at, NOW())
             WHERE session_id = ? AND status <> 'registered'"
        );
        $stmt->execute([$sessionId]);
    } catch (Throwable $e) {
        error_log('customerInviteMarkRegistered error: ' . $e->getMessage());
    }
}

/**
 * 事前作成したセッションを、SMS認証で判明した既存セッションへ引き継ぐ。
 *
 * 既存顧客（この名刺で認証済みの電話番号）が専用URLからSMS認証すると、
 * phone/verify.php はその顧客の既存セッションへ合流させる。このとき事前作成した
 * 空のセッションが取り残され、担当の顧客一覧に中身のない顧客が二重に並んでしまう。
 * そこで招待レコードを合流先へ付け替え、空のセッションを削除する。
 *
 * 削除するのは「中身が何も無い事前作成セッション」だけに厳しく限定する。
 * chat_sessions の削除は chat_lead_contacts / chat_leads / chat_session_devices などへ
 * カスケードするため、判定を誤ると顧客の登録内容ごと消えてしまう。
 * 発言が無くても、SMS認証・お名前・メールアドレスの登録が済んでいれば消さない
 * （登録直後に一度も発言せず離脱した顧客が、この処理で消えることを防ぐ）。
 */
function customerInviteTransferSession(PDO $db, string $fromSessionId, string $toSessionId, int $businessCardId): void
{
    $fromSessionId = trim($fromSessionId);
    $toSessionId = trim($toSessionId);
    if ($fromSessionId === '' || $toSessionId === '' || $fromSessionId === $toSessionId || $businessCardId <= 0) {
        return;
    }
    try {
        customerInviteEnsureTable($db);

        $invite = customerInviteFindBySession($db, $fromSessionId);
        if (!$invite) {
            return; // 事前作成されたセッションではない。通常の合流なので何もしない。
        }
        // 呼び出し元の名刺に属する招待・セッションだけを対象にする
        // （client から渡る session_id を、名刺の裏取り無しに削除の根拠にしない）。
        if ((int)$invite['business_card_id'] !== $businessCardId) {
            return;
        }
        if ((string)$invite['status'] === 'registered') {
            return; // 既にお客様のご登録が済んでいる。実体のある顧客ページなので触らない。
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM chat_sessions WHERE id = ? AND business_card_id = ?");
        $stmt->execute([$fromSessionId, $businessCardId]);
        if (((int)$stmt->fetchColumn()) === 0) {
            return;
        }

        // 発言・連絡先・ヒアリングのいずれかがあれば「中身のあるセッション」として残す。
        foreach ([
            "SELECT COUNT(*) FROM chat_messages WHERE session_id = ?",
            "SELECT COUNT(*) FROM chat_lead_contacts WHERE session_id = ?",
            "SELECT COUNT(*) FROM chat_leads WHERE session_id = ?",
        ] as $countSql) {
            $stmt = $db->prepare($countSql);
            $stmt->execute([$fromSessionId]);
            if (((int)$stmt->fetchColumn()) > 0) {
                return;
            }
        }

        // 合流先に既に招待がある場合は付け替えられない（session_id が UNIQUE）ので、
        // 元の招待レコードだけを消す（下のセッション削除でカスケードされる）。
        $existing = customerInviteFindBySession($db, $toSessionId);
        if (!$existing) {
            $stmt = $db->prepare("UPDATE chat_customer_invitations SET session_id = ? WHERE session_id = ?");
            $stmt->execute([$toSessionId, $fromSessionId]);
        }

        $stmt = $db->prepare("DELETE FROM chat_sessions WHERE id = ? AND business_card_id = ?");
        $stmt->execute([$fromSessionId, $businessCardId]);
    } catch (Throwable $e) {
        error_log('customerInviteTransferSession error: ' . $e->getMessage());
    }
}

/**
 * 専用URLを開いた顧客に最初に表示するチャットメッセージ。
 * この直後に、通常どおりSMS認証フォームが表示される。
 */
function customerInviteWelcomeMessage(string $customerName): string
{
    $name = trim($customerName);
    $label = $name !== '' ? $name . '様専用' : 'あなた専用';
    return "{$label}AIエージェントへようこそ。\n"
        . "このページでは、不動産の売買・賃貸に関するご相談や、お取引の進捗状況などを一元管理できます。\n\n"
        . "また、安全にメッセージや物件情報をお届けするため、チャット履歴や進捗情報を保存し、"
        . "機種変更や別の端末からでも続きからご利用頂くために、最初にSMS認証をお願いいたします。";
}

/**
 * 招待メールの件名・本文を組み立てる。文面は依頼どおり。
 *
 * @return array [subject, html, text]
 */
function customerInviteBuildEmail(string $agentName, string $customerName, string $url): array
{
    $agent = trim($agentName) !== '' ? trim($agentName) : '担当者';
    $customer = trim($customerName);
    $customerLabel = $customer !== '' ? $customer . '様' : 'お客様';

    $subject = "{$agent}様より、メッセージが届いています";

    $lines = [
        "{$customerLabel}",
        'お世話になっております。',
        "{$agent}です。",
        "{$customerLabel}専用の「AIエージェント」をご用意いたしました。",
        '',
        'このページでは、',
        '・いつでも相談できるAIエージェント',
        '・査定書や物件のご提案',
        '・メッセージのやり取り',
        '・お取引の進捗確認',
        'などを一元管理できます。',
        '',
        "{$customerLabel}様ご本人であることを確認するため、AIエージェントページを開く際にSMS認証をお願いしております。",
        'ご相談やご要望がございましたら、「AIエージェントページ」からいつでもお気軽にご連絡ください。',
    ];

    $divider = '━━━━━━━━━━━━━━';
    $text = implode("\n", $lines) . "\n\n"
        . $divider . "\n"
        . "{$customerLabel}専用 AIエージェントページ\n"
        . $url . "\n"
        . $divider . "\n\n"
        . "どうぞよろしくお願いいたします。\n";

    $htmlLines = '';
    foreach ($lines as $line) {
        $htmlLines .= $line === ''
            ? '<p style="margin:0;">&nbsp;</p>'
            : '<p style="margin:0;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeCustomerLabel = htmlspecialchars($customerLabel, ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.9;color:#333;">'
        . $htmlLines
        . '<div style="margin:24px 0;padding:16px 0;border-top:1px solid #0757d7;border-bottom:1px solid #0757d7;">'
        . '<p style="margin:0 0 12px;font-weight:bold;">' . $safeCustomerLabel . '専用 AIエージェントページ</p>'
        . '<p style="margin:0;"><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;background:#0757d7;color:#fff;text-decoration:none;border-radius:4px;">AIエージェントページを開く</a></p>'
        . '<p style="margin:12px 0 0;font-size:12px;word-break:break-all;"><a href="' . $safeUrl . '" style="color:#0757d7;">' . $safeUrl . '</a></p>'
        . '</div>'
        . '<p style="margin:0;">どうぞよろしくお願いいたします。</p>'
        . '</div>';

    return [$subject, $html, $text];
}

/**
 * 招待メールを送信する。
 *
 * @return bool 送信できたら true
 */
function customerInviteSendEmail(string $to, string $agentName, string $customerName, string $url, ?int $invitationId = null): bool
{
    [$subject, $html, $text] = customerInviteBuildEmail($agentName, $customerName, $url);
    return sendEmail($to, $subject, $html, $text, 'customer_invitation', null, $invitationId);
}

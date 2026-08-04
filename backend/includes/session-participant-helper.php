<?php
/**
 * 1案件（chat_sessions）に最大2名の顧客を参加させるためのヘルパー。
 * -------------------------------------------------------------
 * 夫婦など2名で検討する顧客向け。2人目を「同じ session_id」に合流させ、
 * 案件・履歴・資料・提案・進捗を自動共有する。chat_sessions 本体はいじらず、
 * 2名分の氏名・メール・電話・認証状態は chat_session_participants に保持する。
 * （chat_lead_contacts は session_id が UNIQUE で1名分しか持てないため。）
 *
 *   role='primary' … 最初に登録した本人（案件の作成者）。
 *   role='partner' … primary が「ご家族を招待」した2人目。
 *
 * 招待は「メール送信＋共有リンク」方式（サーバーからのSMS自動送信は行わない）。
 * 2人目は招待URLを開き、自分の電話番号でSMS認証して同じ案件に参加する。
 *
 * 設計メモ:
 *   詳細は migrations/20260804_add_session_participants.sql の冒頭コメント参照。
 */

require_once __DIR__ . '/../config/config.php'; // BASE_URL
require_once __DIR__ . '/functions.php';        // sendEmail()
require_once __DIR__ . '/chat-phone-helper.php'; // chatPhoneLookupKey(), chatCleanCustomerNameValue()

/** テーブル・列が無ければ作成（冪等）。migrations/20260804 と同じ定義。 */
function participantEnsureSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $db->exec(
        "CREATE TABLE IF NOT EXISTS chat_session_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id CHAR(36) NOT NULL,
            business_card_id INT NOT NULL,
            role ENUM('primary','partner') NOT NULL,
            invite_token CHAR(64) NULL DEFAULT NULL,
            phone_normalized VARCHAR(32) NULL DEFAULT NULL,
            email VARCHAR(255) NULL DEFAULT NULL,
            display_name VARCHAR(255) NULL DEFAULT NULL,
            firebase_uid VARCHAR(128) NULL DEFAULT NULL,
            status ENUM('invited','registered','removed') NOT NULL DEFAULT 'invited',
            invited_at TIMESTAMP NULL DEFAULT NULL,
            registered_at TIMESTAMP NULL DEFAULT NULL,
            removed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_session_role (session_id, role),
            UNIQUE KEY uniq_participant_invite_token (invite_token),
            INDEX idx_participant_session (session_id, status),
            INDEX idx_participant_card_phone (business_card_id, phone_normalized),
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // chat_messages.author_participant_id（誰の発言か）。SHOW COLUMNS で存在確認してから追加する。
    // `SHOW COLUMNS ... LIKE ?` は native prepare 下で例外になるため、プレースホルダを使わない
    // （chat-phone-helper.php の ensureChatSessionDevicesTable と同じ理由）。
    try {
        $existing = [];
        foreach ($db->query("SHOW COLUMNS FROM chat_messages") as $row) {
            $existing[$row['Field']] = true;
        }
        if (!isset($existing['author_participant_id'])) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN author_participant_id INT NULL DEFAULT NULL AFTER sender_user_id");
        }
    } catch (Throwable $e) {
        error_log('participantEnsureSchema author column check failed: ' . $e->getMessage());
    }

    $done = true;
}

/** 推測できない招待URL用トークン（64桁の16進）。 */
function participantGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

/** トークンの形式チェック（DBへ問い合わせる前に弾く）。 */
function participantIsValidToken(string $token): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}$/', trim($token));
}

/**
 * 2人目の招待URL。名刺ページではなく AIエージェントページを最初に表示させるため
 * chat=1 を付ける（card.php の $chatOnly）。couple=<token> で合流先セッションを指す。
 */
function participantInviteUrl(string $cardSlug, string $token): string
{
    return rtrim(BASE_URL, '/') . '/card.php?slug=' . rawurlencode($cardSlug)
        . '&chat=1&couple=' . rawurlencode($token);
}

/** 参加中（解除されていない）の参加者一覧を返す。primary→partner の順。 */
function participantListActive(PDO $db, string $sessionId): array
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') return [];
    try {
        participantEnsureSchema($db);
        $stmt = $db->prepare(
            "SELECT id, role, status, email, display_name, phone_normalized, invited_at, registered_at
             FROM chat_session_participants
             WHERE session_id = ? AND status <> 'removed'
             ORDER BY FIELD(role, 'primary', 'partner'), id ASC"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('participantListActive error: ' . $e->getMessage());
        return [];
    }
}

/** 参加中の人数（primary 含む）。 */
function participantCountActive(PDO $db, string $sessionId): int
{
    return count(participantListActive($db, $sessionId));
}

/** 招待トークンから partner 行を取得（名刺の url_slug も一緒に返す）。 */
function participantFindByToken(PDO $db, string $token): ?array
{
    $token = trim($token);
    if (!participantIsValidToken($token)) return null;
    try {
        participantEnsureSchema($db);
        $stmt = $db->prepare(
            "SELECT p.*, bc.url_slug, bc.name AS agent_name
             FROM chat_session_participants p
             JOIN business_cards bc ON bc.id = p.business_card_id
             WHERE p.invite_token = ? AND p.role = 'partner'
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('participantFindByToken error: ' . $e->getMessage());
        return null;
    }
}

/**
 * primary（本人）行を作成／更新する（冪等）。案件に必ず1行だけ存在させる。
 * 本人がSMS認証した時（verify.php）や、家族を招待する時（invite-partner.php）に呼ぶ。
 * 既存の値は空文字で上書きしない（COALESCE(NULLIF(...)))。
 *
 * @return int primary 参加者の id（失敗時 0）
 */
function participantEnsurePrimary(PDO $db, string $sessionId, int $businessCardId, string $phoneNormalized = '', string $email = '', string $displayName = '', string $firebaseUid = ''): int
{
    $sessionId = trim($sessionId);
    if ($sessionId === '' || $businessCardId <= 0) return 0;
    try {
        participantEnsureSchema($db);
        $phone = trim($phoneNormalized);
        $email = trim($email);
        $name = chatCleanCustomerNameValue($displayName);
        $uid = trim($firebaseUid);
        $stmt = $db->prepare(
            "INSERT INTO chat_session_participants
                (session_id, business_card_id, role, phone_normalized, email, display_name, firebase_uid, status, registered_at)
             VALUES (?, ?, 'primary', ?, ?, ?, ?, 'registered', NOW())
             ON DUPLICATE KEY UPDATE
                phone_normalized = COALESCE(NULLIF(VALUES(phone_normalized), ''), phone_normalized),
                email            = COALESCE(NULLIF(VALUES(email), ''), email),
                display_name     = COALESCE(NULLIF(VALUES(display_name), ''), display_name),
                firebase_uid     = COALESCE(NULLIF(VALUES(firebase_uid), ''), firebase_uid),
                status           = 'registered',
                registered_at    = COALESCE(registered_at, NOW()),
                updated_at       = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            $sessionId, $businessCardId,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
            $name !== '' ? $name : null,
            $uid !== '' ? $uid : null,
        ]);
        $stmt = $db->prepare("SELECT id FROM chat_session_participants WHERE session_id = ? AND role = 'primary' LIMIT 1");
        $stmt->execute([$sessionId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('participantEnsurePrimary error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * 2人目（partner）を招待する。招待メール用のトークンと partner 行を作る。
 * すでに参加中の partner がいれば拒否（1案件2名まで）。解除済みの partner は再招待できる。
 *
 * @return array ['ok'=>bool, 'token'=>string, 'participant_id'=>int, 'error'=>string]
 */
function participantCreatePartnerInvite(PDO $db, string $sessionId, int $businessCardId, string $email, string $displayName): array
{
    $sessionId = trim($sessionId);
    $email = trim($email);
    $name = chatCleanCustomerNameValue($displayName);
    if ($sessionId === '' || $businessCardId <= 0) {
        return ['ok' => false, 'error' => 'invalid_session'];
    }
    if ($email === '' || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_email'];
    }
    try {
        participantEnsureSchema($db);
        $token = participantGenerateToken();

        $stmt = $db->prepare("SELECT id, status FROM chat_session_participants WHERE session_id = ? AND role = 'partner' LIMIT 1");
        $stmt->execute([$sessionId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (in_array((string)$existing['status'], ['invited', 'registered'], true)) {
                // すでに2人目が参加中／招待中。まず解除してからでないと新しい方は招待できない。
                return ['ok' => false, 'error' => 'partner_exists'];
            }
            // removed 済みの partner 行を再利用して再招待する（電話番号・UIDはリセット）。
            $stmt = $db->prepare(
                "UPDATE chat_session_participants
                 SET invite_token = ?, email = ?, display_name = ?, status = 'invited',
                     phone_normalized = NULL, firebase_uid = NULL,
                     invited_at = NOW(), registered_at = NULL, removed_at = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$token, $email, $name !== '' ? $name : null, (int)$existing['id']]);
            return ['ok' => true, 'token' => $token, 'participant_id' => (int)$existing['id'], 'error' => ''];
        }

        $stmt = $db->prepare(
            "INSERT INTO chat_session_participants
                (session_id, business_card_id, role, invite_token, email, display_name, status, invited_at)
             VALUES (?, ?, 'partner', ?, ?, ?, 'invited', NOW())"
        );
        $stmt->execute([$sessionId, $businessCardId, $token, $email, $name !== '' ? $name : null]);
        return ['ok' => true, 'token' => $token, 'participant_id' => (int)$db->lastInsertId(), 'error' => ''];
    } catch (Throwable $e) {
        error_log('participantCreatePartnerInvite error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'server_error'];
    }
}

/**
 * 招待された2人目のSMS認証完了を partner 行へ反映する（トークンで特定）。
 * 氏名・メールは招待時の申告値を維持し、電話番号・UID・認証状態だけ更新する。
 *
 * @return string 合流先の session_id（失敗時は ''）
 */
function participantRegisterPartnerByToken(PDO $db, string $token, int $businessCardId, string $phoneNormalized, string $firebaseUid = ''): string
{
    $token = trim($token);
    if (!participantIsValidToken($token) || $businessCardId <= 0) return '';
    try {
        participantEnsureSchema($db);
        $row = participantFindByToken($db, $token);
        if (!$row || (int)$row['business_card_id'] !== $businessCardId) return '';
        $phone = trim($phoneNormalized);
        // 招待した本人(primary)と同じ電話番号でSMS認証した場合は、2人目として扱わない
        // （本人が自分の番号を招待した等）。'' を返し、呼び出し側の通常フローに委ねる。
        if ($phone !== '') {
            $stmt = $db->prepare("SELECT 1 FROM chat_session_participants WHERE session_id = ? AND role = 'primary' AND phone_normalized = ? LIMIT 1");
            $stmt->execute([(string)$row['session_id'], $phone]);
            if ($stmt->fetchColumn()) return '';
        }
        $stmt = $db->prepare(
            "UPDATE chat_session_participants
             SET phone_normalized = COALESCE(NULLIF(?, ''), phone_normalized),
                 firebase_uid     = COALESCE(NULLIF(?, ''), firebase_uid),
                 status           = 'registered',
                 registered_at    = COALESCE(registered_at, NOW()),
                 removed_at       = NULL,
                 updated_at       = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$phone, trim($firebaseUid), (int)$row['id']]);
        return (string)$row['session_id'];
    } catch (Throwable $e) {
        error_log('participantRegisterPartnerByToken error: ' . $e->getMessage());
        return '';
    }
}

/**
 * メッセージの投稿者（参加者id）を、送信端末の電話番号から特定する。
 * 参加者が1名（primary のみ）または該当なしの場合は null（＝従来どおり投稿者ラベルなし）。
 */
function participantResolveAuthorId(PDO $db, string $sessionId, ?string $phoneNormalized): ?int
{
    $sessionId = trim($sessionId);
    $phone = trim((string)$phoneNormalized);
    if ($sessionId === '' || $phone === '') return null;
    try {
        participantEnsureSchema($db);
        // 2名とも本人確認（SMS認証）を済ませている時だけ投稿者を記録する。
        // 招待しただけ（partner が invited のまま）の段階では、本人の発言に不要なラベルを付けない。
        $stmt = $db->prepare("SELECT COUNT(*) FROM chat_session_participants WHERE session_id = ? AND status = 'registered'");
        $stmt->execute([$sessionId]);
        if ((int)$stmt->fetchColumn() < 2) return null;
        $stmt = $db->prepare(
            "SELECT id FROM chat_session_participants
             WHERE session_id = ? AND phone_normalized = ? AND status <> 'removed'
             ORDER BY FIELD(role, 'primary', 'partner') LIMIT 1"
        );
        $stmt->execute([$sessionId, $phone]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null ? (int)$id : null;
    } catch (Throwable $e) {
        error_log('participantResolveAuthorId error: ' . $e->getMessage());
        return null;
    }
}

/**
 * 保存済みメッセージに投稿者（参加者id）を後付けで記録する。
 * $authorParticipantId が null/0 なら何もしない（＝単独案件・AI・担当の発言）。
 */
function chatStampMessageAuthor(PDO $db, int $messageId, ?int $authorParticipantId): void
{
    if ($messageId <= 0 || $authorParticipantId === null || (int)$authorParticipantId <= 0) return;
    try {
        participantEnsureSchema($db);
        $stmt = $db->prepare("UPDATE chat_messages SET author_participant_id = ? WHERE id = ?");
        $stmt->execute([(int)$authorParticipantId, $messageId]);
    } catch (Throwable $e) {
        error_log('chatStampMessageAuthor error: ' . $e->getMessage());
    }
}

/**
 * 端末の電話番号に対応する参加者の表示名を返す（見つからなければ ''）。
 * 共有セッションを開いた端末が「本人」か「ご家族」かで、正しい方のお名前を表示するために使う。
 */
function participantNameForPhone(PDO $db, string $sessionId, ?string $phoneNormalized): string
{
    $sessionId = trim($sessionId);
    $phone = trim((string)$phoneNormalized);
    if ($sessionId === '' || $phone === '') return '';
    try {
        participantEnsureSchema($db);
        $stmt = $db->prepare(
            "SELECT display_name FROM chat_session_participants
             WHERE session_id = ? AND phone_normalized = ? AND status <> 'removed'
             ORDER BY FIELD(role, 'primary', 'partner') LIMIT 1"
        );
        $stmt->execute([$sessionId, $phone]);
        return chatCleanCustomerNameValue($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('participantNameForPhone error: ' . $e->getMessage());
        return '';
    }
}

/**
 * 参加者id→表示名・ロールのマップ。メッセージ一覧に投稿者名を付ける時に使う。
 * 解除済みの参加者も含めて解決する（過去の発言の投稿者表示のため）。
 */
function participantNamesByIds(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    try {
        participantEnsureSchema($db);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, role, display_name FROM chat_session_participants WHERE id IN ($place)");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['id']] = [
                'role' => (string)$row['role'],
                'name' => chatCleanCustomerNameValue($row['display_name'] ?? ''),
            ];
        }
        return $map;
    } catch (Throwable $e) {
        error_log('participantNamesByIds error: ' . $e->getMessage());
        return [];
    }
}

/**
 * 案件の参加者全員（参加中）のメールアドレス一覧。通知の配信先に使う。
 * @return string[] 重複排除済みの有効なメールアドレス
 */
function participantActiveEmails(PDO $db, string $sessionId): array
{
    $out = [];
    foreach (participantListActive($db, $sessionId) as $p) {
        $email = trim((string)($p['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($email)] = $email;
        }
    }
    return array_values($out);
}

/**
 * 2人目の参加を解除する（primary の操作）。行は残し status='removed' にする。
 * 併せて2人目のSMS認証済み端末を認可集合から外し、以後アクセスできないようにする。
 *
 * @return bool 解除できたら true
 */
function participantRemovePartner(PDO $db, string $sessionId): bool
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') return false;
    try {
        participantEnsureSchema($db);
        $stmt = $db->prepare("SELECT id, phone_normalized FROM chat_session_participants WHERE session_id = ? AND role = 'partner' AND status <> 'removed' LIMIT 1");
        $stmt->execute([$sessionId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$partner) return false;

        $stmt = $db->prepare(
            "UPDATE chat_session_participants
             SET status = 'removed', removed_at = NOW(), invite_token = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([(int)$partner['id']]);

        // 2人目の端末の閲覧権を失効させる（電話番号で突合できる端末行を削除）。
        $partnerPhone = trim((string)($partner['phone_normalized'] ?? ''));
        if ($partnerPhone !== '') {
            try {
                $stmt = $db->prepare("DELETE FROM chat_session_devices WHERE session_id = ? AND phone_normalized = ?");
                $stmt->execute([$sessionId, $partnerPhone]);
            } catch (Throwable $e) {
                error_log('participantRemovePartner device revoke failed: ' . $e->getMessage());
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('participantRemovePartner error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 招待URLを開いた2人目（ご家族）に最初に表示する歓迎メッセージ。
 * この直後に、通常どおりSMS認証フォームが表示される（2人目は自分の電話番号で認証）。
 */
function participantCoupleWelcomeMessage(string $partnerName, string $agentName): string
{
    $name = trim($partnerName);
    $label = $name !== '' ? $name . '様' : 'ご家族の方';
    $agent = trim($agentName) !== '' ? trim($agentName) : '担当者';
    return "{$label}、ようこそ。\n"
        . "こちらは、ご家族の方と同じ内容を共有できるAIエージェントページです。\n"
        . "不動産のご相談、提案物件や資料、日程・進捗などを一緒にご確認いただけます。\n\n"
        . "ご本人であることを確認するため、はじめにご自身の携帯電話番号でSMS認証をお願いいたします。"
        . "（{$agent}が対応いたします。）";
}

/**
 * 招待メールの件名・本文を組み立てる。差出人は「案件の本人（inviter）」＋担当の会社/氏名。
 *
 * @return array [subject, html, text]
 */
function participantBuildInviteEmail(string $inviterName, string $agentName, string $url, string $companyName = ''): array
{
    $inviter = trim($inviterName);
    $inviterLabel = $inviter !== '' ? $inviter . '様' : 'ご家族の方';
    $agent = trim($agentName) !== '' ? trim($agentName) : '担当者';
    $company = trim($companyName);
    $agentLabel = $company !== '' ? $company . '　' . $agent : $agent;

    $subject = '【不動産AI名刺】' . $inviterLabel . 'より、AIエージェントページへの参加のご案内';

    $lines = [
        'お世話になっております。',
        $agentLabel . 'です。',
        '',
        $inviterLabel . 'より、不動産のご相談を一緒に進めるメンバーとしてご招待がありました。',
        '下記のAIエージェントページから、',
        '・いつでも相談できるAIエージェント',
        '・査定書や物件のご提案',
        '・メッセージのやり取り',
        '・お取引の進捗確認',
        'などを、' . $inviterLabel . 'と同じ内容でご確認いただけます。',
        '',
        'ご参加にあたっては、ご本人であることを確認するため、ページを開く際に'
            . 'ご自身の携帯電話番号でのSMS認証をお願いしております。',
    ];

    $divider = '━━━━━━━━━━━━━━';
    $text = implode("\n", $lines) . "\n\n"
        . $divider . "\n"
        . "参加用ページ（SMS認証）\n"
        . $url . "\n"
        . $divider . "\n\n"
        . "※このメールにお心当たりがない場合は破棄してください。\n"
        . "どうぞよろしくお願いいたします。\n";

    $htmlLines = '';
    foreach ($lines as $line) {
        $htmlLines .= $line === ''
            ? '<p style="margin:0;">&nbsp;</p>'
            : '<p style="margin:0;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.9;color:#333;">'
        . $htmlLines
        . '<div style="margin:24px 0;padding:16px 0;border-top:1px solid #0757d7;border-bottom:1px solid #0757d7;">'
        . '<p style="margin:0 0 12px;font-weight:bold;">参加用ページ（SMS認証）</p>'
        . '<p style="margin:0;"><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;background:#0757d7;color:#fff;text-decoration:none;border-radius:4px;">ページを開く</a></p>'
        . '<p style="margin:12px 0 0;font-size:12px;word-break:break-all;"><a href="' . $safeUrl . '" style="color:#0757d7;">' . $safeUrl . '</a></p>'
        . '</div>'
        . '<p style="margin:0;font-size:12px;color:#888;">※このメールにお心当たりがない場合は破棄してください。</p>'
        . '<p style="margin:8px 0 0;">どうぞよろしくお願いいたします。</p>'
        . '</div>';

    return [$subject, $html, $text];
}

/**
 * 招待メールを送信する。
 * @return bool 送信できたら true
 */
function participantSendInviteEmail(string $to, string $inviterName, string $agentName, string $url, string $companyName = ''): bool
{
    [$subject, $html, $text] = participantBuildInviteEmail($inviterName, $agentName, $url, $companyName);
    return sendEmail($to, $subject, $html, $text, 'partner_invitation');
}

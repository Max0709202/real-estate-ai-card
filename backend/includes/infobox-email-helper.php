<?php
/**
 * 情報BOX 利用通知メール（仕様 第6章 6-1）と送信処理（6-2）。
 *
 * ・送信対象ごとに 未送信／送信中／送信済み／失敗 を保持し、送信中の二重送信を防ぐ。
 * ・送信に失敗しても、登録内容は巻き戻さない。
 * ・チャット本文・非公開書類名はメールに載せない。
 * ・受信者は自分で登録した覚えがないため、照合に使う「登録メールアドレス」「登録電話番号」を
 *   本文に明記する（画面7へのお客様からのご指示）。
 */

require_once __DIR__ . '/infobox-helper.php';

/** 売買価格の表示（未設定と0円を区別する）。 */
function iboxPriceText($price): string
{
    if ($price === null || $price === '') return '未設定';
    return number_format((int)$price) . '円';
}

/**
 * 利用通知メールの件名・本文を組み立てる。
 * @return array{subject:string, lines:string[], url:string}
 */
function iboxBuildInviteMail(array $box, array $recipient, array $sender, string $url): array
{
    $roles = iboxRoles();
    $isPerson = ($roles[$recipient['role']]['kind'] ?? 'company') === 'person';
    $recipientName = trim((string)$recipient['name']);
    $company = trim((string)$recipient['company_name']);
    $salutation = $isPerson
        ? ($recipientName !== '' ? $recipientName : 'ご関係者') . '様'
        : trim(($company !== '' ? $company . ' ' : '') . ($recipientName !== '' ? $recipientName : 'ご担当者')) . '様';

    $propertyName = trim((string)$box['property_name']) !== '' ? (string)$box['property_name'] : '対象物件';
    $senderCompany = trim((string)($sender['company_name'] ?? ''));
    $senderName = iboxParticipantName($sender);

    $lines = [
        $salutation,
        '',
        'お世話になっております。' . ($senderCompany !== '' ? $senderCompany . 'の' : '') . $senderName . 'です。',
        '下記のお取引に必要な書類とご連絡を共有するため、不動産AI名刺の情報BOXをご用意しました。',
        '',
        '物件名：' . $propertyName,
        '所在地：' . (trim((string)$box['address']) !== '' ? $box['address'] : '未設定'),
        '売買価格：' . iboxPriceText($box['price']),
        '',
        '---------------------------------',
        '下記URLからアクセスし、',
        '登録メールアドレス「' . (string)$recipient['email'] . '」',
        '登録電話番号「' . (string)$recipient['phone'] . '」',
        'と、ご入力ください。',
        '---------------------------------',
        $url,
        '（このURLの有効期限は' . IBOX_INVITE_DAYS . '日間です。期限が切れた場合も、同じURLから再発行できます）',
        '',
        '書類は、アップロードしたご本人と、ご本人が指定した相手だけが閲覧・印刷できます。名刺所有者や管理者でも、指定がない書類は閲覧できません。書類を変更・削除できるのは、アップロードしたご本人だけです。',
        '',
        '書類担当者に指定された方は、担当フォルダーへの書類登録をお願いいたします。登録時には、閲覧・印刷を許可する相手をお名前でご確認ください。',
        '',
        iboxColorNotice(),
        '',
        '個別チャットは相手とご本人だけが閲覧できます。全員チャットは関係者全員に公開されます。個人間のご相談は個別チャットをご利用ください。ローン審査結果は投稿しないでください。',
        '',
        iboxAccessNotice($box, $recipient),
        'ご不明点は情報BOX内の個別チャットで担当者へご連絡ください。',
        '',
        'お問い合わせ：' . trim($senderCompany . ' ' . $senderName),
        '電話：' . (trim((string)($sender['phone'] ?? '')) !== '' ? $sender['phone'] : '—'),
    ];

    return [
        'subject' => '［不動産AI名刺］' . $propertyName . 'の情報BOX利用のご案内',
        'lines' => $lines,
        'url' => $url,
    ];
}

/** 行配列を HTML / テキスト本文にする。 */
function iboxComposeMail(array $built): array
{
    $html = '';
    foreach ($built['lines'] as $line) {
        $line = (string)$line;
        if (trim($line) === '') { $html .= '<p style="margin:0 0 8px 0;">&nbsp;</p>'; continue; }
        $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        if ($line === $built['url']) {
            $html .= '<p style="margin:12px 0;"><a href="' . $escaped . '" style="display:inline-block;padding:12px 24px;background:#136f86;color:#fff;text-decoration:none;border-radius:6px;">情報BOXを開く</a><br>'
                . '<span style="font-size:12px;color:#666;word-break:break-all;">' . $escaped . '</span></p>';
            continue;
        }
        $html .= '<p style="margin:0 0 8px 0;">' . $escaped . '</p>';
    }
    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.8;color:#333;">' . $html . '</div>';
    $text = implode("\n", array_map('strval', $built['lines'])) . "\n";
    return [$html, $text];
}

/** 通知に必要な項目（氏名・メール・電話）の不足。 */
function iboxNotifyMissingFields(array $p): array
{
    $missing = [];
    if (trim((string)$p['name']) === '') $missing[] = '氏名';
    if (!filter_var(trim((string)$p['email']), FILTER_VALIDATE_EMAIL)) $missing[] = 'メールアドレス';
    if (strlen(iboxNormalizePhone($p['phone'] ?? '')) < 10) $missing[] = '電話番号';
    return $missing;
}

/**
 * 1名に利用通知を送る。送信中の二重送信は行ロックで防ぐ。
 * @return array{ok:bool, status:string, message:string}
 */
function iboxSendInvite(PDO $db, array $box, array $target, array $sender): array
{
    $missing = iboxNotifyMissingFields($target);
    if ($missing) {
        return ['ok' => false, 'status' => (string)$target['notify_status'], 'message' => implode('・', $missing) . 'が未入力です。'];
    }

    // 「送信中」への切り替えに成功した1リクエストだけが送る（連打・並行送信での重複を防ぐ）。
    $stmt = $db->prepare("UPDATE ibox_participants SET notify_status = 'sending', updated_at = ? WHERE id = ? AND notify_status <> 'sending' AND status = 'active'");
    $stmt->execute([iboxNow(), (int)$target['id']]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'status' => 'sending', 'message' => '送信処理中です。しばらくお待ちください。'];
    }

    $ok = false;
    $error = '';
    try {
        $fresh = iboxLoadParticipant($db, (int)$target['id']) ?: $target;
        $token = iboxIssueInvite($db, $fresh);
        $built = iboxBuildInviteMail($box, $fresh, $sender, iboxInviteUrl($token));
        [$html, $text] = iboxComposeMail($built);
        $ok = sendEmail((string)$fresh['email'], $built['subject'], $html, $text, 'infobox_invite', null, (int)$box['id']);
        if (!$ok) $error = 'メール送信に失敗しました';
    } catch (Throwable $e) {
        $error = 'メール送信に失敗しました';
        error_log('iboxSendInvite error: ' . $e->getMessage());
    }

    $status = $ok ? 'sent' : 'failed';
    $db->prepare('UPDATE ibox_participants SET notify_status = ?, notified_at = ?, notify_error = ?, updated_at = ? WHERE id = ?')
        ->execute([$status, $ok ? iboxNow() : $target['notified_at'], $ok ? null : $error, iboxNow(), (int)$target['id']]);
    iboxAudit($db, (int)$box['id'], (int)$sender['id'], $ok ? 'notify_sent' : 'notify_failed', 'participant', (int)$target['id']);

    return ['ok' => $ok, 'status' => $status, 'message' => $ok ? '送信しました' : $error];
}

/** 期限切れURLの再発行：登録メール・電話が一致した方の登録メールアドレスへ、新しいURLを送る。 */
function iboxSendReissue(PDO $db, array $box, array $p): bool
{
    $token = iboxIssueInvite($db, $p);
    $url = iboxInviteUrl($token);
    $propertyName = trim((string)$box['property_name']) !== '' ? (string)$box['property_name'] : '対象物件';
    $built = [
        'subject' => '［不動産AI名刺］' . $propertyName . 'の情報BOX アクセス用URLの再発行',
        'lines' => [
            iboxParticipantName($p) . '様',
            '',
            '情報BOXのアクセス用URLを再発行しました。下記URLから、登録済みのメールアドレスと電話番号を入力してください。',
            $url,
            '（このURLの有効期限は' . IBOX_INVITE_DAYS . '日間です）',
            '',
            'お心当たりのない場合は、このメールを破棄してください。',
        ],
        'url' => $url,
    ];
    [$html, $text] = iboxComposeMail($built);
    try {
        return sendEmail((string)$p['email'], $built['subject'], $html, $text, 'infobox_reissue', null, (int)$box['id']);
    } catch (Throwable $e) {
        error_log('iboxSendReissue error: ' . $e->getMessage());
        return false;
    }
}

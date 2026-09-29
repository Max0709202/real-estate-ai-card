<?php
/**
 * 招待された関係者のアクセス（仕様 画面8・第6章）。
 *
 * POST {action:'verify', token, email, phone}   … 登録メール・電話の照合 → 情報BOX用のセッション
 * POST {action:'reissue', token, email, phone}  … 期限切れURLの再発行（登録メールアドレスへ送る）
 * POST {action:'logout', box_id}
 *
 * ・認証前は物件・参加者・フォルダーを一切返さない。
 * ・電話番号は空白・ハイフン等を除いて照合する。
 * ・連続5回失敗で15分間ロックする。
 * ・期限切れ・失敗は、理由と問い合わせ先（案内した担当者）を返す。
 * ・不動産AI名刺にログイン中なら、そのアカウントを同じ関係者IDへ紐づける（以後アカウントから再ログインできる）。
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/infobox-email-helper.php';

header('Content-Type: application/json; charset=UTF-8');

/** 照合（失敗回数の記録とロックを含む）。成功時 null、失敗時はエラーメッセージ。 */
function iboxAccessCheckCredentials(PDO $db, array $p, string $email, string $phone): ?string
{
    if (!empty($p['locked_until']) && $p['locked_until'] > iboxNow()) {
        $minutes = max(1, (int)ceil((strtotime($p['locked_until']) - time()) / 60));
        return '入力の誤りが続いたため、一時的にご利用を制限しています。約' . $minutes . '分後に再度お試しください。';
    }
    $emailOk = $email !== '' && hash_equals(iboxNormalizeEmail($p['email']), iboxNormalizeEmail($email));
    $phoneOk = $phone !== '' && hash_equals(iboxNormalizePhone($p['phone']), iboxNormalizePhone($phone));
    if ($emailOk && $phoneOk) {
        $db->prepare('UPDATE ibox_participants SET fail_count = 0, locked_until = NULL WHERE id = ?')->execute([(int)$p['id']]);
        return null;
    }
    $fails = (int)$p['fail_count'] + 1;
    if ($fails >= IBOX_AUTH_MAX_FAILS) {
        $db->prepare('UPDATE ibox_participants SET fail_count = 0, locked_until = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() + IBOX_AUTH_LOCK_MINUTES * 60), (int)$p['id']]);
        iboxAudit($db, (int)$p['box_id'], (int)$p['id'], 'auth_locked', 'participant', (int)$p['id']);
        return '入力の誤りが続いたため、' . IBOX_AUTH_LOCK_MINUTES . '分間ご利用を制限しました。時間をおいて再度お試しください。';
    }
    $db->prepare('UPDATE ibox_participants SET fail_count = ? WHERE id = ?')->execute([$fails, (int)$p['id']]);
    return 'メールアドレスまたは電話番号が、ご登録の内容と一致しません。ご案内メールに記載の内容をご入力ください。';
}

try {
    $db = iboxApiDb();
    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');

    if ($action === 'logout') {
        iboxSessionLogout((int)($input['box_id'] ?? 0));
        sendSuccessResponse([], 'ログアウトしました');
    }

    $ref = iboxLookupInvite($db, trim((string)($input['token'] ?? '')));
    if (!$ref) sendErrorResponse('このURLは無効です。ご案内メールのURLをもう一度お確かめください。', 404);
    $p = $ref['p'];
    $box = $ref['box'];
    $email = trim((string)($input['email'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    // 期限切れ・失敗は、理由と問い合わせ先（案内した担当者）を表示する（画面8）
    $contact = iboxInviterContact($db, $p);
    $fail = function (int $code, string $reason, string $message) use ($contact) {
        sendJsonResponse(['success' => false, 'reason' => $reason, 'message' => $message, 'contact' => $contact], $code);
    };

    if ($ref['state'] === 'revoked' || $p['status'] !== 'active') {
        $fail(403, 'revoked', 'このURLは無効になりました（ご登録内容の変更、または参加停止のため）。ご案内担当者へお問い合わせください。');
    }
    if (!iboxEnabledForUser($db, (int)$box['owner_user_id'])) {
        $fail(403, 'disabled', '情報BOXは現在ご利用いただけません。ご案内担当者へお問い合わせください。');
    }

    $error = iboxAccessCheckCredentials($db, $p, $email, $phone);
    if ($error !== null) $fail(401, 'mismatch', $error);

    if ($action === 'reissue') {
        $ok = iboxSendReissue($db, $box, $p);
        iboxAudit($db, (int)$box['id'], (int)$p['id'], 'invite_reissue', 'participant', (int)$p['id'], ['sent' => $ok]);
        if (!$ok) $fail(500, 'mail_failed', 'メールを送信できませんでした。時間をおいて再度お試しいただくか、ご案内担当者へお問い合わせください。');
        sendSuccessResponse([], 'ご登録のメールアドレスへ、新しいURLを送信しました。');
    }

    if ($action !== 'verify') sendErrorResponse('不明な操作です', 400);
    if ($ref['state'] === 'expired') {
        $fail(403, 'expired', 'このURLの有効期限（' . IBOX_INVITE_DAYS . '日間）が切れています。「新しいURLを受け取る」から再発行できます。');
    }
    $access = iboxAccessState($box, $p);
    if (!$access['ok']) {
        $fail(403, (string)$access['reason'], 'この情報BOXの利用期限を過ぎました。ご不明点はご案内担当者へお問い合わせください。');
    }

    // ログイン中の既存アカウントがあれば同じ関係者IDへ紐づける（以後はアカウントから開ける）
    $linked = iboxLinkParticipantAccount($db, $p, $box, (int)($_SESSION['user_id'] ?? 0));
    iboxSessionLogin($p);
    iboxAudit($db, (int)$box['id'], (int)$p['id'], 'auth_success', 'participant', (int)$p['id'], ['account_linked' => $linked]);
    sendSuccessResponse(['box_id' => (int)$box['id'], 'redirect' => 'infobox.php?box=' . (int)$box['id'], 'account_linked' => $linked], '認証しました');
} catch (Throwable $e) {
    error_log('infobox/access.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

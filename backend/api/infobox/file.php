<?php
/**
 * 情報BOX のファイル配信（書類・チャット添付・取引台帳PDF）。
 *
 * GET ?kind=doc&box_id=&id=       … 書類の現在の有効版（Word・Excel は PDF プレビュー）
 * GET ?kind=chat&box_id=&id=      … チャット添付（その会話の参加者だけ）
 * GET ?kind=ledger&box_id=&id=    … 取引台帳PDF（名刺所有者だけ）
 *
 * 閲覧・印刷のたびに「BOX の参加資格 → 対象ごとの権限」を確認する。
 * 画面を開けたことを根拠に許可し続けない（共有解除・削除・期限切れは次の要求から拒否）。
 * 旧版・削除済みの書類は、このURLからは参照できない。
 */
require_once __DIR__ . '/_common.php';

function iboxFileDeny(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function iboxFileSend(string $path, string $mime, string $downloadName, bool $inline = true): void
{
    if (!is_file($path)) iboxFileDeny(404, 'ファイルが見つかりません。');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: " . ($inline ? 'inline' : 'attachment') . "; filename=\"file\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($path);
    exit;
}

try {
    $db = iboxApiDb();
    $boxId = (int)($_GET['box_id'] ?? 0);
    $id = (int)($_GET['id'] ?? 0);
    $kind = (string)($_GET['kind'] ?? '');

    $viewer = iboxResolveViewer($db, $boxId);
    if (isset($viewer['error'])) iboxFileDeny($viewer['code'], $viewer['error']);
    $box = $viewer['box'];
    $me = $viewer['p'];
    $dir = iboxStorageDir((int)$box['id']);

    if ($kind === 'doc') {
        $doc = iboxLoadDocument($db, (int)$box['id'], $id);
        if (!$doc || !iboxDocVisibleTo($doc, $doc['shares'], $me)) iboxFileDeny(404, 'この書類は表示できません。');
        // 差替えで形式が変わることがあるため、拡張子は現在の版に合わせる
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', (string)$doc['display_name']);
        if (!empty($doc['preview_name'])) {
            iboxFileSend($dir . '/' . basename($doc['preview_name']), 'application/pdf', $base . '.pdf');
        }
        iboxFileSend($dir . '/' . basename($doc['stored_name']), (string)$doc['mime_type'], $base . '.' . $doc['ext']);
    }

    if ($kind === 'chat') {
        $stmt = $db->prepare('SELECT m.*, c.kind AS conv_kind, c.p_low, c.p_high FROM ibox_messages m JOIN ibox_conversations c ON c.id = m.conversation_id WHERE m.id = ? AND m.box_id = ? AND m.deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id, (int)$box['id']]);
        $msg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$msg || empty($msg['attach_stored'])) iboxFileDeny(404, 'この添付は表示できません。');
        if ($msg['conv_kind'] === 'direct' && !in_array((int)$me['id'], [(int)$msg['p_low'], (int)$msg['p_high']], true)) {
            iboxFileDeny(404, 'この添付は表示できません。');
        }
        $inline = in_array($msg['attach_mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);
        iboxFileSend($dir . '/' . basename($msg['attach_stored']), (string)$msg['attach_mime'], (string)$msg['attach_name'], $inline);
    }

    if ($kind === 'ledger') {
        // 取引台帳は所有者専用の内部資料。顧客・相手仲介には配信しない。
        if (!$viewer['is_owner']) iboxFileDeny(404, 'このファイルは表示できません。');
        $stmt = $db->prepare('SELECT * FROM ibox_ledgers WHERE id = ? AND box_id = ? LIMIT 1');
        $stmt->execute([$id, (int)$box['id']]);
        $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ledger) iboxFileDeny(404, 'このファイルは表示できません。');
        iboxFileSend($dir . '/' . basename($ledger['pdf_name']), 'application/pdf', '取引台帳_' . $box['transaction_code'] . '_v' . (int)$ledger['version'] . '.pdf');
    }

    iboxFileDeny(400, '不正なリクエストです。');
} catch (Throwable $e) {
    error_log('infobox/file.php error: ' . $e->getMessage());
    iboxFileDeny(500, 'ファイルを表示できませんでした。');
}

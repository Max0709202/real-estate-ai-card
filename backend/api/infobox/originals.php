<?php
/**
 * 原本の受渡し管理（仕様 第3章「原本受渡し」・画面18）。
 *
 * GET  ?action=get&box_id=&id=     … 原本票と受渡し履歴（作成者と公開先だけ）
 * POST {action:'save', ...}        … 原本票の作成・変更（作成は登録担当者、変更は作成者のみ）
 * POST {action:'event', ...}       … 受渡しの記録（履歴は追記のみ。過去を上書きしない）
 * POST {action:'delete', ...}      … 原本票の削除（作成者のみ）
 *
 * 原本票はファイルなしでも作れる。原本票だけではフォルダーは青にならない（色は電子ファイルの状態）。
 * ファイルと連携した原本票の公開先は、そのファイルを閲覧できる人の範囲内に限る。
 */
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=UTF-8');

function iboxOriginalStatuses(): array
{
    return [
        'unconfirmed' => '未確認',
        'requesting' => '取得依頼中',
        'obtained' => '取得済',
        'submitted' => '提出済',
        'returned' => '返却済',
        'not_needed' => '不要',
    ];
}

function iboxLoadOriginal(PDO $db, int $boxId, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM ibox_originals WHERE id = ? AND box_id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id, $boxId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function iboxOriginalVisible(array $o, array $me): bool
{
    return (int)$o['created_by'] === (int)$me['id'] || in_array((int)$me['id'], array_map('intval', iboxDecodeList($o['viewers'])), true);
}

function iboxOriginalPayload(PDO $db, array $box, array $o, array $me): array
{
    $nameById = iboxApiNameMap(iboxParticipants($db, (int)$box['id'], true));
    $stmt = $db->prepare('SELECT * FROM ibox_original_events WHERE original_id = ? ORDER BY id ASC');
    $stmt->execute([(int)$o['id']]);
    $events = [];
    $statuses = iboxOriginalStatuses();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $events[] = [
            'status' => $e['status'],
            'status_label' => $statuses[$e['status']] ?? $e['status'],
            'event_date' => $e['event_date'],
            'copies' => $e['copies'] !== null ? (int)$e['copies'] : null,
            'counterparty' => (string)($e['counterparty'] ?? ''),
            'confirmed_by_name' => (string)($e['confirmed_by_name'] ?? ''),
            'note' => (string)($e['note'] ?? ''),
            'recorded_by' => $nameById[(int)$e['created_by']] ?? '',
            'created_at' => $e['created_at'],
        ];
    }
    $lastSubmit = null;
    foreach ($events as $e) if ($e['status'] === 'submitted') $lastSubmit = $e;

    // 連携ファイル（閲覧者が見られる場合だけ名前を出す）
    $docName = '';
    $fileState = null;
    if (!empty($o['document_id'])) {
        $doc = iboxLoadDocument($db, (int)$box['id'], (int)$o['document_id']);
        if ($doc && iboxDocVisibleTo($doc, $doc['shares'], $me)) $docName = (string)$doc['display_name'];
    }
    $folder = iboxLoadFolder($db, (int)$box['id'], (int)$o['folder_id']);
    if ($folder) {
        $visible = iboxVisibleDocsByFolder($db, (int)$box['id'], $me)[(int)$folder['id']] ?? [];
        $st = iboxFolderStatus($folder, count($visible));
        $fileState = ['label' => $st['label'], 'color' => $st['color'], 'count' => count($visible), 'folder_name' => (string)$folder['name']];
    }
    $isMine = (int)$o['created_by'] === (int)$me['id'];
    return [
        'id' => (int)$o['id'],
        'folder_id' => (int)$o['folder_id'],
        'document_id' => $o['document_id'] !== null ? (int)$o['document_id'] : null,
        'document_name' => $docName,
        'doc_name' => (string)$o['doc_name'],
        'target_name' => (string)($o['target_name'] ?? ''),
        'required_state' => (string)$o['required_state'],
        'copies' => $o['copies'] !== null ? (int)$o['copies'] : null,
        'submit_to' => (string)($o['submit_to'] ?? ''),
        'deadline' => $o['deadline'],
        'issued_date' => $o['issued_date'],
        'deadline_condition' => (string)($o['deadline_condition'] ?? ''),
        'keeper' => (string)($o['keeper'] ?? ''),
        'remarks' => (string)($o['remarks'] ?? ''),
        'status' => (string)$o['status'],
        'status_label' => $statuses[$o['status']] ?? $o['status'],
        'submitted' => $lastSubmit,
        'creator_name' => $nameById[(int)$o['created_by']] ?? '',
        'is_mine' => $isMine,
        'viewers' => $isMine ? array_map('intval', iboxDecodeList($o['viewers'])) : null,
        'events' => $events,
        'file_state' => $fileState,
    ];
}

try {
    $db = iboxApiDb();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        $o = iboxLoadOriginal($db, (int)$viewer['box']['id'], (int)($_GET['id'] ?? 0));
        if (!$o || !iboxOriginalVisible($o, $viewer['p'])) sendErrorResponse('原本票が見つかりません。', 404);
        sendSuccessResponse(['original' => iboxOriginalPayload($db, $viewer['box'], $o, $viewer['p']), 'statuses' => iboxOriginalStatuses()]);
    }

    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');
    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0), true);
    $box = $viewer['box'];
    $me = $viewer['p'];

    if ($action === 'save') {
        $id = (int)($input['id'] ?? 0);
        $existing = $id > 0 ? iboxLoadOriginal($db, (int)$box['id'], $id) : null;
        if ($id > 0) {
            if (!$existing || !iboxOriginalVisible($existing, $me)) sendErrorResponse('原本票が見つかりません。', 404);
            if ((int)$existing['created_by'] !== (int)$me['id']) sendErrorResponse('原本票の変更・公開先の設定は、作成した本人だけが行えます。', 403);
            $folderId = (int)$existing['folder_id'];
        } else {
            $folderId = (int)($input['folder_id'] ?? 0);
        }
        $folder = iboxLoadFolder($db, (int)$box['id'], $folderId);
        if (!$folder) sendErrorResponse('フォルダーが見つかりません。', 404);
        if (!$existing && !iboxCanUploadToFolder($box, $folder, $me)) sendErrorResponse('このフォルダーの原本票を作成する権限がありません。', 403);

        $docName = iboxApiText($input['doc_name'] ?? '');
        if ($docName === '') sendErrorResponse('書類名を入力してください。', 400);
        $required = in_array($input['required_state'] ?? '', ['unknown', 'required', 'not_required'], true) ? $input['required_state'] : 'unknown';
        $copiesRaw = trim((string)($input['copies'] ?? ''));
        $copies = $copiesRaw === '' ? null : (int)$copiesRaw;
        $submitTo = iboxApiText($input['submit_to'] ?? '');
        if ($required === 'required' && ($copies === null || $copies < 1 || $submitTo === '')) {
            sendErrorResponse('原本が必要な場合は、必要通数（1以上）と提出先を入力してください。', 400);
        }

        // 連携ファイル：作成者が閲覧できる書類に限る。公開先はそのファイルの閲覧者の範囲内に絞る。
        $documentId = (int)($input['document_id'] ?? 0);
        $allowedViewers = null;
        if ($documentId > 0) {
            $doc = iboxLoadDocument($db, (int)$box['id'], $documentId);
            if (!$doc || !iboxDocVisibleTo($doc, $doc['shares'], $me) || (int)$doc['folder_id'] !== $folderId) sendErrorResponse('連携する書類が見つかりません。', 404);
            $allowedViewers = array_merge([(int)$doc['uploader_id']], $doc['shares']);
        }
        $active = array_map(fn($pp) => (int)$pp['id'], iboxParticipants($db, (int)$box['id']));
        $viewers = [];
        foreach ((array)($input['viewers'] ?? []) as $pid) {
            $pid = (int)$pid;
            if ($pid === (int)$me['id'] || !in_array($pid, $active, true)) continue;
            if ($allowedViewers !== null && !in_array($pid, $allowedViewers, true)) continue;
            $viewers[$pid] = true;
        }
        $viewers = array_keys($viewers);

        $status = array_key_exists($input['status'] ?? '', iboxOriginalStatuses()) ? $input['status'] : ($existing['status'] ?? 'unconfirmed');
        if ($status === 'submitted' && (!$existing || $existing['status'] !== 'submitted')) {
            sendErrorResponse('「提出済」は受渡しの記録（提出日・提出通数・受領確認者）から登録してください。', 400);
        }
        if ($required === 'not_required') $status = 'not_needed';

        $values = [
            $documentId > 0 ? $documentId : null,
            iboxApiText($input['target_name'] ?? '', 128),
            $docName,
            $required,
            $copies,
            $submitTo,
            iboxApiDate($input['deadline'] ?? '', '提出期限'),
            iboxApiDate($input['issued_date'] ?? '', '発行日'),
            iboxApiText($input['deadline_condition'] ?? '', 500),
            iboxApiText($input['keeper'] ?? '', 128),
            iboxApiText($input['remarks'] ?? '', 2000),
            $status,
            json_encode($viewers),
            iboxNow(),
        ];
        if ($existing) {
            $db->prepare('UPDATE ibox_originals SET document_id = ?, target_name = ?, doc_name = ?, required_state = ?, copies = ?, submit_to = ?, deadline = ?, issued_date = ?, deadline_condition = ?, keeper = ?, remarks = ?, status = ?, viewers = ?, updated_at = ? WHERE id = ?')
                ->execute(array_merge($values, [(int)$existing['id']]));
            $oid = (int)$existing['id'];
        } else {
            $db->prepare('INSERT INTO ibox_originals (document_id, target_name, doc_name, required_state, copies, submit_to, deadline, issued_date, deadline_condition, keeper, remarks, status, viewers, updated_at, box_id, folder_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($values, [(int)$box['id'], $folderId, (int)$me['id'], iboxNow()]));
            $oid = (int)$db->lastInsertId();
        }
        iboxAudit($db, (int)$box['id'], (int)$me['id'], $existing ? 'original_update' : 'original_create', 'original', $oid);
        sendSuccessResponse(['original' => iboxOriginalPayload($db, $box, iboxLoadOriginal($db, (int)$box['id'], $oid), $me)], '原本票を保存しました');
    }

    $o = iboxLoadOriginal($db, (int)$box['id'], (int)($input['id'] ?? 0));
    if (!$o || !iboxOriginalVisible($o, $me)) sendErrorResponse('原本票が見つかりません。', 404);
    if ((int)$o['created_by'] !== (int)$me['id']) sendErrorResponse('この原本票を変更できるのは、作成した本人だけです。', 403);

    if ($action === 'event') {
        $status = (string)($input['status'] ?? '');
        if (!array_key_exists($status, iboxOriginalStatuses())) sendErrorResponse('状態を選択してください。', 400);
        $date = iboxApiDate($input['event_date'] ?? '', '受渡し日');
        $copiesRaw = trim((string)($input['copies'] ?? ''));
        $copies = $copiesRaw === '' ? null : (int)$copiesRaw;
        $counterparty = iboxApiText($input['counterparty'] ?? '');
        $confirmer = iboxApiText($input['confirmed_by_name'] ?? '', 128);
        if ($status === 'submitted' && ($date === null || $copies === null || $copies < 1 || $confirmer === '')) {
            sendErrorResponse('提出済には、提出日・提出通数・受領確認者の入力が必要です。', 400);
        }
        $db->prepare('INSERT INTO ibox_original_events (original_id, status, event_date, copies, counterparty, confirmed_by_name, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$o['id'], $status, $date, $copies, $counterparty, $confirmer, iboxApiText($input['note'] ?? '', 500), (int)$me['id'], iboxNow()]);
        $db->prepare('UPDATE ibox_originals SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, iboxNow(), (int)$o['id']]);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'original_event', 'original', (int)$o['id'], ['status' => $status]);
        sendSuccessResponse(['original' => iboxOriginalPayload($db, $box, iboxLoadOriginal($db, (int)$box['id'], (int)$o['id']), $me)], '受渡しを記録しました');
    }

    if ($action === 'delete') {
        $db->prepare('UPDATE ibox_originals SET deleted_at = ?, updated_at = ? WHERE id = ?')->execute([iboxNow(), iboxNow(), (int)$o['id']]);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'original_delete', 'original', (int)$o['id']);
        sendSuccessResponse([], '原本票を削除しました');
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    error_log('infobox/originals.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

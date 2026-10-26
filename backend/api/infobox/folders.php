<?php
/**
 * 書類フォルダー（仕様 第2・3・5章、画面9〜12・14・15・17）。
 *
 * GET  ?action=list&box_id=               … 閲覧者に見えるフォルダー（件数・色は見られる範囲だけで集計）
 * GET  ?action=get&box_id=&folder_id=     … フォルダー内の、閲覧者が見られる書類と原本票
 * GET  ?action=catalog&box_id=            … 追加できる書類カタログ（仲介担当者）
 * POST {action:'add', ...}                 … フォルダー追加（買主仲介・売主仲介のみ。作成者が責任者）
 * POST {action:'settings', ...}            … 一覧公開・登録担当の設定（初期は所有者、追加は作成者）
 * POST {action:'unneeded', op:request|confirm|reset, ...} … 不要・書類なしの申告と確定
 * POST {action:'rename'|'delete', ...}     … 追加フォルダーの名称変更・削除（作成者のみ）
 */
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=UTF-8');

/** 設定画面の行：役割の枠（登録済みなら氏名付き）と、その他の関係者（個人）。 */
function iboxPrincipalRows(PDO $db, array $box): array
{
    $participants = iboxParticipants($db, (int)$box['id']);
    $rows = [];
    $byRole = [];
    foreach ($participants as $pp) {
        foreach (iboxViewerRoles($box, $pp) as $role) $byRole[$role][] = iboxParticipantName($pp);
    }
    foreach (iboxRoles() as $role => $def) {
        if ($role === 'other') continue;
        $rows[] = ['key' => 'role:' . $role, 'label' => $def['label'], 'names' => $byRole[$role] ?? []];
    }
    foreach ($participants as $pp) {
        if ($pp['role'] === 'other') $rows[] = ['key' => 'p:' . (int)$pp['id'], 'label' => 'その他の関係者', 'names' => [iboxParticipantName($pp)]];
    }
    return $rows;
}

function iboxCleanPrincipals(PDO $db, array $box, $list): array
{
    $valid = [];
    $roles = iboxRoles();
    $ids = array_map(fn($pp) => (int)$pp['id'], iboxParticipants($db, (int)$box['id']));
    foreach ((array)$list as $item) {
        $item = (string)$item;
        if (strpos($item, 'role:') === 0 && isset($roles[substr($item, 5)]) && substr($item, 5) !== 'other') $valid[$item] = true;
        if (strpos($item, 'p:') === 0 && in_array((int)substr($item, 2), $ids, true)) $valid['p:' . (int)substr($item, 2)] = true;
    }
    return array_keys($valid);
}

function iboxFolderDetail(PDO $db, array $box, array $folder, array $me): array
{
    $participants = iboxParticipants($db, (int)$box['id']);
    $nameById = iboxApiNameMap(iboxParticipants($db, (int)$box['id'], true));
    $docs = iboxVisibleDocsByFolder($db, (int)$box['id'], $me)[(int)$folder['id']] ?? [];
    $docRows = [];
    foreach ($docs as $doc) {
        $isMine = (int)$doc['uploader_id'] === (int)$me['id'];
        $row = [
            'id' => (int)$doc['id'],
            'name' => (string)$doc['display_name'],
            'ext' => strtoupper((string)$doc['ext']),
            'version' => (int)$doc['current_version'],
            'uploader_name' => $nameById[(int)$doc['uploader_id']] ?? '',
            'updated_at' => $doc['updated_at'],
            'is_mine' => $isMine,
            'previewable' => in_array($doc['ext'], ['pdf', 'jpg', 'png'], true) || !empty($doc['preview_name']),
        ];
        // 共有先は登録者本人にだけ返す（他の閲覧者には誰に共有されているかも見せない）
        if ($isMine) $row['shares'] = array_map('intval', $doc['shares']);
        $docRows[] = $row;
    }

    // 原本票：作成者と、作成者が公開先に指定した人だけ
    $stmt = $db->prepare('SELECT * FROM ibox_originals WHERE folder_id = ? AND deleted_at IS NULL ORDER BY id ASC');
    $stmt->execute([(int)$folder['id']]);
    $originals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $viewers = array_map('intval', iboxDecodeList($o['viewers']));
        if ((int)$o['created_by'] !== (int)$me['id'] && !in_array((int)$me['id'], $viewers, true)) continue;
        $originals[] = [
            'id' => (int)$o['id'],
            'doc_name' => (string)$o['doc_name'],
            'target_name' => (string)($o['target_name'] ?? ''),
            'status' => (string)$o['status'],
            'creator_name' => $nameById[(int)$o['created_by']] ?? '',
            'is_mine' => (int)$o['created_by'] === (int)$me['id'],
        ];
    }

    $candidates = [];
    foreach ($participants as $pp) {
        if ((int)$pp['id'] === (int)$me['id']) continue;
        $candidates[] = ['id' => (int)$pp['id'], 'name' => iboxParticipantName($pp), 'role_label' => iboxParticipantRoleLabel($box, $pp)];
    }

    $summary = iboxSerializeFolder($box, $folder, $me, $docs, $participants, $nameById);
    $summary['list_principals'] = iboxCanManageFolder($folder, $me) ? iboxDecodeList($folder['list_principals']) : null;
    $summary['upload_principals'] = iboxCanManageFolder($folder, $me) ? iboxDecodeList($folder['upload_principals']) : null;

    return [
        'folder' => $summary,
        'documents' => $docRows,
        'originals' => $originals,
        'can_create_original' => iboxCanUploadToFolder($box, $folder, $me),
        'share_candidates' => $candidates,
        'principal_rows' => iboxCanManageFolder($folder, $me) ? iboxPrincipalRows($db, $box) : [],
    ];
}

try {
    $db = iboxApiDb();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        $box = $viewer['box'];
        $me = $viewer['p'];
        $action = (string)($_GET['action'] ?? 'list');

        if ($action === 'list') {
            sendSuccessResponse([
                'folders' => iboxFolderList($db, $box, $me),
                'can_add' => iboxIsAgent($box, $me) && !$viewer['readonly'],
            ]);
        }
        if ($action === 'get') {
            $folder = iboxLoadFolder($db, (int)$box['id'], (int)($_GET['folder_id'] ?? 0));
            // 見えないフォルダーは「存在しない」と同じ応答にする（存在を知らせない）
            $visibleIds = array_column(iboxFolderList($db, $box, $me), 'id');
            if (!$folder || !in_array((int)$folder['id'], $visibleIds, true)) sendErrorResponse('フォルダーが見つかりません。', 404);
            sendSuccessResponse(iboxFolderDetail($db, $box, $folder, $me));
        }
        if ($action === 'catalog') {
            if (!iboxIsAgent($box, $me)) sendErrorResponse('フォルダーを追加できるのは買主仲介・売主仲介の担当者です。', 403);
            $items = [];
            foreach (iboxCatalog() as $item) {
                if (!iboxCatalogApplies($item, (string)$box['property_type'])) continue;
                $items[] = [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'basic' => $item['basic'],
                    'uploader_codes' => $item['uploader_codes'],
                    'viewer_codes' => $item['viewer_codes'],
                    'purpose' => $item['purpose'],
                ];
            }
            sendSuccessResponse(['items' => $items]);
        }
        sendErrorResponse('不明な操作です', 400);
    }

    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');
    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0), true);
    $box = $viewer['box'];
    $me = $viewer['p'];

    if ($action === 'add') {
        if (!iboxIsAgent($box, $me)) sendErrorResponse('フォルダーを追加できるのは買主仲介・売主仲介の担当者です。', 403);
        $templateId = trim((string)($input['template_id'] ?? ''));
        $catalog = iboxCatalog();
        $item = $templateId !== '' ? ($catalog[$templateId] ?? null) : null;
        if ($templateId !== '' && !$item) sendErrorResponse('書類の候補が正しくありません。', 400);
        $name = iboxApiText($input['name'] ?? ($item['name'] ?? ''));
        if ($name === '') sendErrorResponse('フォルダー名を入力してください。', 400);
        $target = iboxApiText($input['target_name'] ?? '', 128);

        // 同じテンプレート・対象者のフォルダーが（自分に見える範囲で）既にあれば案内する
        if (empty($input['confirm_duplicate'])) {
            foreach (iboxFolderList($db, $box, $me) as $f) {
                $sameTemplate = $templateId !== '' && $f['template_id'] === $templateId;
                $sameName = $templateId === '' && $f['name'] === $name;
                if (($sameTemplate || $sameName) && $f['target_name'] === $target) {
                    sendJsonResponse(['success' => false, 'duplicate' => true, 'existing_folder_id' => $f['id'],
                        'message' => '同じ書類・対象者のフォルダー「' . $f['name'] . '」が既にあります。既存のフォルダーを開くか、別の用途として追加してください。'], 409);
                }
            }
        }

        // 他の人の初期権限は「一覧で見える」まで。中身は書類ごとに登録者が指定する。
        $listRoles = $item ? array_unique(array_merge($item['viewer_roles'], $item['uploader_roles'])) : array_keys(array_diff_key(iboxRoles(), ['other' => 1]));
        $list = array_values(array_map(fn($r) => 'role:' . $r, $listRoles));
        $list[] = 'p:' . (int)$me['id'];

        $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM ibox_folders WHERE box_id = ?');
        $stmt->execute([(int)$box['id']]);
        $order = max(1000, (int)$stmt->fetchColumn());
        $db->prepare('
            INSERT INTO ibox_folders (box_id, template_id, name, target_name, is_initial, created_by, list_principals, upload_principals, sort_order, created_at, updated_at)
            VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)
        ')->execute([(int)$box['id'], $templateId !== '' ? $templateId : null, $name, $target !== '' ? $target : null, (int)$me['id'], json_encode($list), json_encode([]), $order, iboxNow(), iboxNow()]);
        $folderId = (int)$db->lastInsertId();
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'folder_add', 'folder', $folderId, ['template_id' => $templateId, 'name' => $name]);
        sendSuccessResponse(['folder_id' => $folderId], 'フォルダーを追加しました');
    }

    $folder = iboxLoadFolder($db, (int)$box['id'], (int)($input['folder_id'] ?? 0));
    if (!$folder) sendErrorResponse('フォルダーが見つかりません。', 404);

    if ($action === 'settings') {
        if (!iboxCanManageFolder($folder, $me)) sendErrorResponse('このフォルダーの設定を変更できません。', 403);
        $list = iboxCleanPrincipals($db, $box, $input['list_principals'] ?? []);
        $upload = iboxCleanPrincipals($db, $box, $input['upload_principals'] ?? []);
        // 登録担当はフォルダー一覧にも出す
        $list = array_values(array_unique(array_merge($list, $upload)));
        $db->prepare('UPDATE ibox_folders SET list_principals = ?, upload_principals = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($list), json_encode($upload), iboxNow(), (int)$folder['id']]);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'folder_settings', 'folder', (int)$folder['id'], [
            'before' => ['list' => iboxDecodeList($folder['list_principals']), 'upload' => iboxDecodeList($folder['upload_principals'])],
            'after' => ['list' => $list, 'upload' => $upload],
        ]);
        sendSuccessResponse(iboxFolderDetail($db, $box, iboxLoadFolder($db, (int)$box['id'], (int)$folder['id']), $me), '設定を保存しました');
    }

    if ($action === 'unneeded') {
        $op = (string)($input['op'] ?? '');
        $reason = iboxApiText($input['reason'] ?? '', 500);
        $canManage = iboxCanManageFolder($folder, $me);
        if ($op === 'request') {
            if ((int)$folder['is_initial'] !== 1 || !iboxCanUploadToFolder($box, $folder, $me)) sendErrorResponse('このフォルダーの不要申告はできません。', 403);
            if ($reason === '') sendErrorResponse('理由を入力してください。', 400);
            $db->prepare("UPDATE ibox_folders SET unneeded_state = 'requested', unneeded_reason = ?, unneeded_requested_by = ?, unneeded_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$reason, (int)$me['id'], iboxNow(), iboxNow(), (int)$folder['id']]);
        } elseif ($op === 'confirm') {
            if (!$canManage) sendErrorResponse('不要・書類なしを確定できるのは、初期フォルダーは名刺所有者、追加フォルダーは作成者です。', 403);
            if ($reason === '') $reason = (string)($folder['unneeded_reason'] ?? '');
            if ($reason === '') sendErrorResponse('理由を入力してください。', 400);
            if (empty($input['confirmed'])) sendErrorResponse('確認欄にチェックしてください。', 400);
            $db->prepare("UPDATE ibox_folders SET unneeded_state = 'confirmed', unneeded_reason = ?, unneeded_confirmed_by = ?, unneeded_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$reason, (int)$me['id'], iboxNow(), iboxNow(), (int)$folder['id']]);
        } elseif ($op === 'reset') {
            $isRequester = $folder['unneeded_state'] === 'requested' && (int)$folder['unneeded_requested_by'] === (int)$me['id'];
            if (!$canManage && !$isRequester) sendErrorResponse('この操作はできません。', 403);
            $db->prepare("UPDATE ibox_folders SET unneeded_state = 'none', unneeded_reason = NULL, unneeded_requested_by = NULL, unneeded_confirmed_by = NULL, updated_at = ? WHERE id = ?")
                ->execute([iboxNow(), (int)$folder['id']]);
        } else {
            sendErrorResponse('不明な操作です', 400);
        }
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'folder_unneeded_' . $op, 'folder', (int)$folder['id'], ['reason' => $reason]);
        sendSuccessResponse(iboxFolderDetail($db, $box, iboxLoadFolder($db, (int)$box['id'], (int)$folder['id']), $me),
            ['request' => '不要・書類なしを申告しました', 'confirm' => '不要・書類なしを確定しました', 'reset' => '取り消しました'][$op]);
    }

    if ($action === 'rename' || $action === 'delete') {
        if ((int)$folder['is_initial'] === 1 || (int)$folder['created_by'] !== (int)$me['id']) {
            sendErrorResponse('フォルダー名の変更・削除は、追加した本人だけが行えます。', 403);
        }
        if ($action === 'rename') {
            $name = iboxApiText($input['name'] ?? '');
            if ($name === '') sendErrorResponse('フォルダー名を入力してください。', 400);
            $db->prepare('UPDATE ibox_folders SET name = ?, target_name = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, iboxApiText($input['target_name'] ?? '', 128) ?: null, iboxNow(), (int)$folder['id']]);
            iboxAudit($db, (int)$box['id'], (int)$me['id'], 'folder_rename', 'folder', (int)$folder['id'], ['before' => $folder['name'], 'after' => $name]);
            sendSuccessResponse([], 'フォルダー名を変更しました');
        }
        // 配下の他人の書類を一括削除しないため、有効な書類・原本票が1件でもあれば削除しない
        if (iboxFolderHasContent($db, (int)$folder['id'])) {
            sendErrorResponse('書類または原本票が残っているため削除できません。すべての書類・原本票が削除されてから操作してください。', 409);
        }
        $db->prepare('UPDATE ibox_folders SET deleted_at = ?, updated_at = ? WHERE id = ?')->execute([iboxNow(), iboxNow(), (int)$folder['id']]);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'folder_delete', 'folder', (int)$folder['id'], ['name' => $folder['name']]);
        sendSuccessResponse([], 'フォルダーを削除しました');
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    error_log('infobox/folders.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

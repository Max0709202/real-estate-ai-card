<?php
/**
 * 情報BOX 本体（一覧・作成・概要・物件情報・取引終了）。
 *
 * GET  ?action=list                     … 所有者の BOX 一覧と、アカウントに紐づいた参加中の BOX
 * GET  ?action=properties               … BOX に取り込める自分の物件（所有者のみ）
 * GET  ?action=get&box_id=              … 取引概要（閲覧者ごとに見られる範囲で集計）
 * GET  ?action=refetch&box_id=          … 物件詳細からの再取得（差分を返すだけで保存しない）
 * POST {action:'create', ...}           … BOX 作成（所有者）
 * POST {action:'update_property', ...}  … 物件情報の修正（所有者。物件詳細へは逆反映しない）
 * POST {action:'end', ...}              … 取引終了の確定・訂正（所有者）
 * POST {action:'reopen', ...}           … 取引の再開（所有者）
 */
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=UTF-8');

/** 物件詳細（properties）から BOX に取り込む値。未設定と0円は区別する。 */
function iboxPropertyValues(array $row): array
{
    $name = trim((string)($row['building_name'] ?: $row['property_name']));
    $room = trim((string)($row['room_number'] ?? ''));
    if ($room !== '' && mb_strpos($name, $room) === false) $name .= ' ' . $room . (preg_match('/号室?$/u', $room) ? '' : '号室');
    return [
        'property_name' => $name,
        'address' => (string)($row['address'] ?? ''),
        'price' => $row['price_man'] !== null ? (int)$row['price_man'] * 10000 : null,
        'property_type' => ($row['property_type'] ?? '') === 'mansion' ? 'mansion' : 'house',
    ];
}

function iboxLoadOwnProperty(PDO $db, int $userId, int $propertyId): ?array
{
    try {
        $stmt = $db->prepare('
            SELECT p.* FROM properties p
            JOIN business_cards bc ON bc.id = p.business_card_id
            WHERE p.id = ? AND bc.user_id = ? LIMIT 1
        ');
        $stmt->execute([$propertyId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function iboxBoxPayload(array $box): array
{
    return [
        'id' => (int)$box['id'],
        'transaction_code' => (string)$box['transaction_code'],
        'property_id' => $box['property_id'] !== null ? (int)$box['property_id'] : null,
        'property_name' => (string)($box['property_name'] ?? ''),
        'address' => (string)($box['address'] ?? ''),
        'price' => $box['price'] !== null ? (int)$box['price'] : null,
        'property_type' => (string)$box['property_type'],
        'property_type_label' => $box['property_type'] === 'house' ? '一戸建て' : 'マンション',
        'contract_planned_date' => $box['contract_planned_date'],
        'owner_side' => (string)$box['owner_side'],
        'dual_agency' => (int)$box['dual_agency'] === 1,
        'status' => (string)$box['status'],
        'end_date' => $box['end_date'],
        'access_until' => $box['access_until'],
        'readonly' => iboxIsReadOnly($box),
    ];
}

try {
    $db = iboxApiDb();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $method === 'GET' ? (string)($_GET['action'] ?? '') : (string)(iboxApiInput()['action'] ?? '');

    if ($method === 'GET' && $action === 'list') {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) sendErrorResponse('再ログインをお願いします', 401);
        $canCreate = iboxEnabledForUser($db, $userId);
        // 自分のアカウントに紐づいた、他社の情報BOX（招待を受けて参加中のもの）
        $joined = [];
        foreach (iboxJoinedBoxes($db, $userId) as $row) {
            $joined[] = iboxBoxPayload($row['box']) + ['role_label' => iboxParticipantRoleLabel($row['box'], $row['p'])];
        }
        if (!$canCreate && !$joined) sendErrorResponse('情報BOXは現在ご利用いただけません。', 403);
        $boxes = [];
        if ($canCreate) {
            $stmt = $db->prepare('SELECT * FROM ibox_boxes WHERE owner_user_id = ? ORDER BY status ASC, updated_at DESC');
            $stmt->execute([$userId]);
            $boxes = array_map('iboxBoxPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        sendSuccessResponse(['boxes' => $boxes, 'joined' => $joined, 'can_create' => $canCreate]);
    }

    if ($method === 'GET' && $action === 'properties') {
        $userId = iboxApiRequireEnabledUser($db);
        $rows = [];
        try {
            $stmt = $db->prepare('
                SELECT p.id, p.property_name, p.building_name, p.room_number, p.address, p.price_man, p.property_type, p.updated_at
                FROM properties p
                JOIN business_cards bc ON bc.id = p.business_card_id
                WHERE bc.user_id = ?
                ORDER BY p.updated_at DESC
                LIMIT 200
            ');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = ['id' => (int)$row['id']] + iboxPropertyValues($row);
            }
        } catch (Exception $e) {
            error_log('infobox properties error: ' . $e->getMessage());
        }
        sendSuccessResponse(['properties' => $rows]);
    }

    if ($method === 'GET' && $action === 'get') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        $box = $viewer['box'];
        $p = $viewer['p'];

        $folders = iboxFolderList($db, $box, $p);
        $counts = ['total' => count($folders), 'uploaded' => 0, 'missing' => 0, 'unneeded' => 0];
        $attention = [];
        foreach ($folders as $f) {
            if ($f['color'] === 'blue') $counts['uploaded']++;
            elseif ($f['color'] === 'black') $counts['unneeded']++;
            else $counts['missing']++;
            if ($f['status'] === 'requested' && $f['can_manage']) {
                $attention[] = ['type' => 'unneeded_request', 'folder_id' => $f['id'], 'label' => $f['name'], 'reason' => $f['unneeded_reason']];
            }
        }
        if ($viewer['is_owner']) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM ibox_participants WHERE box_id = ? AND is_owner = 0 AND status = 'active' AND notify_status IN ('unsent','failed')");
            $stmt->execute([(int)$box['id']]);
            $unsent = (int)$stmt->fetchColumn();
            if ($unsent > 0) $attention[] = ['type' => 'notify', 'label' => '未通知・送信失敗の関係者 ' . $unsent . '名'];
        }

        // 全員チャットの最新投稿（全員に公開されている内容だけ）
        $latest = null;
        $stmt = $db->prepare("
            SELECT m.body, m.attach_name, m.created_at, m.sender_id
            FROM ibox_messages m JOIN ibox_conversations c ON c.id = m.conversation_id
            WHERE c.box_id = ? AND c.kind = 'all' AND m.deleted_at IS NULL
            ORDER BY m.id DESC LIMIT 1
        ");
        $stmt->execute([(int)$box['id']]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sender = iboxLoadParticipant($db, (int)$row['sender_id']);
            $latest = [
                'sender' => $sender ? iboxParticipantName($sender) : '',
                'sender_role' => $sender ? iboxParticipantRoleLabel($box, $sender) : '',
                'body' => mb_substr((string)($row['body'] !== '' && $row['body'] !== null ? $row['body'] : '（添付ファイル）'), 0, 80),
                'created_at' => $row['created_at'],
            ];
        }

        $firstAccess = false;
        if (!$viewer['is_owner'] && empty($p['first_access_at'])) {
            $firstAccess = true;
            $db->prepare('UPDATE ibox_participants SET first_access_at = ? WHERE id = ?')->execute([iboxNow(), (int)$p['id']]);
        }

        sendSuccessResponse([
            'box' => iboxBoxPayload($box),
            'viewer' => [
                'id' => (int)$p['id'],
                'name' => iboxParticipantName($p),
                'role_label' => iboxParticipantRoleLabel($box, $p),
                'is_owner' => $viewer['is_owner'],
                'is_agent' => iboxIsAgent($box, $p),
                'access_notice' => iboxAccessNotice($box, $p),
                'access_until' => $viewer['access']['until'],
                'readonly' => $viewer['readonly'],
            ],
            'counts' => $counts,
            'attention' => $attention,
            'latest_chat' => $latest,
            'first_access' => $firstAccess,
            'notices' => [
                'document' => iboxDocumentNotice(),
                'color' => iboxColorNotice(),
                'chat' => iboxChatNotice(),
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'refetch') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        iboxApiRequireOwner($viewer);
        $box = $viewer['box'];
        if (empty($box['property_id'])) sendErrorResponse('この情報BOXは物件詳細と紐づいていません。', 400);
        $row = iboxLoadOwnProperty($db, (int)$box['owner_user_id'], (int)$box['property_id']);
        if (!$row) sendErrorResponse('元の物件詳細が見つかりません。', 404);
        $values = iboxPropertyValues($row);
        $diff = [];
        foreach ($values as $key => $value) {
            $current = $box[$key] ?? null;
            if ((string)$current !== (string)$value) $diff[$key] = ['before' => $current, 'after' => $value];
        }
        sendSuccessResponse(['values' => $values, 'diff' => $diff]);
    }

    iboxApiRequirePost();
    $input = iboxApiInput();

    if ($action === 'create') {
        $userId = iboxApiRequireEnabledUser($db);
        $propertyId = (int)($input['property_id'] ?? 0);
        $data = [
            'property_id' => null,
            'property_name' => iboxApiText($input['property_name'] ?? ''),
            'address' => iboxApiText($input['address'] ?? ''),
            'price' => null,
            'property_type' => ($input['property_type'] ?? '') === 'house' ? 'house' : 'mansion',
            'contract_planned_date' => iboxApiDate($input['contract_planned_date'] ?? '', '契約予定日'),
            'owner_side' => ($input['owner_side'] ?? '') === 'seller' ? 'seller' : 'buyer',
            'dual_agency' => !empty($input['dual_agency']),
        ];
        if ($propertyId > 0) {
            $row = iboxLoadOwnProperty($db, $userId, $propertyId);
            if (!$row) sendErrorResponse('選択した物件が見つかりません。', 404);
            // 同じ物件で進行中の BOX があれば重複して作らない
            $stmt = $db->prepare("SELECT id FROM ibox_boxes WHERE owner_user_id = ? AND property_id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$userId, $propertyId]);
            if ($existing = $stmt->fetchColumn()) {
                sendJsonResponse(['success' => false, 'message' => 'この物件の情報BOXは既にあります。', 'existing_box_id' => (int)$existing], 409);
            }
            $data = array_merge($data, iboxPropertyValues($row));
            $data['property_id'] = $propertyId;
            if (($input['property_type'] ?? '') === 'house' || ($input['property_type'] ?? '') === 'mansion') $data['property_type'] = $input['property_type'];
        }
        if (array_key_exists('price', $input) && trim((string)$input['price']) !== '') {
            $digits = preg_replace('/\D/', '', mb_convert_kana((string)$input['price'], 'n', 'UTF-8'));
            $data['price'] = $digits !== '' ? (int)$digits : null;
        }
        if ($data['property_name'] === '') sendErrorResponse('物件名を入力してください。', 400);

        $boxId = iboxCreateBox($db, $userId, $data);
        sendSuccessResponse(['box_id' => $boxId], '情報BOXを作成しました');
    }

    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0), $action !== 'end' && $action !== 'reopen');
    iboxApiRequireOwner($viewer);
    $box = $viewer['box'];
    $p = $viewer['p'];

    if ($action === 'update_property') {
        $price = trim((string)($input['price'] ?? ''));
        $digits = preg_replace('/\D/', '', mb_convert_kana($price, 'n', 'UTF-8'));
        $fields = [
            'property_name' => iboxApiText($input['property_name'] ?? ''),
            'address' => iboxApiText($input['address'] ?? ''),
            // 未設定（空）と 0 円は区別する
            'price' => $price === '' ? null : (int)$digits,
            'contract_planned_date' => iboxApiDate($input['contract_planned_date'] ?? '', '契約予定日'),
        ];
        if ($fields['property_name'] === '') sendErrorResponse('物件名を入力してください。', 400);
        $newType = ($input['property_type'] ?? '') === 'house' ? 'house' : 'mansion';

        $db->prepare('UPDATE ibox_boxes SET property_name = ?, address = ?, price = ?, contract_planned_date = ?, property_type = ?, updated_at = ? WHERE id = ?')
            ->execute([$fields['property_name'], $fields['address'], $fields['price'], $fields['contract_planned_date'], $newType, iboxNow(), (int)$box['id']]);
        $added = 0;
        if ($newType !== $box['property_type']) {
            // 物件種別の変更で既存書類は削除しない。不足する初期フォルダーだけ追加する。
            $added = iboxCreateInitialFolders($db, iboxLoadBox($db, (int)$box['id']));
        }
        iboxAudit($db, (int)$box['id'], (int)$p['id'], 'property_update', 'box', (int)$box['id'], [
            'before' => ['property_name' => $box['property_name'], 'address' => $box['address'], 'price' => $box['price'], 'property_type' => $box['property_type']],
            'after' => $fields + ['property_type' => $newType],
        ]);
        sendSuccessResponse(['box' => iboxBoxPayload(iboxLoadBox($db, (int)$box['id'])), 'folders_added' => $added], '物件情報を保存しました');
    }

    if ($action === 'end') {
        $endDate = iboxApiDate($input['end_date'] ?? '', '取引終了日');
        if ($endDate === null) sendErrorResponse('実際の取引終了日を入力してください。', 400);
        if ($endDate > date('Y-m-d')) sendErrorResponse('取引終了日に未来の日付は指定できません。', 400);
        $reason = iboxApiText($input['reason'] ?? '', 500);
        if ($box['status'] === 'ended' && $reason === '') sendErrorResponse('終了日を訂正する理由を入力してください。', 400);

        // 継続閲覧できるのは自社が仲介した買主・売主（個人単位で指定。両手なら双方）
        $ownSides = (int)$box['dual_agency'] === 1 ? ['buyer', 'seller'] : [$box['owner_side']];
        $allowedRoles = [];
        foreach (iboxRoles() as $role => $def) {
            if (!empty($def['party']) && in_array($def['side'], $ownSides, true)) $allowedRoles[] = $role;
        }
        $continueIds = array_map('intval', (array)($input['continue_ids'] ?? []));
        $participants = iboxParticipants($db, (int)$box['id'], true);

        $untilBefore = $box['access_until'];
        $until = iboxAccessUntil($endDate);
        $db->beginTransaction();
        foreach ($participants as $pp) {
            if ((int)$pp['is_owner'] === 1) continue;
            $continue = in_array((int)$pp['id'], $continueIds, true) && in_array($pp['role'], $allowedRoles, true) ? 1 : 0;
            $db->prepare('UPDATE ibox_participants SET continue_access = ?, updated_at = ? WHERE id = ?')->execute([$continue, iboxNow(), (int)$pp['id']]);
            // 訂正前の期限で既に失効した方は、訂正しても自動では復活させない
            if (!$continue && $untilBefore && iboxNow() > $untilBefore && $pp['status'] === 'active') {
                $db->prepare("UPDATE ibox_participants SET status = 'suspended', updated_at = ? WHERE id = ?")->execute([iboxNow(), (int)$pp['id']]);
                iboxBumpAuthVersion($db, (int)$pp['id']);
            }
        }
        $db->prepare("UPDATE ibox_boxes SET status = 'ended', end_date = ?, access_until = ?, ended_at = COALESCE(ended_at, ?), updated_at = ? WHERE id = ?")
            ->execute([$endDate, $until, iboxNow(), iboxNow(), (int)$box['id']]);
        $db->commit();
        iboxAudit($db, (int)$box['id'], (int)$p['id'], $box['status'] === 'ended' ? 'end_correct' : 'end_confirm', 'box', (int)$box['id'], [
            'before' => ['end_date' => $box['end_date'], 'access_until' => $untilBefore],
            'after' => ['end_date' => $endDate, 'access_until' => $until],
            'reason' => $reason,
            'continue_ids' => $continueIds,
        ]);
        sendSuccessResponse(['box' => iboxBoxPayload(iboxLoadBox($db, (int)$box['id']))], '取引終了を確定しました');
    }

    if ($action === 'reopen') {
        if ($box['status'] !== 'ended') sendErrorResponse('この取引は終了していません。', 400);
        $reason = iboxApiText($input['reason'] ?? '', 500);
        if ($reason === '') sendErrorResponse('取引を再開する理由を入力してください。', 400);
        $db->beginTransaction();
        // 期限切れで失効した方は自動復活させない（必要な相手だけ再招待する）
        if (!empty($box['access_until']) && iboxNow() > $box['access_until']) {
            foreach (iboxParticipants($db, (int)$box['id']) as $pp) {
                if ((int)$pp['is_owner'] === 1 || (int)$pp['continue_access'] === 1) continue;
                $db->prepare("UPDATE ibox_participants SET status = 'suspended', notify_status = 'unsent', updated_at = ? WHERE id = ?")->execute([iboxNow(), (int)$pp['id']]);
                iboxBumpAuthVersion($db, (int)$pp['id']);
            }
        }
        $db->prepare("UPDATE ibox_boxes SET status = 'active', end_date = NULL, access_until = NULL, updated_at = ? WHERE id = ?")->execute([iboxNow(), (int)$box['id']]);
        $db->commit();
        iboxAudit($db, (int)$box['id'], (int)$p['id'], 'reopen', 'box', (int)$box['id'], ['reason' => $reason, 'before_end_date' => $box['end_date']]);
        sendSuccessResponse(['box' => iboxBoxPayload(iboxLoadBox($db, (int)$box['id']))], '取引を再開しました。必要な相手を再招待してください。');
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('infobox/box.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

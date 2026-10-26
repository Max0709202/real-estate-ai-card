<?php
/**
 * 取引関係者（仕様 第1章・画面3〜6）。
 *
 * GET  ?action=list&box_id=                 … 役割ごとの関係者一覧
 * GET  ?action=import&box_id=&source=card|property … 名刺・物件詳細からの取込み（仲介会社情報のみ・所有者）
 * POST {action:'save', box_id, id?, role, ...}   … 新規登録／修正（下書き保存。メールは送らない）
 * POST {action:'suspend'|'reactivate', box_id, id}
 * POST {action:'notify', box_id, ids:[]}         … 利用通知メール（宛先ごとに結果を返す）
 *
 * 買主・売主の住所・メール・電話は、所有者とその入力者の管理画面にだけ返す。
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/infobox-email-helper.php';

header('Content-Type: application/json; charset=UTF-8');

function iboxParticipantListPayload(PDO $db, array $box, array $viewerP): array
{
    $all = iboxParticipants($db, (int)$box['id'], true);
    $nameById = iboxApiNameMap($all);
    $bySlot = [];
    $others = [];
    foreach ($all as $pp) {
        $row = iboxSerializeParticipant($box, $pp, $viewerP, $nameById, true);
        if ((int)$pp['is_owner'] === 1) {
            foreach (iboxViewerRoles($box, $pp) as $role) $bySlot[$role] = $row;
            continue;
        }
        if ($pp['role'] === 'other') { $others[] = $row; continue; }
        // 同じ役割は有効な行を優先（参加停止した旧行より後から登録した行を表示）
        if (!isset($bySlot[$pp['role']]) || $pp['status'] === 'active') $bySlot[$pp['role']] = $row;
    }
    $slots = [];
    foreach (iboxRoles() as $role => $def) {
        if ($role === 'other') continue;
        $slots[] = [
            'role' => $role,
            'role_label' => $def['label'],
            'kind' => $def['kind'],
            'participant' => $bySlot[$role] ?? null,
            'can_register' => !isset($bySlot[$role]) || $bySlot[$role]['status'] !== 'active'
                ? iboxCanRegisterRole($box, $viewerP, $role) : false,
        ];
    }
    return [
        'slots' => $slots,
        'others' => $others,
        'can_add_other' => iboxCanRegisterRole($box, $viewerP, 'other'),
    ];
}

try {
    $db = iboxApiDb();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        $action = (string)($_GET['action'] ?? 'list');
        if ($action === 'list') {
            sendSuccessResponse(iboxParticipantListPayload($db, $viewer['box'], $viewer['p']));
        }
        if ($action === 'import') {
            iboxApiRequireOwner($viewer);
            $box = $viewer['box'];
            if (($_GET['source'] ?? '') === 'property') {
                if (empty($box['property_id'])) sendErrorResponse('物件詳細と紐づいていません。', 400);
                $stmt = $db->prepare('SELECT seller_company, seller_branch, seller_person, seller_email, seller_phone FROM properties WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$box['property_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                sendSuccessResponse(['values' => [
                    'company_name' => trim((string)($row['seller_company'] ?? '') . ' ' . (string)($row['seller_branch'] ?? '')),
                    'name' => (string)($row['seller_person'] ?? ''),
                    'address' => '',
                    'email' => (string)($row['seller_email'] ?? ''),
                    'phone' => (string)($row['seller_phone'] ?? ''),
                ]]);
            }
            $card = iboxOwnerCard($db, (int)$box['owner_user_id']);
            sendSuccessResponse(['values' => [
                'company_name' => (string)($card['company_name'] ?? ''),
                'name' => (string)($card['name'] ?? ''),
                'address' => (string)($card['company_address'] ?? ''),
                'email' => (string)($card['email'] ?? ''),
                'phone' => (string)($card['mobile_phone'] ?? ($card['company_phone'] ?? '')),
            ]]);
        }
        sendErrorResponse('不明な操作です', 400);
    }

    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');
    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0), true);
    $box = $viewer['box'];
    $me = $viewer['p'];

    if ($action === 'save') {
        $id = (int)($input['id'] ?? 0);
        $values = [
            'company_name' => iboxApiText($input['company_name'] ?? ''),
            'name' => iboxApiText($input['name'] ?? '', 128),
            'address' => iboxApiText($input['address'] ?? ''),
            'email' => iboxNormalizeEmail(iboxApiText($input['email'] ?? '')),
            'phone' => iboxApiText($input['phone'] ?? '', 64),
        ];
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            sendErrorResponse('メールアドレスの形式が正しくありません。', 400);
        }
        if ($values['phone'] !== '' && strlen(iboxNormalizePhone($values['phone'])) < 10) {
            sendErrorResponse('電話番号を正しく入力してください。', 400);
        }

        if ($id > 0) {
            $target = iboxLoadParticipant($db, $id);
            if (!$target || (int)$target['box_id'] !== (int)$box['id']) sendErrorResponse('関係者が見つかりません。', 404);
            if (!iboxCanEditParticipant($me, $target)) sendErrorResponse('この関係者を修正できるのは、名刺所有者と登録した方だけです。', 403);
            $roles = iboxRoles();
            if (($roles[$target['role']]['kind'] ?? '') === 'person' && (int)$target['is_owner'] === 0) $values['company_name'] = '';
            if ($values['name'] === '' && $values['company_name'] === '') sendErrorResponse('氏名または会社名を入力してください。', 400);

            $contactChanged = iboxNormalizeEmail($target['email']) !== $values['email']
                || iboxNormalizePhone($target['phone']) !== iboxNormalizePhone($values['phone']);
            $db->prepare('UPDATE ibox_participants SET company_name = ?, name = ?, address = ?, email = ?, phone = ?, phone_norm = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([$values['company_name'], $values['name'], $values['address'], $values['email'], $values['phone'], iboxNormalizePhone($values['phone']), (int)$me['id'], iboxNow(), $id]);
            if ($contactChanged && (int)$target['is_owner'] === 0) {
                // 連絡先が変わったら旧URL・既存セッションを失効させ、改めて通知が必要な状態に戻す
                iboxBumpAuthVersion($db, $id);
                $db->prepare("UPDATE ibox_participants SET notify_status = 'unsent' WHERE id = ? AND notify_status <> 'sending'")->execute([$id]);
            }
            iboxAudit($db, (int)$box['id'], (int)$me['id'], 'participant_update', 'participant', $id, ['contact_changed' => $contactChanged]);
            sendSuccessResponse(iboxParticipantListPayload($db, $box, $me), '保存しました');
        }

        $role = (string)($input['role'] ?? '');
        $roles = iboxRoles();
        if (!isset($roles[$role])) sendErrorResponse('役割が正しくありません。', 400);
        if (!iboxCanRegisterRole($box, $me, $role)) {
            sendErrorResponse('この役割の関係者を登録する権限がありません。', 403);
        }
        if (empty($roles[$role]['multi'])) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM ibox_participants WHERE box_id = ? AND role = ? AND status = 'active'");
            $stmt->execute([(int)$box['id'], $role]);
            if ((int)$stmt->fetchColumn() > 0) sendErrorResponse($roles[$role]['label'] . 'は既に登録されています。', 409);
        }
        if ($roles[$role]['kind'] === 'person') $values['company_name'] = '';
        if ($values['name'] === '' && $values['company_name'] === '') sendErrorResponse('氏名または会社名を入力してください。', 400);

        $db->prepare('
            INSERT INTO ibox_participants (box_id, role, is_owner, company_name, name, address, email, phone, phone_norm, status, registered_by, updated_by, notify_status, created_at, updated_at)
            VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, \'unsent\', ?, ?)
        ')->execute([
            (int)$box['id'], $role, $values['company_name'], $values['name'], $values['address'], $values['email'], $values['phone'],
            iboxNormalizePhone($values['phone']), (int)$me['id'], (int)$me['id'], iboxNow(), iboxNow(),
        ]);
        $newId = (int)$db->lastInsertId();
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'participant_add', 'participant', $newId, ['role' => $role]);
        sendSuccessResponse(iboxParticipantListPayload($db, $box, $me) + ['created_id' => $newId], '登録しました');
    }

    if ($action === 'suspend' || $action === 'reactivate') {
        $target = iboxLoadParticipant($db, (int)($input['id'] ?? 0));
        if (!$target || (int)$target['box_id'] !== (int)$box['id'] || (int)$target['is_owner'] === 1) sendErrorResponse('関係者が見つかりません。', 404);
        if (!iboxCanEditParticipant($me, $target)) sendErrorResponse('参加停止・再開できるのは、名刺所有者と登録した方だけです。', 403);
        if ($action === 'suspend') {
            $db->prepare("UPDATE ibox_participants SET status = 'suspended', updated_by = ?, updated_at = ? WHERE id = ?")->execute([(int)$me['id'], iboxNow(), (int)$target['id']]);
            iboxBumpAuthVersion($db, (int)$target['id']);
        } else {
            $roles = iboxRoles();
            if (empty($roles[$target['role']]['multi'])) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM ibox_participants WHERE box_id = ? AND role = ? AND status = 'active' AND id <> ?");
                $stmt->execute([(int)$box['id'], $target['role'], (int)$target['id']]);
                if ((int)$stmt->fetchColumn() > 0) sendErrorResponse('同じ役割の関係者が既に参加しています。', 409);
            }
            // 再開しても旧URLは使えない。改めて「関係者に通知」で新しいURLを送る。
            $db->prepare("UPDATE ibox_participants SET status = 'active', notify_status = 'unsent', updated_by = ?, updated_at = ? WHERE id = ?")->execute([(int)$me['id'], iboxNow(), (int)$target['id']]);
        }
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'participant_' . $action, 'participant', (int)$target['id']);
        sendSuccessResponse(iboxParticipantListPayload($db, $box, $me), $action === 'suspend' ? '参加を停止しました' : '参加を再開しました。関係者に通知してください。');
    }

    if ($action === 'notify') {
        $ids = array_values(array_unique(array_map('intval', (array)($input['ids'] ?? []))));
        if (!$ids) sendErrorResponse('送信先を選択してください。', 400);
        $results = [];
        foreach ($ids as $id) {
            $target = iboxLoadParticipant($db, $id);
            if (!$target || (int)$target['box_id'] !== (int)$box['id'] || $target['status'] !== 'active') {
                $results[] = ['id' => $id, 'ok' => false, 'status' => 'failed', 'message' => '送信できない関係者です。'];
                continue;
            }
            if (!iboxCanNotifyParticipant($me, $target)) {
                $results[] = ['id' => $id, 'ok' => false, 'status' => (string)$target['notify_status'], 'message' => 'ご自身が登録した関係者だけに通知できます。'];
                continue;
            }
            $results[] = ['id' => $id, 'name' => iboxParticipantName($target)] + iboxSendInvite($db, $box, $target, $me);
        }
        $sent = count(array_filter($results, fn($r) => $r['ok']));
        sendSuccessResponse(
            ['results' => $results] + iboxParticipantListPayload($db, $box, $me),
            $sent === count($results) ? '通知を送信しました' : '一部の宛先に送信できませんでした（登録内容は保存されています）'
        );
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    error_log('infobox/participants.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

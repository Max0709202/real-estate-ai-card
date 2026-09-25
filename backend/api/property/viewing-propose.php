<?php
/**
 * 内見日程調整: エージェントの確認と打診（§5）。
 * POST(JSON) 担当ログイン必須
 *   action='propose'  { property_id, slot_ids:[..], buyer_attributes?, seller:{...}? }
 *       対応できる日時（黄）を選び、売主（仲介）会社へ M03（初回）／M08（日時変更）を送る。
 *   action='reinput'  { property_id }
 *       対応可能な日時がない場合。買主へ M02 を送り、希望日時の入力画面へ案内する。
 *
 * 売主仲介会社情報（販売会社名・担当者名・メールアドレス・販売会社電話番号・取引態様・備考）は
 * 読み取りミスを修正できるよう、この画面から全項目を更新できる。
 * ★購入検討者属性（buyer_attributes）はエージェントの自由入力。売主側の回答画面に表示する。
 */
require_once __DIR__ . '/viewing-common.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = viewingApiInput();
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$action = trim((string)($input['action'] ?? 'propose'));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    $auth = viewingApiAuthorize($db, $propertyId, $input);
    if ($auth['role'] !== 'agent') sendErrorResponse('担当者としてログインしてください', 403);
    $property = $auth['property'];

    $case = viewingFindByPropertySession($db, $propertyId, (string)$property['session_id']);
    if (!$case) sendErrorResponse('内見依頼がありません', 404);

    /* ---- 内見不可（成約・申込済み）の旨を買主へ案内する ----
       売主側の回答では買主へ自動送信せず、担当者が内容を確認して送る（仕様 §6 補足案）。 */
    if ($action === 'notify_unavailable') {
        if ((string)$case['status'] !== 'unavailable') sendErrorResponse('内見不可の案件ではありません', 409);
        $message = mb_substr(trim((string)($input['message'] ?? '')), 0, 2000);
        $res = viewingMailSend($db, $case, 'N03', ['message' => $message]);
        if ($res['sent'] === 0) sendErrorResponse('買主へのメールを送信できませんでした。宛先をご確認ください。', 502);
        viewingLogEvent($db, (int)$case['id'], 'buyer_unavailable_notified', ['actor' => 'agent']);
        sendSuccessResponse(viewingApiCasePayload($db, viewingLoad($db, (int)$case['id']), $property, 'agent'), '買主へご案内を送信しました。');
        exit;
    }

    if (!viewingIsOpen($case)) sendErrorResponse('この内見はすでに' . (viewingStatusDefs()[$case['status']]['label'] ?? '終了') . 'です。', 409);

    /* ---- 売主仲介会社情報の更新（全項目編集可） ---- */
    if (is_array($input['seller'] ?? null)) {
        $s = $input['seller'];
        $map = [
            'company'          => 'seller_company',
            'person'           => 'seller_person',
            'email'            => 'seller_email',
            'phone'            => 'seller_phone',
            'transaction_type' => 'transaction_type',
            'remarks'          => 'seller_remarks',
        ];
        $sets = [];
        $vals = [];
        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $s)) continue;
            $sets[] = "{$col} = ?";
            $vals[] = mb_substr(trim((string)$s[$key]), 0, $col === 'seller_remarks' ? 2000 : 255);
        }
        if ($sets) {
            $vals[] = $propertyId;
            $db->prepare("UPDATE properties SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            $property = viewingLoadProperty($db, $propertyId);
        }
    }

    /* ---- 購入検討者属性（エージェントの自由入力） ---- */
    if (array_key_exists('buyer_attributes', $input)) {
        $attrs = mb_substr(trim((string)$input['buyer_attributes']), 0, 2000);
        $db->prepare("UPDATE property_viewings SET buyer_attributes = ? WHERE id = ?")->execute([$attrs, (int)$case['id']]);
        $case['buyer_attributes'] = $attrs;
    }

    /* ---- 対応可能な日時がない場合：買主へ再入力を依頼する ---- */
    if ($action === 'reinput') {
        $db->prepare("UPDATE property_viewings SET status = 'buyer_reinput' WHERE id = ?")->execute([(int)$case['id']]);
        $fresh = viewingLoad($db, (int)$case['id']);
        viewingLogEvent($db, (int)$case['id'], 'agent_reinput', ['actor' => 'agent']);
        // sendSuccessResponse() は exit するため、送信処理はレスポンスより先に登録しておく。
        viewingApiAfterResponse(function () use ($db, $fresh) { viewingMailSend($db, $fresh, 'M02'); });
        sendSuccessResponse(viewingApiCasePayload($db, $fresh, $property, 'agent'), '買主へ別の日時のご依頼を送信しました。');
        exit;
    }

    /* ---- 対応可能な候補（黄）を確定して売主（仲介）会社へ打診する ---- */
    $slotIds = array_values(array_unique(array_map('intval', is_array($input['slot_ids'] ?? null) ? $input['slot_ids'] : [])));
    if (!$slotIds) sendErrorResponse('対応できる日時を1つ以上お選びください。', 400);

    // 買主が選んでいない日時は追加しない（この案件・この round の候補だけを黄にする）。
    $ph = implode(',', array_fill(0, count($slotIds), '?'));
    $stmt = $db->prepare("SELECT id FROM property_viewing_slots
                          WHERE viewing_id = ? AND round = ? AND id IN ({$ph})");
    $stmt->execute(array_merge([(int)$case['id'], (int)$case['round']], $slotIds));
    $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$valid) sendErrorResponse('選択された日時が買主の希望候補に含まれていません。', 400);

    // 宛先（売主仲介会社のメール）の確認。未入力・形式不正なら送信できない。
    $sellerEmail = trim((string)($property['seller_email'] ?? ''));
    if ($sellerEmail === '' || !filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
        sendErrorResponse('売主（仲介）会社のメールアドレスをご確認ください。未入力または形式が正しくありません。', 400);
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE property_viewing_slots SET state = 'buyer' WHERE viewing_id = ? AND round = ?")
           ->execute([(int)$case['id'], (int)$case['round']]);
        $ph2 = implode(',', array_fill(0, count($valid), '?'));
        $db->prepare("UPDATE property_viewing_slots SET state = 'agent' WHERE viewing_id = ? AND id IN ({$ph2})")
           ->execute(array_merge([(int)$case['id']], $valid));
        $db->prepare("UPDATE property_viewings SET status = 'seller_pending', unavailable_reason = NULL WHERE id = ?")->execute([(int)$case['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $isReschedule = (int)$case['is_rescheduling'] === 1;
    $fresh = viewingLoad($db, (int)$case['id']);
    viewingLogEvent($db, (int)$case['id'], 'agent_propose', [
        'actor' => 'agent', 'recipient' => $sellerEmail, 'detail' => count($valid) . '枠を打診',
    ]);

    // sendSuccessResponse() は exit するため、送信処理はレスポンスより先に登録しておく。
    viewingApiAfterResponse(function () use ($db, $fresh, $isReschedule) {
        viewingMailSend($db, $fresh, $isReschedule ? 'M08' : 'M03');
    });
    sendSuccessResponse(
        viewingApiCasePayload($db, $fresh, $property, 'agent'),
        '売主（仲介）会社へ内見を打診しました。'
    );
} catch (Exception $e) {
    error_log('viewing-propose error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

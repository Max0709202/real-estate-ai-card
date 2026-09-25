<?php
/**
 * 内見日程調整: 売主（仲介）会社の回答（§6）。専用URLのトークンだけで操作する（ログイン不要）。
 *
 * GET  ?t=<token>                     回答画面に出す内容を返す
 * POST { t, action:'accept',   slot_id, key_method, key:{...} }  1枠を選び鍵情報を入力して承諾
 * POST { t, action:'contracted' }     成約・申込済みにより内見不可
 * POST { t, action:'no_slot' }        候補日時では内見不可（成約済みとは別の回答）
 *
 * ★2026/9/20 追加ご依頼
 *   回答画面には、カレンダーのほかに「購入検討者属性」（エージェントの自由入力）を表示する。
 *
 * 買主の赤い候補や他案件の予定は返さない。旧候補への回答・承諾済み案件への再承諾・
 * キャンセル済み案件への承諾は受け付けず、現在の状態を返す。
 */
require_once __DIR__ . '/viewing-common.php';
require_once __DIR__ . '/../../includes/upload_security.php';
require_once __DIR__ . '/../../includes/viewing-reminder-helper.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$input = $isPost ? viewingApiInput() : $_GET;
$token = trim((string)($input['t'] ?? ''));

try {
    $db = (new Database())->getConnection();
    viewingEnsureTables($db);

    $ref = viewingTokenLookup($db, $token);
    if ($ref === null || $ref['audience'] !== 'seller') sendErrorResponse('このURLは無効です。お手数ですが担当者へご連絡ください。', 403);

    $case = viewingLoad($db, $ref['viewing_id']);
    if (!$case) sendErrorResponse('対象の内見が見つかりません', 404);
    $property = viewingLoadProperty($db, (int)$case['property_id']) ?: [];
    $agent = viewingAgentContact($db, (int)$case['business_card_id']);

    /** 回答画面へ返す内容。緑（承諾済み）を含む黄色の候補だけを返す。 */
    $payload = function (array $case) use ($db, $property, $agent) {
        $slots = array_values(array_filter(viewingSlots($db, (int)$case['id'], (int)$case['round']),
            fn($s) => in_array($s['state'], ['agent', 'seller'], true)));
        $keyDefs = viewingKeyMethodDefs();
        return [
            'property' => [
                // ★物件名には金額を併記する
                'label'   => viewingPropertyLabel($property),
                'address' => (string)($property['address'] ?? ''),
                'layout'  => (string)($property['layout'] ?? ''),
            ],
            'viewing' => [
                'status'        => (string)$case['status'],
                'status_label'  => viewingStatusDefs()[(string)$case['status']]['label'] ?? (string)$case['status'],
                'is_rescheduling' => (int)$case['is_rescheduling'] === 1,
                'prev_text'     => viewingFormatRange($case['prev_start_at'], $case['prev_end_at']),
                'confirmed_text' => viewingFormatRange($case['confirmed_start_at'], $case['confirmed_end_at']),
                'key_method'    => $case['key_method'],
                'key_data'      => $case['key_data'] ?? [],
                // ★購入検討者属性（エージェントの自由入力）を回答画面に表示する
                'buyer_attributes' => (string)($case['buyer_attributes'] ?? ''),
            ],
            'slots' => array_map(fn($s) => [
                'id' => (int)$s['id'], 'start_at' => $s['start_at'], 'end_at' => $s['end_at'],
                'state' => $s['state'], 'text' => viewingFormatRange($s['start_at'], $s['end_at']),
            ], $slots),
            'key_methods' => $keyDefs,
            'agent'       => $agent,
            'attachments' => viewingAttachments($db, (int)$case['id']),
        ];
    };

    if (!$isPost) {
        // 回答ページへのアクセス（メール開封とは別の記録）。
        viewingLogEvent($db, (int)$case['id'], 'page_open', ['actor' => 'seller']);
        sendSuccessResponse($payload($case));
    }

    $action = trim((string)($input['action'] ?? ''));
    if (!viewingIsOpen($case)) {
        sendErrorResponse('この内見はすでに' . (viewingStatusDefs()[$case['status']]['label'] ?? '終了') . 'です。', 409, $payload($case));
    }

    /* ---- 成約・申込済みにより内見不可 ---- */
    if ($action === 'contracted' || $action === 'no_slot') {
        $isContracted = ($action === 'contracted');
        if ($isContracted) {
            $db->prepare("UPDATE property_viewings SET status = 'unavailable', unavailable_reason = 'contracted' WHERE id = ?")
               ->execute([(int)$case['id']]);
            viewingReminderCancelAll($db, (int)$case['id'], '売主側より成約・申込済みの回答');
        } else {
            // 候補日時では調整不可。成約済みとは別の回答として受け付け、案件は継続する。
            $db->prepare("UPDATE property_viewings SET status = 'agent_review', unavailable_reason = 'no_slot' WHERE id = ?")
               ->execute([(int)$case['id']]);
        }
        $fresh = viewingLoad($db, (int)$case['id']);
        viewingLogEvent($db, (int)$case['id'], 'replied', [
            'actor' => 'seller', 'detail' => $isContracted ? '成約・申込済み' : '候補日時では内見不可',
        ]);
        // sendSuccessResponse() は exit するため、送信処理はレスポンスより先に登録しておく。
        viewingApiAfterResponse(function () use ($db, $fresh, $isContracted) {
            viewingMailSend($db, $fresh, $isContracted ? 'N01' : 'N02');
        });
        sendSuccessResponse($payload($fresh), 'ご回答ありがとうございました。担当者へお伝えいたします。');
        exit;
    }

    if ($action !== 'accept') sendErrorResponse('不正な操作です', 400);

    /* ---- 1枠を選び、鍵情報を入力して承諾する ---- */
    $slotId = isset($input['slot_id']) ? (int)$input['slot_id'] : 0;
    $stmt = $db->prepare("SELECT * FROM property_viewing_slots
                          WHERE id = ? AND viewing_id = ? AND round = ? AND state IN ('agent','seller') LIMIT 1");
    $stmt->execute([$slotId, (int)$case['id'], (int)$case['round']]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) sendErrorResponse('候補日時をお選びください。', 400);

    $keyDefs = viewingKeyMethodDefs();
    $method = trim((string)($input['key_method'] ?? ''));
    if (!isset($keyDefs[$method])) sendErrorResponse('鍵の受け渡し方法をお選びください。', 400);

    $raw = is_array($input['key'] ?? null) ? $input['key'] : [];
    $keyData = [];
    foreach ($keyDefs[$method]['fields'] as $field => $label) {
        $keyData[$field] = mb_substr(trim((string)($raw[$field] ?? '')), 0, 500);
    }
    $missing = [];
    foreach ($keyDefs[$method]['required'] as $field) {
        if ($keyData[$field] === '') $missing[] = $keyDefs[$method]['fields'][$field];
    }
    if ($missing) sendErrorResponse('次の項目をご入力ください：' . implode('／', $missing), 400);

    $db->beginTransaction();
    try {
        // 同時に選べるのは1枠のみ。直前の選択は解除する。
        $db->prepare("UPDATE property_viewing_slots SET state = 'agent' WHERE viewing_id = ? AND round = ? AND state = 'seller'")
           ->execute([(int)$case['id'], (int)$case['round']]);
        $db->prepare("UPDATE property_viewing_slots SET state = 'seller' WHERE id = ?")->execute([$slotId]);
        $stmt = $db->prepare("UPDATE property_viewings SET
                    status = 'confirmed', confirmed_slot_id = ?, confirmed_start_at = ?, confirmed_end_at = ?,
                    confirmed_version = confirmed_version + 1, confirmed_at = ?,
                    key_method = ?, key_json = ?, unavailable_reason = NULL
                  WHERE id = ? AND status = 'seller_pending'");
        $stmt->execute([
            $slotId, $slot['start_at'], $slot['end_at'], viewingNow()->format('Y-m-d H:i:s'),
            $method, json_encode($keyData, JSON_UNESCAPED_UNICODE), (int)$case['id'],
        ]);
        if ($stmt->rowCount() === 0) {
            // 旧候補への回答・承諾済み案件への再承諾は受け付けない。
            $db->rollBack();
            $fresh = viewingLoad($db, (int)$case['id']);
            sendErrorResponse('この内見はすでに回答済みです。現在の内容をご確認ください。', 409, $payload($fresh));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $fresh = viewingLoad($db, (int)$case['id']);
    viewingLogEvent($db, (int)$case['id'], 'replied', [
        'actor' => 'seller', 'detail' => '承諾：' . viewingFormatRange($slot['start_at'], $slot['end_at']),
    ]);

    // この時点では買主への確定メール（M06）は送らない（仕様 §2・§6）。
    // sendSuccessResponse() は exit するため、送信処理はレスポンスより先に登録しておく。
    $changed = (int)$fresh['is_rescheduling'] === 1;
    viewingApiAfterResponse(function () use ($db, $fresh, $changed) {
        viewingMailSend($db, $fresh, 'M04', ['changed' => $changed]);
        viewingMailSend($db, $fresh, 'M05', ['changed' => $changed]);
    });

    sendSuccessResponse($payload($fresh), 'ご回答ありがとうございました。内見日時が確定しました。');
} catch (Exception $e) {
    error_log('viewing-seller error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

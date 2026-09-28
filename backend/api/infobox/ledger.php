<?php
/**
 * 取引台帳（仕様 第4章・画面21）。名刺所有者だけが使う自社内部資料。
 *
 * GET  ?action=state&box_id=          … 項目定義・下書き・保存済みの版
 * GET  ?action=index[&year=&office=]  … 自分の台帳一覧（事務所・事業年度別の検索）
 * POST {action:'autofill', box_id, overwrite}   … 売買契約書・重要事項説明書から自動取得
 * POST {action:'save_draft', box_id, data}      … 下書き保存（取引終了前でも登録・保存できる）
 * POST {action:'generate', box_id, data, reason, op_key} … PDF を作成して新しい版として保存
 *
 * ・売買契約書と重要事項説明書が無い場合は、台帳を作らずエラーを返す（お客様のご指示）。
 * ・必須項目の不足は項目別に返し、完成PDFを作らない。「該当なし」と未入力は区別する。
 * ・訂正は理由付きの新しい版として保存し、旧版の PDF と入力値は残す。
 */
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/infobox-ledger-helper.php';
require_once __DIR__ . '/../../includes/infobox-pdf-helper.php';

header('Content-Type: application/json; charset=UTF-8');

function iboxLedgerFieldDefsPayload(): array
{
    $out = [];
    foreach (iboxLedgerFields() as $key => $def) {
        $out[] = ['key' => $key, 'label' => $def[0], 'group' => $def[1], 'required' => $def[2], 'type' => $def[3]];
    }
    return $out;
}

function iboxLedgerVersionsPayload(PDO $db, array $box): array
{
    return array_map(fn($v) => [
        'id' => (int)$v['id'],
        'version' => (int)$v['version'],
        'file_name' => '取引台帳_' . $box['transaction_code'] . '_v' . (int)$v['version'] . '.pdf',
        'reason' => (string)($v['reason'] ?? ''),
        'created_at' => $v['created_at'],
        'url' => 'backend/api/infobox/file.php?kind=ledger&box_id=' . (int)$box['id'] . '&id=' . (int)$v['id'],
    ], iboxLedgerVersions($db, (int)$box['id']));
}

try {
    $db = iboxApiDb();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET' && ($_GET['action'] ?? '') === 'index') {
        $userId = iboxApiRequireEnabledUser($db);
        $where = ['l.owner_user_id = ?'];
        $params = [$userId];
        if (($year = (int)($_GET['year'] ?? 0)) > 0) { $where[] = 'l.fiscal_year = ?'; $params[] = $year; }
        if (($office = trim((string)($_GET['office'] ?? ''))) !== '') { $where[] = 'l.office_name LIKE ?'; $params[] = '%' . $office . '%'; }
        // 各 BOX の最新版だけを一覧にする（旧版は BOX の台帳画面に残る）
        $stmt = $db->prepare('
            SELECT l.id, l.box_id, l.version, l.office_name, l.fiscal_year, l.ledger_no, l.created_at, b.transaction_code, b.property_name
            FROM ibox_ledgers l
            JOIN ibox_boxes b ON b.id = l.box_id
            WHERE ' . implode(' AND ', $where) . '
              AND l.version = (SELECT MAX(l2.version) FROM ibox_ledgers l2 WHERE l2.box_id = l.box_id)
            ORDER BY l.fiscal_year DESC, l.created_at DESC
            LIMIT 500
        ');
        $stmt->execute($params);
        $rows = array_map(fn($r) => [
            'box_id' => (int)$r['box_id'],
            'version' => (int)$r['version'],
            'office_name' => (string)$r['office_name'],
            'fiscal_year' => $r['fiscal_year'] !== null ? (int)$r['fiscal_year'] : null,
            'ledger_no' => (string)$r['ledger_no'],
            'transaction_code' => (string)$r['transaction_code'],
            'property_name' => (string)$r['property_name'],
            'created_at' => $r['created_at'],
            'url' => 'backend/api/infobox/file.php?kind=ledger&box_id=' . (int)$r['box_id'] . '&id=' . (int)$r['id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        sendSuccessResponse(['ledgers' => $rows]);
    }

    if ($method === 'GET') {
        $viewer = iboxApiViewer($db, (int)($_GET['box_id'] ?? 0));
        iboxApiRequireOwner($viewer);
        $box = $viewer['box'];
        $draft = iboxLedgerLoadDraft($db, (int)$box['id']);
        $sources = iboxLedgerSourceDocs($db, $box, $viewer['p']);
        sendSuccessResponse([
            'fields' => iboxLedgerFieldDefsPayload(),
            'draft' => $draft['data'] ?? null,
            'draft_updated_at' => $draft['updated_at'] ?? null,
            'missing' => $draft ? iboxLedgerMissing($draft['data'], $box) : null,
            'versions' => iboxLedgerVersionsPayload($db, $box),
            'has_sources' => ['contract' => !empty($sources['contract']), 'explanation' => !empty($sources['explanation'])],
        ]);
    }

    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');
    // 台帳の追記・再生成は、利用期限経過後も所有者だけは行える
    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0));
    iboxApiRequireOwner($viewer);
    $box = $viewer['box'];
    $me = $viewer['p'];

    if ($action === 'autofill') {
        @set_time_limit(180);
        $result = iboxLedgerAutoFill($db, $box, $me);
        if (!$result['ok']) sendErrorResponse($result['message'], 422);
        $data = $result['data'];
        $draft = iboxLedgerLoadDraft($db, (int)$box['id']);
        if ($draft && empty($input['overwrite'])) {
            // 入力済みの下書きは残し、空欄だけを埋める
            foreach ($draft['data'] as $key => $value) {
                if (trim((string)$value) !== '') $data[$key] = $value;
            }
        }
        iboxLedgerSaveDraft($db, (int)$box['id'], $data);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'ledger_autofill', 'box', (int)$box['id'], ['sources' => $result['sources']]);
        sendSuccessResponse([
            'data' => iboxLedgerSanitize($data),
            'missing' => iboxLedgerMissing($data, $box),
            'sources' => $result['sources'],
            'notes' => $result['notes'],
        ], '売買契約書・重要事項説明書から取得しました。空欄の項目は入力してください。');
    }

    if ($action === 'save_draft') {
        $data = iboxLedgerSanitize((array)($input['data'] ?? []));
        iboxLedgerSaveDraft($db, (int)$box['id'], $data);
        sendSuccessResponse(['missing' => iboxLedgerMissing($data, $box)], '下書きを保存しました');
    }

    if ($action === 'generate') {
        $sources = iboxLedgerSourceDocs($db, $box, $me);
        if (!$sources['contract'] || !$sources['explanation']) {
            sendErrorResponse('売買契約書と重要事項説明書が情報BOXに登録されていないため、取引台帳は作れません。', 422);
        }
        $data = iboxLedgerSanitize((array)($input['data'] ?? []));
        iboxLedgerSaveDraft($db, (int)$box['id'], $data);
        $missing = iboxLedgerMissing($data, $box);
        if ($missing) {
            sendJsonResponse(['success' => false, 'message' => '必須項目が未入力のため、PDFを作成できません。該当しない項目は「該当なし」と入力してください。', 'missing' => $missing], 422);
        }
        $opKey = iboxApiOpKey($input['op_key'] ?? '');
        if ($opKey !== null) {
            $stmt = $db->prepare('SELECT id FROM ibox_ledgers WHERE box_id = ? AND op_key = ? LIMIT 1');
            $stmt->execute([(int)$box['id'], $opKey]);
            if ($stmt->fetchColumn()) sendSuccessResponse(['versions' => iboxLedgerVersionsPayload($db, $box)], 'PDFを作成して保存しました');
        }
        $stmt = $db->prepare('SELECT COALESCE(MAX(version), 0) FROM ibox_ledgers WHERE box_id = ?');
        $stmt->execute([(int)$box['id']]);
        $version = (int)$stmt->fetchColumn() + 1;
        $reason = iboxApiText($input['reason'] ?? '', 500);
        if ($version > 1 && $reason === '') sendErrorResponse('訂正の理由を入力してください（新しい版として保存し、旧版も残します）。', 400);

        $pdf = iboxRenderLedgerPdf($data, ['version' => $version, 'transaction_code' => $box['transaction_code']]);
        $pdfName = iboxRandomName('pdf');
        $path = iboxStorageDir((int)$box['id']) . '/' . $pdfName;
        if (@file_put_contents($path, $pdf) === false) sendErrorResponse('PDFの作成に失敗しました。もう一度お試しください。', 500);
        try {
            $db->prepare('INSERT INTO ibox_ledgers (box_id, owner_user_id, version, ledger_no, office_name, fiscal_year, data_json, pdf_name, reason, op_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    (int)$box['id'], (int)$box['owner_user_id'], $version,
                    $data['contract_no'] !== '' ? $data['contract_no'] : $box['transaction_code'],
                    $data['office_name'], (int)$data['fiscal_year'] ?: null,
                    json_encode($data, JSON_UNESCAPED_UNICODE), $pdfName, $reason !== '' ? $reason : null, $opKey, iboxNow(),
                ]);
        } catch (Throwable $e) {
            // 同時操作で同じ版番号になった等。完成状態の PDF だけが残らないよう消す。
            @unlink($path);
            error_log('infobox ledger insert error: ' . $e->getMessage());
            sendErrorResponse('PDFの保存に失敗しました。もう一度お試しください。', 409);
        }
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'ledger_generate', 'ledger', (int)$db->lastInsertId(), ['version' => $version, 'reason' => $reason]);
        sendSuccessResponse(['versions' => iboxLedgerVersionsPayload($db, $box)], '取引台帳（第' . $version . '版）を作成して保存しました');
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    error_log('infobox/ledger.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

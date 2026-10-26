<?php
/**
 * 書類の登録・差替え・共有先・名称変更・削除（仕様 第2・3章、画面10〜12・19）。
 *
 * POST multipart {action:'upload', box_id, folder_id, shares:[id...], op_key}
 * POST multipart {action:'replace', box_id, document_id, op_key}   … op_key が同じ再送は同じ差替えとして扱う
 * POST {action:'shares', box_id, document_id, participant_ids:[]}
 * POST {action:'rename', box_id, document_id, name}
 * POST {action:'delete', box_id, document_id}
 *
 * ・共有先は登録者本人を除いて初期選択なし。候補から実名を選ぶ。閲覧と印刷を一組の権限とする。
 * ・変更・削除・共有先の指定は、その書類をアップロードした本人だけ（所有者・フォルダー責任者も不可）。
 * ・検証・保存に成功してから公開する。差替えが失敗したら旧版を維持する。
 */
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=UTF-8');

/** 共有先として有効な関係者ID（同じBOXの参加中の方。本人は除く）。 */
function iboxCleanShareIds(PDO $db, array $box, array $me, $ids): array
{
    $valid = [];
    $active = [];
    foreach (iboxParticipants($db, (int)$box['id']) as $pp) $active[(int)$pp['id']] = true;
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    foreach ((array)$ids as $id) {
        $id = (int)$id;
        if ($id > 0 && $id !== (int)$me['id'] && isset($active[$id])) $valid[$id] = true;
    }
    return array_keys($valid);
}

/** 共有先の実名（画面・確認用）。0人なら「あなたのみ」。 */
function iboxShareNames(PDO $db, array $ids): string
{
    if (!$ids) return 'あなたのみ';
    $names = [];
    foreach ($ids as $id) {
        $pp = iboxLoadParticipant($db, (int)$id);
        if ($pp) $names[] = iboxParticipantName($pp);
    }
    return 'あなた と ' . implode('・', $names);
}

function iboxRequireMyDocument(PDO $db, array $box, array $me, int $documentId): array
{
    $doc = iboxLoadDocument($db, (int)$box['id'], $documentId);
    // 見えない書類は存在しないのと同じ応答にする
    if (!$doc || !iboxDocVisibleTo($doc, $doc['shares'], $me)) sendErrorResponse('書類が見つかりません。', 404);
    if ((int)$doc['uploader_id'] !== (int)$me['id']) {
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'document_denied', 'document', $documentId);
        sendErrorResponse('書類の変更・削除・共有先の指定は、アップロードした本人だけが行えます。', 403);
    }
    return $doc;
}

try {
    $db = iboxApiDb();
    iboxApiRequirePost();
    $input = iboxApiInput();
    $action = (string)($input['action'] ?? '');
    $viewer = iboxApiViewer($db, (int)($input['box_id'] ?? 0), true);
    $box = $viewer['box'];
    $me = $viewer['p'];

    if ($action === 'upload') {
        $folder = iboxLoadFolder($db, (int)$box['id'], (int)($input['folder_id'] ?? 0));
        if (!$folder) sendErrorResponse('フォルダーが見つかりません。', 404);
        if (!iboxCanUploadToFolder($box, $folder, $me)) sendErrorResponse('このフォルダーに書類を登録する権限がありません。', 403);

        $opKey = iboxApiOpKey($input['op_key'] ?? '');
        if ($opKey !== null) {
            // 同じ操作の再送（連打・通信の再試行）は、登録済みの書類を返す
            $stmt = $db->prepare('SELECT id FROM ibox_documents WHERE uploader_id = ? AND op_key = ? LIMIT 1');
            $stmt->execute([(int)$me['id'], $opKey]);
            if ($existing = $stmt->fetchColumn()) sendSuccessResponse(['document_id' => (int)$existing], '登録しました');
        }

        $shares = iboxCleanShareIds($db, $box, $me, $input['shares'] ?? []);
        $file = $_FILES['file'] ?? null;
        $valid = iboxValidateUpload($file);
        if (!$valid['ok']) sendErrorResponse($valid['message'], 400);
        $stored = iboxStoreValidatedUpload($file, $valid, (int)$box['id']);
        if (!$stored['ok']) sendErrorResponse($stored['message'], 400);

        $now = iboxNow();
        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO ibox_documents (box_id, folder_id, uploader_id, display_name, current_version, status, op_key, created_at, updated_at) VALUES (?, ?, ?, ?, 1, \'active\', ?, ?, ?)')
                ->execute([(int)$box['id'], (int)$folder['id'], (int)$me['id'], $valid['name'], $opKey, $now, $now]);
            $docId = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO ibox_document_versions (document_id, version, stored_name, preview_name, original_name, ext, mime_type, byte_size, sha256, created_by, created_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$docId, $stored['stored_name'], $stored['preview_name'], $valid['name'], $valid['ext'], $valid['mime'], $valid['size'], $stored['sha256'], (int)$me['id'], $now]);
            $ins = $db->prepare('INSERT INTO ibox_document_shares (document_id, participant_id, created_at) VALUES (?, ?, ?)');
            foreach ($shares as $pid) $ins->execute([$docId, $pid, $now]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // 完成状態のレコードを残さない。保存したファイルも消す。
            $dir = iboxStorageDir((int)$box['id']);
            @unlink($dir . '/' . $stored['stored_name']);
            if ($stored['preview_name']) @unlink($dir . '/' . $stored['preview_name']);
            throw $e;
        }
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'document_upload', 'document', $docId, ['folder_id' => (int)$folder['id'], 'shares' => $shares]);
        sendSuccessResponse(['document_id' => $docId, 'viewers' => iboxShareNames($db, $shares)], '登録して共有しました');
    }

    $doc = iboxRequireMyDocument($db, $box, $me, (int)($input['document_id'] ?? 0));

    if ($action === 'replace') {
        $opKey = iboxApiOpKey($input['op_key'] ?? '');
        if ($opKey !== null) {
            // 同じ差替え操作の再送（連打・通信の再試行）は、版を重ねずに結果だけ返す
            $stmt = $db->prepare('SELECT version FROM ibox_document_versions WHERE document_id = ? AND op_key = ? LIMIT 1');
            $stmt->execute([(int)$doc['id'], $opKey]);
            if (($done = $stmt->fetchColumn()) !== false) sendSuccessResponse(['version' => (int)$done], '第' . (int)$done . '版に差し替えました');
        }
        $file = $_FILES['file'] ?? null;
        $valid = iboxValidateUpload($file);
        // 検証・保存に失敗したら旧版をそのまま維持する
        if (!$valid['ok']) sendErrorResponse($valid['message'] . '（差替え前の書類はそのまま残っています）', 400);
        $stored = iboxStoreValidatedUpload($file, $valid, (int)$box['id']);
        if (!$stored['ok']) sendErrorResponse($stored['message'] . '（差替え前の書類はそのまま残っています）', 400);

        $newVersion = (int)$doc['current_version'] + 1;
        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO ibox_document_versions (document_id, version, stored_name, preview_name, original_name, ext, mime_type, byte_size, sha256, op_key, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$doc['id'], $newVersion, $stored['stored_name'], $stored['preview_name'], $valid['name'], $valid['ext'], $valid['mime'], $valid['size'], $stored['sha256'], $opKey, (int)$me['id'], iboxNow()]);
            // 登録者・書類名・共有先は引き継ぎ、版番号だけを更新する（旧版は通常の配信URLから参照できない）
            $stmt = $db->prepare("UPDATE ibox_documents SET current_version = ?, updated_at = ? WHERE id = ? AND current_version = ? AND status = 'active'");
            $stmt->execute([$newVersion, iboxNow(), (int)$doc['id'], (int)$doc['current_version']]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('version conflict');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $dir = iboxStorageDir((int)$box['id']);
            @unlink($dir . '/' . $stored['stored_name']);
            if ($stored['preview_name']) @unlink($dir . '/' . $stored['preview_name']);
            error_log('infobox replace error: ' . $e->getMessage());
            sendErrorResponse('差替えに失敗しました。差替え前の書類はそのまま残っています。もう一度お試しください。', 409);
        }
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'document_replace', 'document', (int)$doc['id'], ['before_version' => (int)$doc['current_version'], 'after_version' => $newVersion]);
        sendSuccessResponse(['version' => $newVersion], '第' . $newVersion . '版に差し替えました');
    }

    if ($action === 'shares') {
        $shares = iboxCleanShareIds($db, $box, $me, $input['participant_ids'] ?? []);
        $db->beginTransaction();
        $db->prepare('DELETE FROM ibox_document_shares WHERE document_id = ?')->execute([(int)$doc['id']]);
        $ins = $db->prepare('INSERT INTO ibox_document_shares (document_id, participant_id, created_at) VALUES (?, ?, ?)');
        foreach ($shares as $pid) $ins->execute([(int)$doc['id'], $pid, iboxNow()]);
        $db->prepare('UPDATE ibox_documents SET updated_at = ? WHERE id = ?')->execute([iboxNow(), (int)$doc['id']]);
        $db->commit();
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'document_shares', 'document', (int)$doc['id'], ['before' => $doc['shares'], 'after' => $shares]);
        sendSuccessResponse(['shares' => $shares, 'viewers' => iboxShareNames($db, $shares)], '共有先を保存しました');
    }

    if ($action === 'rename') {
        $name = iboxApiText($input['name'] ?? '');
        if ($name === '') sendErrorResponse('書類名を入力してください。', 400);
        $db->prepare('UPDATE ibox_documents SET display_name = ?, updated_at = ? WHERE id = ?')->execute([$name, iboxNow(), (int)$doc['id']]);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'document_rename', 'document', (int)$doc['id'], ['before' => $doc['display_name'], 'after' => $name]);
        sendSuccessResponse([], '書類名を変更しました');
    }

    if ($action === 'delete') {
        $db->prepare("UPDATE ibox_documents SET status = 'deleted', deleted_at = ?, updated_at = ? WHERE id = ?")->execute([iboxNow(), iboxNow(), (int)$doc['id']]);
        iboxAudit($db, (int)$box['id'], (int)$me['id'], 'document_delete', 'document', (int)$doc['id'], ['name' => $doc['display_name']]);
        sendSuccessResponse([], '書類を削除しました');
    }

    sendErrorResponse('不明な操作です', 400);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('infobox/documents.php error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

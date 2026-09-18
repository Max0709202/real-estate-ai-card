<?php
/**
 * 体験版（デモ）名刺の「見本」データを、体験者ごとの新しいセッションへ複製する。
 *
 * 背景:
 *   デモ名刺（business_cards.is_demo = 1）はSMS認証を行わず、体験者ごとに使い捨ての
 *   チャットセッションを発行する（api/chat/session/start.php）。そのため、担当が提案した
 *   物件や担当連絡のやり取りは「その体験者のセッション」にしか残らず、次にデモを開いた方は
 *   物件選定タブも担当連絡タブも空のままになる。
 *   商談でお見せする際に「実際に近い環境」にするため、あらかじめ用意した見本の内容を
 *   新しい体験セッションへ自動で複製する。
 *
 * 見本（テンプレート）の作り方:
 *   デモ名刺のアカウントでログイン →「顧客管理」→ 顧客ページの事前作成で
 *   姓に「見本」（DEMO_SAMPLE_CUSTOMER_LAST_NAME で変更可）と入力して作成する。
 *   その顧客に対して物件選定で物件を登録し、担当連絡でメッセージを送っておけば、
 *   以後デモ名刺を開いた方全員に同じ内容が複製される。
 *   見本をやめたい場合は、その顧客ページを削除（またはゴミ箱へ移動）すればよい。
 *
 * 複製の方針:
 *   - 物件（properties）・物件の画像行（property_images）・フォルダー（property_folders）と、
 *     担当連絡チャネルのメッセージ（chat_messages.channel = 'contact'）だけを複製する。
 *     AIチャットの履歴は複製しない（体験者ごとに最初のご挨拶から始めるため）。
 *   - 画像の実ファイルは複製せず、見本と同じファイルを参照する（体験用の読み取り前提）。
 *     そのため複製行の expires_at は NULL にし、保存期限の掃除
 *     （cron/cleanup-expired-properties.php）が見本と共用の実ファイルを消さないようにする。
 *   - 失敗しても体験チャットの開始そのものは止めない（呼び出し側で握りつぶす）。
 */

require_once __DIR__ . '/property-helper.php';
require_once __DIR__ . '/customer-invitation-helper.php';

if (!function_exists('demoSampleCustomerLastName')) {
    /** 見本として扱う事前作成顧客の姓。既定「見本」。 */
    function demoSampleCustomerLastName(): string
    {
        $v = trim((string)(getenv('DEMO_SAMPLE_CUSTOMER_LAST_NAME') ?: ''));
        return $v !== '' ? $v : '見本';
    }
}

if (!function_exists('demoSeedMaxProperties')) {
    /** 1つの体験セッションへ複製する物件の上限件数。 */
    function demoSeedMaxProperties(): int
    {
        $v = (int)(getenv('DEMO_SAMPLE_MAX_PROPERTIES') ?: 20);
        return max(1, min(50, $v));
    }
}

if (!function_exists('demoSeedTemplateSessionId')) {
    /**
     * 見本セッションのIDを返す。見つからなければ空文字。
     * 事前作成顧客（chat_customer_invitations）の姓が見本名と一致するものを新しい順に1件。
     * 体験セッション（is_demo = 1）とゴミ箱の履歴は見本にしない。
     */
    function demoSeedTemplateSessionId(PDO $db, int $cardId): string
    {
        if ($cardId <= 0) return '';
        try {
            customerInviteEnsureTable($db);
            $hasDeletedAt = false;
            try {
                foreach ($db->query('SHOW COLUMNS FROM chat_sessions') as $col) {
                    if (($col['Field'] ?? '') === 'deleted_at') { $hasDeletedAt = true; break; }
                }
            } catch (Throwable $e) { /* 列の確認に失敗したら条件を付けない */ }

            $sql = "SELECT ci.session_id
                    FROM chat_customer_invitations ci
                    JOIN chat_sessions cs ON cs.id = ci.session_id
                    WHERE ci.business_card_id = ?
                      AND ci.last_name = ?
                      AND COALESCE(cs.is_demo, 0) = 0";
            if ($hasDeletedAt) $sql .= " AND cs.deleted_at IS NULL";
            $sql .= " ORDER BY ci.id DESC LIMIT 1";

            $stmt = $db->prepare($sql);
            $stmt->execute([$cardId, demoSampleCustomerLastName()]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            error_log('demoSeedTemplateSessionId error: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('demoSeedInsertCopy')) {
    /**
     * 取得済みの1行を、指定の列を除外・上書きしたうえで同じ表へ挿入する。
     * 列構成をDBから読み取った行そのままで組み立てるため、将来列が増えても追従できる。
     *
     * @param array $skip      除外する列名
     * @param array $overrides 上書きする [列名 => 値]（その表に無い列は無視する）
     * @return int 追加した行のID（取得できなければ0）
     */
    function demoSeedInsertCopy(PDO $db, string $table, array $row, array $skip, array $overrides): int
    {
        foreach ($skip as $column) {
            unset($row[$column]);
        }
        foreach ($overrides as $column => $value) {
            if (array_key_exists($column, $row)) $row[$column] = $value;
        }
        if (empty($row)) return 0;

        $columns = array_keys($row);
        $quoted = array_map(function ($c) { return '`' . $c . '`'; }, $columns);
        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ') VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($row));
        return (int)$db->lastInsertId();
    }
}

if (!function_exists('demoSeedCopyProperties')) {
    /** 見本の物件・画像行・フォルダーを新しいセッションへ複製する。複製した件数を返す。 */
    function demoSeedCopyProperties(PDO $db, string $templateSessionId, string $sessionId, int $cardId): int
    {
        propertyEnsureTables($db);

        // フォルダー（物件の格納先）。旧ID → 新ID の対応表を作る。
        $folderMap = [];
        $stmt = $db->prepare("SELECT * FROM property_folders WHERE session_id = ? ORDER BY id ASC");
        $stmt->execute([$templateSessionId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $folder) {
            $newId = demoSeedInsertCopy($db, 'property_folders', $folder,
                ['id', 'created_at', 'updated_at'],
                ['session_id' => $sessionId, 'business_card_id' => $cardId]
            );
            if ($newId > 0) $folderMap[(int)$folder['id']] = $newId;
        }

        // 体験セッションは開くたびに作られるため、複製する件数に上限を設ける。
        $stmt = $db->prepare("SELECT * FROM properties WHERE session_id = ? ORDER BY id ASC LIMIT " . demoSeedMaxProperties());
        $stmt->execute([$templateSessionId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $copied = 0;
        foreach ($properties as $property) {
            $oldPropertyId = (int)$property['id'];
            $oldFolderId = isset($property['folder_id']) ? (int)$property['folder_id'] : 0;
            $newPropertyId = demoSeedInsertCopy($db, 'properties', $property,
                ['id', 'created_at', 'updated_at'],
                [
                    'session_id' => $sessionId,
                    'business_card_id' => $cardId,
                    'folder_id' => ($oldFolderId > 0 && isset($folderMap[$oldFolderId])) ? $folderMap[$oldFolderId] : null,
                    // 画像行は下で複製するため、サムネイル参照はいったん外して後から張り直す。
                    'thumbnail_image_id' => null,
                    // 見本と実ファイルを共用するため、保存期限の掃除の対象外にする。
                    'expires_at' => null,
                ]
            );
            if ($newPropertyId <= 0) continue;
            $copied++;

            // 画像・販売図面・資料の行。実ファイルは複製せず、同じパスを参照する。
            $imageMap = [];
            $imgStmt = $db->prepare("SELECT * FROM property_images WHERE property_id = ? ORDER BY display_order ASC, id ASC");
            $imgStmt->execute([$oldPropertyId]);
            foreach ($imgStmt->fetchAll(PDO::FETCH_ASSOC) as $image) {
                $newImageId = demoSeedInsertCopy($db, 'property_images', $image,
                    ['id', 'created_at'],
                    [
                        'property_id' => $newPropertyId,
                        'business_card_id' => $cardId,
                        'expires_at' => null,
                    ]
                );
                if ($newImageId > 0) $imageMap[(int)$image['id']] = $newImageId;
            }

            $oldThumbId = isset($property['thumbnail_image_id']) ? (int)$property['thumbnail_image_id'] : 0;
            if ($oldThumbId > 0 && isset($imageMap[$oldThumbId])) {
                $db->prepare("UPDATE properties SET thumbnail_image_id = ? WHERE id = ?")
                   ->execute([$imageMap[$oldThumbId], $newPropertyId]);
            }
        }
        return $copied;
    }
}

if (!function_exists('demoSeedCopyContactMessages')) {
    /**
     * 見本の担当連絡（channel = 'contact'）を新しいセッションへ複製する。複製した件数を返す。
     * 担当の発言は未読のまま入れ、体験者の画面でも「担当連絡」に新着バッジが出るようにする。
     */
    function demoSeedCopyContactMessages(PDO $db, string $templateSessionId, string $sessionId): int
    {
        $stmt = $db->prepare("SELECT role, channel, sender_user_id, message
                              FROM chat_messages
                              WHERE session_id = ? AND channel = 'contact'
                              ORDER BY id ASC
                              LIMIT 100");
        $stmt->execute([$templateSessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        $insert = $db->prepare("INSERT INTO chat_messages (session_id, role, channel, sender_user_id, message, read_at)
                                VALUES (?, ?, 'contact', ?, ?, ?)");
        $copied = 0;
        foreach ($rows as $row) {
            $role = (string)$row['role'];
            // 担当の発言 = 体験者にとっての新着。それ以外（体験者側の発言）は既読で入れる。
            $readAt = $role === 'agent' ? null : date('Y-m-d H:i:s');
            $insert->execute([
                $sessionId,
                $role,
                isset($row['sender_user_id']) ? $row['sender_user_id'] : null,
                (string)$row['message'],
                $readAt,
            ]);
            $copied++;
        }
        return $copied;
    }
}

if (!function_exists('demoSeedApply')) {
    /**
     * 新しく作られた体験セッションへ見本の内容を複製する。
     * 見本が用意されていない場合は何もしない。失敗しても例外は投げない。
     *
     * @return bool 1件でも複製したら true
     */
    function demoSeedApply(PDO $db, string $sessionId, int $cardId): bool
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || $cardId <= 0) return false;

        $templateSessionId = demoSeedTemplateSessionId($db, $cardId);
        if ($templateSessionId === '' || $templateSessionId === $sessionId) return false;

        // 表の用意（CREATE TABLE）はトランザクションの外で済ませる。
        try {
            propertyEnsureTables($db);
        } catch (Throwable $e) {
            error_log('demoSeedApply ensure tables error: ' . $e->getMessage());
            return false;
        }

        $ownTransaction = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $ownTransaction = true;
            }
            $copied = demoSeedCopyProperties($db, $templateSessionId, $sessionId, $cardId);
            $copied += demoSeedCopyContactMessages($db, $templateSessionId, $sessionId);
            if ($ownTransaction) $db->commit();
            return $copied > 0;
        } catch (Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                try { $db->rollBack(); } catch (Throwable $e2) { /* 巻き戻し失敗は無視 */ }
            }
            error_log('demoSeedApply error: ' . $e->getMessage());
            return false;
        }
    }
}

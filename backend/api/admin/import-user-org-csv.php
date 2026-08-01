<?php
/**
 * 組織階層（権限・上長）をCSVで一括設定する。
 *
 * 想定CSV（export-user-org-csv.php が出力する形式と同じ）:
 *   メールアドレス, 氏名, 会社名, 部署, 権限, 上長メールアドレス
 *   ※ 氏名・会社名・部署は目視確認用で、取り込みでは使わない。
 *
 * 重要:
 *   このAPIは既存ユーザーの org_role / parent_user_id しか書き換えない。
 *   ユーザーの新規作成・削除は行わない（存在しないメールアドレスは「スキップ」）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

/** 日本語表記・英語表記のどちらでも受け付ける。判定できなければ null。 */
function orgImportParseRole(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    $normalized = mb_convert_kana($value, 'as');
    $normalized = strtolower(str_replace([' ', '　'], '', $normalized));

    $map = [
        'staff' => 'staff', '担当者' => 'staff', '営業' => 'staff', '担当' => 'staff', '一般' => 'staff',
        'manager' => 'manager', 'マネージャー' => 'manager', 'マネージャ' => 'manager', '店長' => 'manager', '課長' => 'manager',
        'admin' => 'admin', '管理者' => 'admin', '統括' => 'admin', '本部' => 'admin',
    ];
    foreach ($map as $needle => $role) {
        if (mb_strpos($normalized, $needle) !== false) return $role;
    }
    return null;
}

try {
    $currentAdminId = requireFullAdminAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        sendErrorResponse('CSVファイルがアップロードされていません', 400);
    }

    $file = $_FILES['csv_file'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        sendErrorResponse('CSVファイルをアップロードしてください', 400);
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false) {
        sendErrorResponse('CSVファイルの読み込みに失敗しました', 500);
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    $enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS', 'CP932', 'ISO-8859-1'], true);
    if ($enc && $enc !== 'UTF-8') {
        $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw, -1, PREG_SPLIT_NO_EMPTY);

    // 区切り文字の自動判定（既存のCSV取込みと同じ扱い）
    $delimiter = ',';
    if (!empty($lines)) {
        $first = $lines[0];
        $commaCount = substr_count($first, ',');
        $semiCount = substr_count($first, ';');
        $tabCount = substr_count($first, "\t");
        if ($tabCount >= 1 && $tabCount >= $commaCount && $tabCount >= $semiCount) {
            $delimiter = "\t";
        } elseif ($semiCount >= 1 && $semiCount >= $commaCount && $semiCount >= $tabCount) {
            $delimiter = ';';
        }
    }

    $database = new Database();
    $db = $database->getConnection();
    orgEnsureUserColumns($db);

    // 1周目: 行を読んで検証だけ行う（上長は2周目でまとめて設定する）。
    // 権限を先に確定させないと、同じCSV内で新しくマネージャーにした人を
    // 上長に指定できないため。
    $rows = [];
    $errors = [];
    $skipped = 0;
    $lineNumber = 0;

    $findUser = $db->prepare('SELECT id, email, org_role, parent_user_id FROM users WHERE email = ? LIMIT 1');

    foreach ($lines as $line) {
        $lineNumber++;
        if ($lineNumber === 1) continue; // ヘッダー行

        $data = str_getcsv($line, $delimiter);
        if (empty(array_filter(array_map('trim', $data)))) continue;

        $email = trim($data[0] ?? '');
        $roleRaw = trim($data[4] ?? '');
        $parentEmail = trim($data[5] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "行 {$lineNumber}: 無効なメールアドレス - " . ($email !== '' ? $email : '(空)');
            $skipped++;
            continue;
        }

        $findUser->execute([$email]);
        $user = $findUser->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $errors[] = "行 {$lineNumber}: 登録されていないメールアドレスのためスキップ - {$email}";
            $skipped++;
            continue;
        }

        $role = $roleRaw === '' ? orgNormalizeRole($user['org_role'] ?? 'staff') : orgImportParseRole($roleRaw);
        if ($role === null) {
            $errors[] = "行 {$lineNumber}: 権限を判別できません（担当者／マネージャー／管理者）- {$roleRaw}";
            $skipped++;
            continue;
        }

        $rows[] = [
            'line' => $lineNumber,
            'user_id' => (int)$user['id'],
            'email' => $email,
            'role' => $role,
            'parent_email' => $parentEmail,
        ];
    }

    $roleUpdated = 0;
    $parentUpdated = 0;

    $db->beginTransaction();
    try {
        // 2周目: 権限を確定させる。
        $updateRole = $db->prepare('UPDATE users SET org_role = ? WHERE id = ?');
        foreach ($rows as $row) {
            $updateRole->execute([$row['role'], $row['user_id']]);
            if ($updateRole->rowCount() > 0) $roleUpdated++;
        }

        // 3周目: 上長を設定する。1件ずつ現在の階層に対して循環チェックを行う。
        $updateParent = $db->prepare('UPDATE users SET parent_user_id = ? WHERE id = ?');
        foreach ($rows as $row) {
            if ($row['parent_email'] === '') continue; // 空欄は「変更しない」

            // 「なし」「-」で明示的に上長を外せるようにする。
            if (in_array($row['parent_email'], ['なし', '無し', '-', 'ー', 'none'], true)) {
                $updateParent->execute([null, $row['user_id']]);
                if ($updateParent->rowCount() > 0) $parentUpdated++;
                continue;
            }

            if (!filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "行 {$row['line']}: 上長メールアドレスが不正 - {$row['parent_email']}";
                continue;
            }

            $findUser->execute([$row['parent_email']]);
            $parent = $findUser->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                $errors[] = "行 {$row['line']}: 上長が登録されていません - {$row['parent_email']}";
                continue;
            }
            if (!orgCanViewTeam($parent['org_role'] ?? 'staff')) {
                $errors[] = "行 {$row['line']}: 上長にはマネージャーまたは管理者のみ指定できます - {$row['parent_email']}";
                continue;
            }
            if (!orgIsAssignableParent($db, $row['user_id'], (int)$parent['id'])) {
                $errors[] = "行 {$row['line']}: 階層が循環するため設定できません - {$row['parent_email']}";
                continue;
            }

            $updateParent->execute([(int)$parent['id'], $row['user_id']]);
            if ($updateParent->rowCount() > 0) $parentUpdated++;
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log('User Org CSV Import Error: ' . $e->getMessage());
        sendErrorResponse('取り込み中にエラーが発生しました', 500);
    }

    logAdminChange(
        $db,
        $currentAdminId,
        $_SESSION['admin_email'] ?? '',
        'other',
        'user',
        null,
        "組織CSV取込み: 権限{$roleUpdated}件更新, 上長{$parentUpdated}件更新, {$skipped}件スキップ"
    );

    sendSuccessResponse([
        'processed' => count($rows),
        'role_updated' => $roleUpdated,
        'parent_updated' => $parentUpdated,
        'skipped' => $skipped,
        'errors' => $errors,
    ], count($rows) . '件を取り込みました');
} catch (Exception $e) {
    error_log('User Org CSV Import Error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

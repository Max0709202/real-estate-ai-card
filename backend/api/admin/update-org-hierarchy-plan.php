<?php
/**
 * 会社（宅建業免許番号）ごとに、階層分け機能の ON / OFF を切り替える。
 *
 * 階層分けは法人プランの機能のため、契約に合わせて運営側だけが切り替える。
 * OFF にしても統括・店長・配下の設定（org_role / parent_user_id）は消さない。
 * ON に戻せば、そのままの階層でそのまま使える。
 *
 * admin_email は「階層機能を使えるログインメール」の一覧（カンマ／改行区切りで複数可）。
 * 表示条件は AND なので、ON かつ この一覧に入っている方だけが階層機能を使える。
 * 一覧が空の会社は、ON にしても誰も使えない。
 *
 * POST { license_key: string, enabled: bool, license_text?: string, company_name?: string, admin_email?: string }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $currentAdminId = requireFullAdminAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $licenseKey = trim((string)($input['license_key'] ?? ''));
    if ($licenseKey === '') {
        sendErrorResponse('免許番号が指定されていません', 400);
    }
    if (!array_key_exists('enabled', $input)) {
        sendErrorResponse('ON / OFF が指定されていません', 400);
    }
    $enabled = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);

    // 利用できるログインメール（複数可）。1件ずつ形式を確認してから保存する。
    $allowedEmails = orgParseEmailList($input['admin_email'] ?? '');
    foreach ($allowedEmails as $allowedEmail) {
        if (!filter_var($allowedEmail, FILTER_VALIDATE_EMAIL)) {
            sendErrorResponse('メールアドレスの形式が正しくありません：' . $allowedEmail, 400);
        }
    }
    $adminEmail = implode(', ', $allowedEmails);

    $database = new Database();
    $db = $database->getConnection();

    $saved = orgSetHierarchyEnabled(
        $db,
        $licenseKey,
        trim((string)($input['license_text'] ?? '')),
        trim((string)($input['company_name'] ?? '')),
        $adminEmail,
        $enabled,
        (int)$currentAdminId
    );
    if (!$saved) {
        sendErrorResponse('設定の保存に失敗しました', 500);
    }

    logAdminChange(
        $db,
        $currentAdminId,
        $_SESSION['admin_email'] ?? '',
        'other',
        'user',
        null,
        '階層分け機能を' . ($enabled ? 'ON' : 'OFF') . ': ' . $licenseKey
            . ' / 利用可能メール=' . ($adminEmail !== '' ? $adminEmail : 'なし')
    );

    // ON なのにメール未登録だと誰も使えないため、その場で伝える。
    $message = $enabled
        ? ($adminEmail !== ''
            ? '階層分け機能をONにしました（利用可能：' . count($allowedEmails) . '件）'
            : 'ONにしましたが、利用できるログインメールが未登録です。このままでは誰も表示されません')
        : '階層分け機能をOFFにしました';

    sendSuccessResponse([
        'license_key' => $licenseKey,
        'admin_email' => $adminEmail,
        'allowed_count' => count($allowedEmails),
        'hierarchy_enabled' => $enabled,
    ], $message);
} catch (Exception $e) {
    error_log('Update Org Hierarchy Plan Error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

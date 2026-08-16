<?php
/**
 * 会社（宅建業免許番号）ごとに、階層分け機能の ON / OFF を切り替える。
 *
 * 階層分けは法人プランの機能のため、契約に合わせて運営側だけが切り替える。
 * OFF にしても統括・店長・配下の設定（org_role / parent_user_id）は消さない。
 * ON に戻せば、そのままの階層でそのまま使える。
 *
 * POST { license_key: string, enabled: bool, license_text?: string, company_name?: string }
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

    $database = new Database();
    $db = $database->getConnection();

    $saved = orgSetHierarchyEnabled(
        $db,
        $licenseKey,
        trim((string)($input['license_text'] ?? '')),
        trim((string)($input['company_name'] ?? '')),
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
    );

    sendSuccessResponse([
        'license_key' => $licenseKey,
        'hierarchy_enabled' => $enabled,
    ], $enabled ? '階層分け機能をONにしました' : '階層分け機能をOFFにしました');
} catch (Exception $e) {
    error_log('Update Org Hierarchy Plan Error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

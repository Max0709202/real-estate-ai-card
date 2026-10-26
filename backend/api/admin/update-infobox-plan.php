<?php
/**
 * 会社（宅建業免許番号）ごとに、情報BOX機能の ON / OFF を切り替える（運営専用）。
 *
 * 階層分け機能と同じく、運営の管理画面から会社単位で切り替える。
 * OFF にしてもデータ（BOX・書類・台帳）は消さない。ON に戻せばそのまま使える。
 *
 * POST { license_key: string, enabled: bool, license_text?: string, company_name?: string }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/infobox-helper.php';
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

    $saved = iboxSetEnabled(
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
        '情報BOX機能を' . ($enabled ? 'ON' : 'OFF') . ': ' . $licenseKey
    );

    sendSuccessResponse([
        'license_key' => $licenseKey,
        'infobox_enabled' => $enabled,
    ], $enabled ? '情報BOX機能をONにしました' : '情報BOX機能をOFFにしました');
} catch (Exception $e) {
    error_log('Update InfoBox Plan Error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

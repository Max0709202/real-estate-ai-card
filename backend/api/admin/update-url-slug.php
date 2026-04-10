<?php
/**
 * Update Company Slug API (ツール表示用企業URL)
 * Updates business_cards.company_slug only. url_slug (名刺URL) is never changed here.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    requireFullAdminAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $bcId = $input['business_card_id'] ?? null;
    $companySlug = $input['company_slug'] ?? $input['url_slug'] ?? '';

    if (empty($bcId)) {
        sendErrorResponse('ビジネスカードIDが必要です', 400);
    }

    $companySlug = trim($companySlug);
    if ($companySlug === '') {
        sendErrorResponse('企業URLスラッグを入力してください', 400);
    }

    if (!preg_match('/^[a-zA-Z0-9\-]+$/', $companySlug)) {
        sendErrorResponse('スラッグは英数字とハイフンのみ使用できます', 400);
    }

    $companySlug = strtolower($companySlug);

    $database = new Database();
    $db = $database->getConnection();

    $stmt = $db->prepare("
        SELECT bc.url_slug, bc.company_slug, bc.user_id, u.email, u.user_type, u.is_era_member
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.id = ?
    ");
    $stmt->execute([$bcId]);
    $bcInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bcInfo) {
        sendErrorResponse('ビジネスカードが見つかりません', 404);
    }

    $userType = $bcInfo['user_type'] ?? 'new';
    $isEraMember = !empty($bcInfo['is_era_member']);
    if ($userType === 'new' && !$isEraMember) {
        sendErrorResponse('新規ユーザーの企業URLは編集できません。既存・ERA会員のみ編集可能です。', 403);
    }

    $oldCompanySlug = $bcInfo['company_slug'] ?? '';

    $stmt = $db->prepare("UPDATE business_cards SET company_slug = ? WHERE id = ?");
    $stmt->execute([$companySlug, $bcId]);

    $adminId = $_SESSION['admin_id'];
    $adminEmail = $_SESSION['admin_email'] ?? '';
    logAdminChange(
        $db,
        $adminId,
        $adminEmail,
        'other',
        'business_card',
        $bcId,
        "企業URL(company_slug)変更: " . ($oldCompanySlug ?: '(空)') . " → {$companySlug} (ユーザー: {$bcInfo['email']})"
    );

    sendSuccessResponse([
        'business_card_id' => $bcId,
        'company_slug' => $companySlug,
        'old_company_slug' => $oldCompanySlug,
        'url_slug' => $bcInfo['url_slug']
    ], '企業URLを更新しました');

} catch (Exception $e) {
    error_log("Update Company Slug Error: " . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

<?php
/**
 * 配下担当者の顧客一覧をCSVで出力する（マイページ「組織・配下顧客」用）。閲覧専用。
 *
 * GET ?member_id=  … 指定した配下担当者だけに絞る（省略時は配下全員）
 *
 * 文字コードは既存のCSV出力（backend/api/admin/export-csv.php）に合わせ、
 * Excelでそのまま開けるBOM付きUTF-8とする。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/customer-invitation-helper.php';
require_once __DIR__ . '/../../includes/org-hierarchy-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

startSessionIfNotStarted();

try {
    $userId = (int) requireAuth();

    $database = new Database();
    $db = $database->getConnection();

    $viewer = orgLoadViewer($db, $userId);
    if (!orgCanViewTeam($viewer['org_role'])) {
        header('Content-Type: application/json; charset=UTF-8');
        sendErrorResponse('配下の顧客を閲覧する権限がありません', 403);
    }

    ensureChatLeadContactTable($db);
    customerInviteEnsureTable($db);

    $descendants = orgDescendants($db, $userId);
    $memberIds = array_map(function ($item) { return (int)$item['id']; }, $descendants);

    $requestedMemberId = isset($_GET['member_id']) ? (int) $_GET['member_id'] : 0;
    $onlyUserId = $requestedMemberId > 0 ? $requestedMemberId : null;
    if ($onlyUserId !== null && !in_array($onlyUserId, $memberIds, true)) {
        header('Content-Type: application/json; charset=UTF-8');
        sendErrorResponse('指定された担当者は配下ではありません', 403);
    }

    $customers = empty($memberIds) ? [] : orgFetchCustomers($db, $memberIds, $onlyUserId, 2000);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="team_customers_' . date('YmdHis') . '.csv"');

    $output = fopen('php://output', 'w');
    // BOM付きUTF-8（Excel用）
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        '担当者名', '担当者メールアドレス', '権限',
        '顧客名', '顧客電話番号', '顧客メールアドレス',
        'ご案内状況', 'メッセージ数', '未読数', '最終やり取り日時', '登録日時'
    ]);

    $invitationLabels = [
        'sent' => 'ご案内メール送信済み',
        'opened' => 'ご案内済み（閲覧あり）',
        'registered' => 'ご登録済み',
    ];

    foreach ($customers as $customer) {
        fputcsv($output, [
            $customer['member_name'],
            $customer['member_email'],
            $customer['member_org_role_label'],
            $customer['customer_name'],
            $customer['customer_phone'],
            $customer['customer_email'],
            $invitationLabels[$customer['invitation_status']] ?? '',
            $customer['message_count'],
            $customer['unread_count'],
            $customer['last_message_at'] ?? '',
            $customer['created_at'] ?? '',
        ]);
    }

    fclose($output);
    exit();
} catch (Exception $e) {
    error_log('org customers csv error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'CSV出力に失敗しました']);
    exit();
}

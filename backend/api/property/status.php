<?php
/**
 * 物件選定: ステータス変更（§5）。
 * POST(JSON) { property_id, status, session_id?, visitor_id? }
 *  - 顧客（visitor_id あり）: 顧客のみ選択可ステータス（内見希望/検討中/見送り/申込検討）
 *  - 担当（ログイン）: エージェントのみ選択可ステータス（仲介可/ご紹介不可）
 *  - status='' でクリア
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$propertyId = isset($input['property_id']) ? (int)$input['property_id'] : 0;
$status = trim((string)($input['status'] ?? ''));
$visitorId = trim($input['visitor_id'] ?? '');
// 見送り理由（複数選択可）。配列 pass_reasons またはカンマ区切り pass_reason のどちらでも受け付ける。
$passReasonsInput = $input['pass_reasons'] ?? ($input['pass_reason'] ?? []);
if (is_string($passReasonsInput)) $passReasonsInput = explode(',', $passReasonsInput);
if (!is_array($passReasonsInput)) $passReasonsInput = [];
$passReasonText = trim((string)($input['pass_reason_text'] ?? ''));
if ($propertyId <= 0) sendErrorResponse('property_id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendErrorResponse('物件が見つかりません', 404);

    $defs = propertyStatusDefs();

    // ロール判定 & 所有検証 & 選択可否
    if ($visitorId !== '') {
        propertyVerifyCustomerSession($db, $row['session_id'], $visitorId);
        $role = 'customer';
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentProperty($db, $propertyId, $userId);
        $role = 'agent';
    }

    if ($status !== '') {
        if (!isset($defs[$status])) sendErrorResponse('不正なステータスです', 400);
        if ($defs[$status]['role'] !== $role) {
            sendErrorResponse('このステータスは選択できません', 403);
        }
    }

    // 見送り(passed)のときのみ理由を保存する。それ以外のステータス（解除含む）では理由をクリア。
    $reasonCsv = null;   // 有効な理由コードのカンマ区切り
    $reasonText = null;  // 「その他」の自由入力
    if ($status === 'passed') {
        $reasonDefs = propertyPassReasonDefs();
        $codes = [];
        foreach ($passReasonsInput as $c) {
            $c = trim((string)$c);
            if ($c !== '' && isset($reasonDefs[$c]) && !in_array($c, $codes, true)) $codes[] = $c;
        }
        if (!empty($codes)) $reasonCsv = implode(',', $codes);
        // 自由入力は「その他」選択時のみ保存（最大500文字）。
        if (in_array('other', $codes, true) && $passReasonText !== '') {
            $reasonText = mb_substr($passReasonText, 0, 500);
        }
    }

    // 理由が変わったら AI 所見は一旦クリア（担当が閲覧したときに pass-reason-ai.php で再生成）。
    $db->prepare("UPDATE properties SET status = ?, pass_reason = ?, pass_reason_text = ?, pass_reason_ai = NULL WHERE id = ?")
       ->execute([$status === '' ? null : $status, $reasonCsv, $reasonText, $propertyId]);

    // 顧客の物件選定操作 → 担当営業へメール通知（営業自身の操作は対象外）。
    if ($role === 'customer' && !empty($row['session_id'])) {
        notifyEnqueue($db, (string)$row['session_id'], 'property');
    }

    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    sendSuccessResponse(['property' => propertySerialize($db, $row, $role === 'agent', false)], 'ステータスを更新しました');
} catch (Exception $e) {
    error_log('property status error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

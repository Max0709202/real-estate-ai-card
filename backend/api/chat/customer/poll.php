<?php
/**
 * 顧客側ポーリング。担当からの新着（role='agent'）を取得する。
 * GET ?session_id=&visitor_id=&since_id=
 * 取得した担当発言は自動的に既読化する。
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../../includes/customer-notification-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

$sessionId = trim($_GET['session_id'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
// 既読化は「顧客が担当連絡タブを実際に開いたとき」だけ行う（背景ポーリングでは既読にしない）。
$markRead = !empty($_GET['mark_read']);
// history=1: 担当連絡チャネルの全履歴（顧客の発言＋担当の発言）を返す。
// 担当連絡タブを開いた時に、顧客自身の送信メッセージも含めて完全な会話を表示するため。
$history = !empty($_GET['history']);

if ($sessionId === '' || !preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId)) {
    sendErrorResponse('session_id is required', 400);
}
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // セッション存在検証（任意で visitor_id 突合：登録済みなら一致を要求）
    $stmt = $db->prepare("SELECT id, visitor_identifier FROM chat_sessions WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    if (!empty($session['visitor_identifier']) && $visitorId !== '' && $session['visitor_identifier'] !== $visitorId) {
        sendErrorResponse('セッションを確認できません', 403);
    }

    if ($history) {
        // 担当連絡チャネルの全メッセージ（顧客=user / 担当=agent の両方）を時系列で取得。
        // 取り消し済みも含めて返し、クライアントで「取り消し」表示にする。
        $stmt = $db->prepare("
            SELECT id, role, message, created_at, edited_at, deleted_at
            FROM chat_messages
            WHERE session_id = ? AND channel = 'contact' AND role IN ('user','agent')
            ORDER BY id ASC LIMIT 500
        ");
        $stmt->execute([$sessionId]);
    } else {
        // 担当の新着発言を取得（担当連絡チャネルの人間担当発言のみ）
        $stmt = $db->prepare("
            SELECT id, role, message, created_at, edited_at, deleted_at
            FROM chat_messages
            WHERE session_id = ? AND role = 'agent' AND channel = 'contact' AND id > ?
            ORDER BY id ASC LIMIT 200
        ");
        $stmt->execute([$sessionId, $sinceId]);
    }
    $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($newMessages) {
        $attach = agentMsgLoadAttachments($db, array_column($newMessages, 'id'));
        foreach ($newMessages as &$m) {
            $m['attachments'] = $attach[(int)$m['id']] ?? [];
            // 編集/取り消し状態を付与し、取り消し済みは本文・添付をマスク（フラグで描画）。
            $m = agentMsgApplyEditState($m, true);
        }
        unset($m);
    }

    // 顧客が担当連絡タブを開いたとき（mark_read）のみ既読化する。
    if ($markRead) {
        agentMsgMarkRead($db, $sessionId, 'agent');
        // 顧客向けメール通知（担当連絡）の未読解除（送信前ならキャンセル）。
        customerNotifyMarkRead($db, $sessionId, 'contact');
    }

    // 現時点の未読件数（担当連絡チャネルの担当発言で read_at IS NULL のもの）。
    // バッジはこの値（=本当の未読数）を表示する。
    $stmt = $db->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ? AND role = 'agent' AND channel = 'contact' AND read_at IS NULL");
    $stmt->execute([$sessionId]);
    $unread = (int)$stmt->fetchColumn();

    // 顧客自身の発言（担当連絡）のうち、担当者が既読にした最大ID。
    // 顧客側UIで「既読」を出すために返す（id <= last_read_user_id の自分の発言は既読扱い）。
    $stmt = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE session_id = ? AND role = 'user' AND channel = 'contact' AND read_at IS NOT NULL");
    $stmt->execute([$sessionId]);
    $lastReadUserId = (int)$stmt->fetchColumn();

    sendSuccessResponse([
        'messages' => $newMessages,
        'unread_count' => $unread,
        'last_read_user_id' => $lastReadUserId,
    ], 'OK');
} catch (Exception $e) {
    error_log('customer poll error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

<?php
/**
 * Address GIS data diagnostic for the my page.
 *
 * Root-cause tool for "確認できません" answers (用途地域は出るのに防火地域・洪水浸水
 * 想定・土砂災害警戒区域が出ない等)。指定住所について以下を返す:
 *   ① 住所→緯度経度の変換結果（geocode）
 *   ②〜⑤ 各取得APIのレスポンス要約（用途地域 XKT002 / 防火 XKT014 /
 *        洪水浸水想定 XKT026 / 土砂災害警戒区域 XKT029 ほか）
 *   ⑥ APIエラー時のエラー内容（http_status / error）
 *   ⑦ 正常終了だがデータ無しの場合のレスポンス内容（raw_sample）
 * 各レイヤーの status により「区域外(out_of_area)」「該当なし(not_designated)」と
 * 「取得失敗(http_error)」「ジオコーディング失敗(geocode_failed)」を区別する。
 *
 * GET/POST  address=<住所>  [codes=XKT002,XKT014,...]
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        sendErrorResponse('Method not allowed', 405);
    }
    requireAuth();

    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) $input = [];
    }
    $address = trim((string)($input['address'] ?? ($_GET['address'] ?? '')));
    if ($address === '') {
        sendErrorResponse('住所を入力してください', 400);
    }
    if (mb_strlen($address) > 180) {
        sendErrorResponse('住所が長すぎます', 400);
    }
    if (!defined('REINFOLIB_API_KEY') || REINFOLIB_API_KEY === '') {
        sendErrorResponse('不動産情報ライブラリAPIキーが未設定です', 500);
    }

    $codesRaw = (string)($input['codes'] ?? ($_GET['codes'] ?? ''));
    $codes = $codesRaw !== '' ? array_filter(array_map('trim', explode(',', $codesRaw))) : null;

    $db = (new Database())->getConnection();
    $report = chatAddressDataDiagnostic($db, $address, $codes);

    sendSuccessResponse($report, '診断を実行しました');
} catch (Exception $e) {
    error_log('Data diagnostic API error: ' . $e->getMessage());
    sendErrorResponse(ENVIRONMENT === 'development' ? $e->getMessage() : 'サーバーエラーが発生しました', 500);
}

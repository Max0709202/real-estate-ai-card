<?php
/**
 * 物件選定: マップの初期表示（マップ情報表示依頼 2026.9.3 §1-§3・§11）。
 * GET ?id=&session_id=&visitor_id=  /  GET ?id=&view_token=  /  担当はログインセッション
 *
 * 返すのは「地図を出すために必要な最小限」だけ。
 *  - 地図表示用のAPIキー（Maps JavaScript API 用）
 *  - 現在見ている物件のピン（物件名・価格・間取り・面積）
 *  - 同じお客様が「検討中」にしている物件のピン
 *  - 周辺情報の10カテゴリー定義
 *
 * §11 のとおり、この時点では Google Places API による周辺施設検索は一切行わない。
 * 周辺情報はボタンが押されたときに map-facilities.php が取得する。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/property-view-helper.php';
require_once __DIR__ . '/../../includes/property-map-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

/** 1リクエストで新規にジオコーディングする上限（初回表示が長くなりすぎないように）。 */
const PROPERTY_MAP_GEOCODE_BUDGET = 15;

$propertyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$visitorId = trim($_GET['visitor_id'] ?? '');
$viewToken = trim($_GET['view_token'] ?? '');
if ($propertyId <= 0) sendErrorResponse('id is required', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendErrorResponse('物件が見つかりません', 404);

    // 認可は物件詳細（get.php）と同じ。顧客は自分のセッションの物件、担当は自分の名刺の物件。
    if ($viewToken !== '') {
        if (propertyViewTokenSession($db, $viewToken) !== (string)$row['session_id']) {
            sendErrorResponse('アクセス権がありません', 403);
        }
    } elseif ($visitorId !== '') {
        propertyVerifyCustomerSession($db, (string)$row['session_id'], $visitorId);
    } else {
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentProperty($db, $propertyId, $userId);
    }

    // 現在見ている物件（赤い目立つピン）。緯度・経度が取れない場合は地図を出せない。
    $geo = propertyMapGeoOf($db, $row, true);
    $current = $geo ? propertyMapPin($row, $geo, true) : null;

    // §3「検討中」の物件をすべて同じ地図に表示する。
    // ステータス未設定は一覧の並び順と同じく「検討中」として扱う（見送り・契約等は出さない）。
    $considering = [];
    if ($current) {
        $stmt = $db->prepare(
            "SELECT * FROM properties
             WHERE session_id = ? AND id <> ?
               AND COALESCE(NULLIF(status, ''), 'considering') = 'considering'
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([(string)$row['session_id'], $propertyId]);
        $budget = PROPERTY_MAP_GEOCODE_BUDGET;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $other) {
            $hasStored = ($other['lat'] ?? null) !== null && ($other['lng'] ?? null) !== null;
            $allowFetch = $hasStored || $budget > 0;
            if (!$hasStored && $allowFetch) $budget--;
            $g = propertyMapGeoOf($db, $other, $allowFetch);
            if (!$g) continue;   // 緯度・経度まで取得できている物件だけを表示する（§3）
            $considering[] = propertyMapPin($other, $g, false);
        }
    }

    $categories = [];
    foreach (propertyMapCategoryDefs() as $key => $def) {
        $categories[] = [
            'key'    => $key,
            'label'  => $def['label'],
            'source' => $def['source'],
            'render' => $def['render'],
        ];
    }

    sendSuccessResponse([
        // Maps JavaScript API のキーだけをブラウザへ渡す。Places はサーバー側でのみ呼ぶ。
        'maps_api_key' => defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '',
        'radius'       => propertyMapRadius(),
        'property'     => $current,
        'considering'  => $considering,
        'categories'   => $categories,
        // 緯度・経度が取れず地図を出せないときの案内（住所未登録・ジオコーディング失敗）。
        'message'      => $current ? '' : 'この物件の所在地から地図の位置を特定できませんでした。所在地をご確認ください。',
    ], 'OK');
} catch (Exception $e) {
    error_log('property map error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

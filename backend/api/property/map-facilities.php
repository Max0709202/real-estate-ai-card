<?php
/**
 * 物件選定: マップの周辺情報（マップ情報表示依頼 2026.9.3 §4-§10）。
 * GET ?id=&category=&session_id=&visitor_id=  /  GET ?id=&category=&view_token=
 *
 * 周辺情報ボタンが押された時点で初めて呼ばれる（§11）。
 * Places のカテゴリーは §9 のグループ単位でまとめて取得し、同じグループの結果を
 * まとめて返すので、後から同じグループの別ボタンが押されてもAPIを呼び直さない（§10）。
 *
 * 返す施設情報は「施設名」「位置」「Googleマップで見る」だけ（§5）。
 * 徒歩時間・距離・公式サイトURLは取得も返却もせず、Routes API は使用しない（§12）。
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

$propertyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$category = trim($_GET['category'] ?? '');
$visitorId = trim($_GET['visitor_id'] ?? '');
$viewToken = trim($_GET['view_token'] ?? '');
if ($propertyId <= 0) sendErrorResponse('id is required', 400);

$defs = propertyMapCategoryDefs();
if (!isset($defs[$category])) sendErrorResponse('category is invalid', 400);

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    $stmt = $db->prepare("SELECT * FROM properties WHERE id = ? LIMIT 1");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) sendErrorResponse('物件が見つかりません', 404);

    // 認可は物件詳細（get.php）と同じ。
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

    // 保存済みの座標だけを使う（マップ表示時に map.php が取得済み）。
    $geo = propertyMapGeoOf($db, $row, true);
    if (!$geo) sendErrorResponse('この物件の位置を特定できないため、周辺情報を表示できません', 400);

    $lat = (float)$geo['lat'];
    $lng = (float)$geo['lng'];
    $def = $defs[$category];
    $results = [];

    if ($def['source'] === 'places') {
        if (propertyMapPlacesKey() === '') {
            sendErrorResponse('周辺施設の検索が設定されていません（APIキー未設定）', 503);
        }
        // §9 グループ単位のまとめ取得。押されたカテゴリー以外の結果も一緒に返し、
        // 画面側で保持してもらう（後から押されてもAPIを呼び直さない・§10）。
        $group = propertyMapFetchPlacesGroup($db, $lat, $lng, (string)$def['group'], $category);
        foreach ($group as $key => $bucket) {
            $results[$key] = [
                'render'     => $defs[$key]['render'],
                'items'      => $bucket['items'],
                'layers'     => [],
                'sufficient' => $bucket['sufficient'] ? 1 : 0,
                'notice'     => '',
            ];
        }
    } elseif ($category === 'hazard') {
        // §8① ピンではなく該当エリアを色分けして表示する。
        $results['hazard'] = [
            'render'     => 'polygon',
            'items'      => [],
            'layers'     => propertyMapHazardLayers($db, $lat, $lng),
            'sufficient' => 1,
            'notice'     => '出典: 国土交通省「不動産情報ライブラリ」。表示は概略です。詳細は各自治体のハザードマップをご確認ください。',
        ];
    } elseif ($category === 'school_district') {
        // §8⑥ 指定小学校・指定中学校の学区。
        $layers = propertyMapSchoolDistricts($db, $lat, $lng);
        $found = false;
        foreach ($layers as $l) { if (!empty($l['polygons'])) { $found = true; break; } }
        $results['school_district'] = [
            'render'     => 'polygon',
            'items'      => [],
            'layers'     => $layers,
            'sufficient' => 1,
            'notice'     => $found
                ? '学区は変更される場合があります。最新情報は自治体へご確認ください。'
                : '公開データではこの地点の学区を確認できませんでした。学区は変更される場合があります。最新情報は自治体へご確認ください。',
        ];
    } elseif ($category === 'shelter') {
        // §8⑨ 指定緊急避難場所。
        $results['shelter'] = [
            'render'     => 'marker',
            'items'      => propertyMapShelters($db, $lat, $lng),
            'layers'     => [],
            'sufficient' => 1,
            'notice'     => '出典: 国土交通省「不動産情報ライブラリ」（指定緊急避難場所）。',
        ];
    } else {
        sendErrorResponse('category is invalid', 400);
    }

    sendSuccessResponse([
        'category' => $category,
        'radius'   => propertyMapRadius(),
        'results'  => $results,
    ], 'OK');
} catch (Exception $e) {
    error_log('property map-facilities error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

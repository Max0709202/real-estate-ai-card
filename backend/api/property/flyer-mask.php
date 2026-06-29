<?php
/**
 * 物件選定: 販売図面の売主情報マスク（担当のみ）。
 * 売主仲介会社情報（会社名・住所・電話・QR・「物件確認はこちら」等）を顧客共有時に自動非表示にする。
 *
 * GET  ?image_id=            → { preview_url, width, height, regions, mask_status, masked_url }
 * POST { image_id, regions } → マスクを適用し顧客用マスク済PDFを生成。{ mask_status:'masked', ... }
 *   regions: [{x,y,w,h}]（正規化座標 0..1, 左上原点）。空配列なら下端帯を既定採用。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit(); }

startSessionIfNotStarted();
$userId = requireAuth();

try {
    $db = (new Database())->getConnection();
    propertyEnsureTables($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $imageId = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;
        if ($imageId <= 0) sendErrorResponse('image_id is required', 400);
        $img = propertyFlyerVerifyAgentImage($db, $imageId, $userId);

        // プレビュー未生成（旧データ等）の場合はその場で生成する
        if (empty($img['preview_path'])) {
            $absOriginal = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($img['stored_path'], '/');
            $isPdf = (strtolower((string)$img['mime_type']) === 'application/pdf')
                || strtolower(pathinfo($absOriginal, PATHINFO_EXTENSION)) === 'pdf';
            $preview = propertyFlyerMakePreview($absOriginal, $isPdf, (int)$img['business_card_id'], (int)$img['property_id']);
            if ($preview) {
                $regions = propertyFlyerBottomBandRegions();
                if (!empty($img['mask_regions'])) {
                    $dec = json_decode($img['mask_regions'], true);
                    if (is_array($dec) && $dec) $regions = $dec;
                }
                $db->prepare("UPDATE property_images SET preview_path = ?, mask_regions = ?, mask_status = IF(mask_status='masked','masked','pending') WHERE id = ?")
                   ->execute([$preview['rel'], json_encode($regions, JSON_UNESCAPED_UNICODE), $imageId]);
                $img['preview_path'] = $preview['rel'];
                $img['mask_regions'] = json_encode($regions, JSON_UNESCAPED_UNICODE);
            }
        }

        $size = null;
        if (!empty($img['preview_path'])) {
            $abs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($img['preview_path'], '/');
            $gi = @getimagesize($abs);
            if ($gi) $size = ['width' => (int)$gi[0], 'height' => (int)$gi[1]];
        }
        // 編集対象はページ1（index 0）。マスク領域はページ別に保持。
        $byPage = propertyMaskRegionsByPage($img['mask_regions'] ?? null);
        $regions = $byPage[0] ?? [];

        sendSuccessResponse([
            'image_id' => $imageId,
            'preview_url' => !empty($img['preview_path']) ? (API_BASE_URL . '/property/image.php?id=' . $imageId . '&variant=preview') : null,
            'width' => $size['width'] ?? null,
            'height' => $size['height'] ?? null,
            'regions' => $regions,
            'mask_status' => $img['mask_status'] ?? 'none',
            'masked_url' => !empty($img['masked_path']) ? (API_BASE_URL . '/property/image.php?id=' . $imageId . '&variant=masked') : null,
        ], 'OK');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;
        $imageId = isset($input['image_id']) ? (int)$input['image_id'] : 0;
        if ($imageId <= 0) sendErrorResponse('image_id is required', 400);
        $img = propertyFlyerVerifyAgentImage($db, $imageId, $userId);

        // 編集されたページ1（index 0）の範囲を正規化
        $regions = [];
        foreach (($input['regions'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $reg = propertyClampRegion($r['x'] ?? 0, $r['y'] ?? 0, $r['w'] ?? 0, $r['h'] ?? 0);
            if ($reg) $regions[] = $reg;
        }

        // ページ別マスクを更新（ページ1のみ手動上書き。他ページはアップロード時の自動検出を維持）
        $byPage = propertyMaskRegionsByPage($img['mask_regions'] ?? null);
        $byPage[0] = $regions;

        // 元PDF/画像から全ページを再ラスタライズし、顧客用マスク済PDFを再生成
        $absOriginal = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($img['stored_path'], '/');
        $isPdf = (strtolower((string)$img['mime_type']) === 'application/pdf')
            || strtolower(pathinfo($absOriginal, PATHINFO_EXTENSION)) === 'pdf';
        $pg = propertyFlyerPageImages($absOriginal, $isPdf, 6);
        $maskedRel = $pg['pages'] ? propertyFlyerBuildCustomerPdf($pg['pages'], $byPage, (int)$img['business_card_id'], (int)$img['property_id']) : null;
        foreach ($pg['tmp'] as $f) { if (is_file($f)) @unlink($f); }
        if (!$maskedRel) sendErrorResponse('マスク処理に失敗しました', 500);

        // 旧マスクPDFを削除
        if (!empty($img['masked_path'])) {
            $oldAbs = rtrim(UPLOAD_DIR, '/') . '/' . ltrim($img['masked_path'], '/');
            if (is_file($oldAbs)) @unlink($oldAbs);
        }

        // 担当が編集・確認を完了 → 顧客に公開（customer_visible=1）
        $db->prepare("UPDATE property_images SET masked_path = ?, mask_regions = ?, mask_status = 'masked', customer_visible = 1 WHERE id = ?")
           ->execute([$maskedRel, json_encode($byPage, JSON_UNESCAPED_UNICODE), $imageId]);

        sendSuccessResponse([
            'image_id' => $imageId,
            'mask_status' => 'masked',
            'customer_visible' => 1,
            'regions' => $regions,
            'masked_url' => API_BASE_URL . '/property/image.php?id=' . $imageId . '&variant=masked',
        ], '顧客共有用のマスク済販売図面を保存し、顧客に公開しました');
    }

    sendErrorResponse('Method not allowed', 405);
} catch (Exception $e) {
    error_log('property flyer-mask error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

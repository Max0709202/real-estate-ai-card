<?php
/**
 * 内見日程調整: 売主（仲介）会社宛てメールの開封記録（1x1の画像を返す）。
 * GET ?t=<売主用トークン>&m=<メールコード>
 *
 * 画像がブロックされる環境もあるため、これが取れないことを「未開封」とは断定しない。
 * 開封（mail_open）・回答ページへのアクセス（page_open）・回答完了（replied）は別々に記録する。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/viewing-helper.php';

$token = trim((string)($_GET['t'] ?? ''));
$code = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['m'] ?? ''));

try {
    $db = (new Database())->getConnection();
    $ref = viewingTokenLookup($db, $token);
    if ($ref !== null && $ref['audience'] === 'seller') {
        viewingLogEvent($db, $ref['viewing_id'], 'mail_open', [
            'actor' => 'seller', 'mail_code' => mb_substr($code, 0, 8) ?: null,
        ]);
    }
} catch (Throwable $e) {
    error_log('viewing-open error: ' . $e->getMessage());
}

// 記録の成否にかかわらず、常に透明な1x1 GIFを返す。
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

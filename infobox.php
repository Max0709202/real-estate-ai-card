<?php
/**
 * 情報BOX 画面（仕様 画面1〜21）。
 *
 *   infobox.php            … 名刺所有者の情報BOX一覧・新規作成・取引台帳の一覧、アカウントに紐づいた参加中のBOX
 *   infobox.php?box=<ID>   … 取引の情報BOX（取引概要／取引関係者／書類フォルダー／チャット）
 *
 * 表示内容はすべて API（backend/api/infobox/）から、閲覧者が見られる範囲だけを取得して描画する。
 * このページ自体は、閲覧者の確認と画面の枠だけを出力する。
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/includes/functions.php';
require_once __DIR__ . '/backend/includes/infobox-helper.php';

startSessionIfNotStarted();
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$boxId = (int)($_GET['box'] ?? 0);
$error = '';
$mode = $boxId > 0 ? 'box' : 'list';
$whoLabel = '';

try {
    $db = (new Database())->getConnection();
    iboxEnsureTables($db);
    if ($mode === 'box') {
        $viewer = iboxResolveViewer($db, $boxId);
        if (isset($viewer['error'])) {
            $error = $viewer['error'];
        } else {
            $whoLabel = iboxParticipantRoleLabel($viewer['box'], $viewer['p']) . '｜' . iboxParticipantName($viewer['p']);
        }
    } else {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            header('Location: login.php');
            exit;
        }
        if (!iboxEnabledForUser($db, $userId) && !iboxJoinedBoxes($db, $userId)) {
            $error = '情報BOXは現在ご利用いただけません。ご利用をご希望の場合は運営までお問い合わせください。';
        } else {
            $card = iboxOwnerCard($db, $userId);
            $whoLabel = '名刺所有者｜' . (string)($card['name'] ?? '');
        }
    }
} catch (Throwable $e) {
    error_log('infobox.php error: ' . $e->getMessage());
    $error = 'ページを表示できませんでした。時間をおいて再度お試しください。';
}
$isOwnerSession = !empty($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
<title>情報BOX｜不動産AI名刺</title>
<link rel="stylesheet" href="assets/css/infobox.css?v=<?php echo filemtime(__DIR__ . '/assets/css/infobox.css'); ?>">
</head>
<body class="ib-body">
<header class="ib-header">
  <a class="ib-brand" href="<?php echo $isOwnerSession ? 'infobox.php' : '#'; ?>"><span class="ib-brand-mark">AI</span><span class="ib-brand-name">不動産AI名刺 <small>情報BOX</small></span></a>
  <span class="ib-who">
    <?php echo $esc($whoLabel); ?>
    <?php if ($isOwnerSession): ?><a href="edit.php">マイページへ</a><?php endif; ?>
  </span>
</header>

<?php if ($error !== ''): ?>
<main class="ib-main">
  <div class="ib-card">
    <div class="ib-error"><?php echo $esc($error); ?></div>
    <?php if ($isOwnerSession): ?><div class="ib-actions is-left"><a class="ib-btn" href="edit.php">マイページへ戻る</a></div><?php endif; ?>
  </div>
</main>
<?php else: ?>
<?php if ($mode === 'box'): ?>
<nav class="ib-tabs" id="ib-tabs">
  <button type="button" class="ib-tab" data-tab="overview">取引概要</button>
  <button type="button" class="ib-tab" data-tab="participants">取引関係者</button>
  <button type="button" class="ib-tab" data-tab="folders">書類フォルダー</button>
  <button type="button" class="ib-tab" data-tab="chat">チャット<span class="ib-dot ib-hidden" id="ib-chat-dot"></span></button>
</nav>
<?php endif; ?>
<main class="ib-main" id="ib-app" data-mode="<?php echo $esc($mode); ?>" data-box-id="<?php echo (int)$boxId; ?>">
  <div class="ib-empty">読み込み中…</div>
</main>
<script src="assets/js/infobox.js?v=<?php echo filemtime(__DIR__ . '/assets/js/infobox.js'); ?>"></script>
<?php endif; ?>
</body>
</html>

<?php
/**
 * 情報BOX 招待された関係者のアクセス（仕様 画面8）。
 * 利用通知メールの受信者専用URL（?t=<トークン>）から開き、登録済みのメールアドレスと電話番号を入力する。
 *
 * 認証前は物件・参加者・フォルダーを表示しない。
 * URLの期限切れ（7日）の場合は、同じ画面から新しいURLを登録メールアドレスへ再発行できる。
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();
$token = preg_match('/^[a-f0-9]{64}$/', (string)($_GET['t'] ?? '')) ? (string)$_GET['t'] : '';
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>情報BOXにアクセス｜不動産AI名刺</title>
<link rel="stylesheet" href="assets/css/infobox.css?v=<?php echo filemtime(__DIR__ . '/assets/css/infobox.css'); ?>">
</head>
<body class="ib-body">
<header class="ib-header">
  <span class="ib-brand"><span class="ib-brand-mark">AI</span><span class="ib-brand-name">不動産AI名刺 <small>情報BOX</small></span></span>
  <span class="ib-who">招待を受けた関係者</span>
</header>

<main class="ib-access">
  <span class="ib-chip">お取引の書類共有</span>
  <h1>情報BOXにアクセス</h1>
  <p class="ib-page-lead">ご案内メールに記載の、登録メールアドレスと<br>登録電話番号を入力してください。</p>

  <?php if ($token === ''): ?>
  <div class="ib-card"><div class="ib-error">このURLは無効です。ご案内メールのURLをもう一度お確かめください。</div></div>
  <?php else: ?>
  <form class="ib-card ib-form" id="ib-access-form" autocomplete="on">
    <div class="ib-field">
      <label for="ib-email">メールアドレス</label>
      <input type="email" id="ib-email" name="email" required autocomplete="email" inputmode="email">
    </div>
    <div class="ib-field">
      <label for="ib-phone">電話番号</label>
      <input type="tel" id="ib-phone" name="phone" required autocomplete="tel" inputmode="tel" placeholder="090-0000-0000">
    </div>
    <div id="ib-access-msg" class="ib-hidden"></div>
    <div>
      <button type="submit" class="ib-btn ib-btn-primary ib-btn-lg" id="ib-access-submit">情報BOXを開く</button>
      <button type="button" class="ib-btn ib-hidden" id="ib-access-reissue">新しいURLを受け取る</button>
    </div>
    <p class="ib-note">ご案内メールを受け取った情報でご入力ください。</p>
  </form>
  <?php endif; ?>
  <p class="ib-note">アクセスできない場合は、ご案内担当者へお問い合わせください。</p>
</main>

<?php if ($token !== ''): ?>
<script>
(function () {
  var token = <?php echo json_encode($token); ?>;
  var form = document.getElementById('ib-access-form');
  var msg = document.getElementById('ib-access-msg');
  var submit = document.getElementById('ib-access-submit');
  var reissue = document.getElementById('ib-access-reissue');

  function show(text, isError) {
    msg.className = isError ? 'ib-error' : 'ib-notice';
    msg.textContent = text;
  }
  function post(action) {
    return fetch('backend/api/infobox/access.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-IBOX': '1' },
      body: JSON.stringify({ action: action, token: token, email: form.email.value, phone: form.phone.value })
    }).then(function (r) { return r.json(); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submit.disabled = true;
    post('verify').then(function (res) {
      if (res.success) { window.location.href = res.data.redirect; return; }
      submit.disabled = false;
      show(res.message || '認証できませんでした。', true);
      reissue.classList.toggle('ib-hidden', res.reason !== 'expired');
    }).catch(function () {
      submit.disabled = false;
      show('通信に失敗しました。時間をおいて再度お試しください。', true);
    });
  });

  reissue.addEventListener('click', function () {
    reissue.disabled = true;
    post('reissue').then(function (res) {
      reissue.disabled = false;
      show(res.message || (res.success ? '送信しました。' : '送信できませんでした。'), !res.success);
    }).catch(function () {
      reissue.disabled = false;
      show('通信に失敗しました。', true);
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>

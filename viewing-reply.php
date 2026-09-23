<?php
/**
 * 内見日時のご回答（売主（仲介）会社向け・ログイン不要）。
 * メール M03 / M08 のURL（?t=<トークン>）から開く。
 *
 * 画面の構成（仕様 §6 ／ 2026/9/20 追加ご依頼）:
 *   ① 物件（物件名＋金額）と、担当エージェントのプロフィール・連絡先
 *   ② ★購入検討者属性（エージェントの自由入力）
 *   ③ カレンダー（エージェントが対応可能とした候補日時＝黄色）から1枠を選ぶ → 緑
 *   ④ 鍵の受け渡し方法の選択と入力（方法ごとに必須項目が変わる。写真・資料の添付も可）
 *   ⑤「この内容で内見を承諾」／「成約・申込済み」／「候補日時では内見不可」
 *
 * 買主の赤い候補や他案件の予定は表示しない。
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/includes/functions.php';
require_once __DIR__ . '/backend/includes/viewing-helper.php';
require_once __DIR__ . '/backend/includes/viewing-email-helper.php';

$token = trim((string)($_GET['t'] ?? ''));
$error = '';
$data = null;

try {
    $db = (new Database())->getConnection();
    viewingEnsureTables($db);
    $ref = viewingTokenLookup($db, $token);
    if ($ref === null || $ref['audience'] !== 'seller') {
        $error = 'このURLは無効です。お手数ですが、担当者へご連絡ください。';
    } else {
        $case = viewingLoad($db, $ref['viewing_id']);
        if (!$case) {
            $error = '対象の内見が見つかりませんでした。';
        } else {
            $property = viewingLoadProperty($db, (int)$case['property_id']) ?: [];
            $agent = viewingAgentContact($db, (int)$case['business_card_id']);
            $slots = array_values(array_filter(
                viewingSlots($db, (int)$case['id'], (int)$case['round']),
                fn($s) => in_array($s['state'], ['agent', 'seller'], true)
            ));
            $data = [
                'case' => $case, 'property' => $property, 'agent' => $agent,
                'slots' => $slots, 'key_methods' => viewingKeyMethodDefs(),
                'attachments' => viewingAttachments($db, (int)$case['id']),
            ];
            viewingLogEvent($db, (int)$case['id'], 'page_open', ['actor' => 'seller']);
        }
    }
} catch (Throwable $e) {
    error_log('viewing-reply page error: ' . $e->getMessage());
    $error = 'ページを表示できませんでした。時間をおいて再度お試しください。';
}

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$apiBase = rtrim(parse_url(API_BASE_URL, PHP_URL_PATH) ?: '/backend/api', '/');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>内見日時のご回答｜不動産AI名刺</title>
<link rel="stylesheet" href="assets/css/viewing-reply.css?v=20260920">
</head>
<body>
<div class="vr-page">
  <header class="vr-head">
    <div class="vr-head__title">内見日時のご回答</div>
    <div class="vr-head__sub">不動産AI名刺</div>
  </header>

<?php if ($error !== '' || !$data): ?>
  <div class="vr-card vr-msg vr-msg--error"><?= $esc($error ?: '表示できる内容がありません。') ?></div>
<?php else:
    $case = $data['case'];
    $property = $data['property'];
    $agent = $data['agent'];
    $status = (string)$case['status'];
    $closed = in_array($status, ['cancelled', 'unavailable'], true);
    $answered = in_array($status, ['confirmed', 'buyer_notified'], true);
    $buyerAttrs = trim((string)($case['buyer_attributes'] ?? ''));
    $isReschedule = (int)$case['is_rescheduling'] === 1;
?>
  <!-- ① 物件（物件名＋金額） -->
  <section class="vr-card">
    <div class="vr-prop__label">対象物件</div>
    <h1 class="vr-prop__name"><?= $esc(viewingPropertyLabel($property)) ?></h1>
    <?php if (trim((string)($property['address'] ?? '')) !== ''): ?>
      <div class="vr-prop__meta"><?= $esc($property['address']) ?></div>
    <?php endif; ?>
    <?php if ($isReschedule && trim((string)viewingFormatRange($case['prev_start_at'], $case['prev_end_at'])) !== ''): ?>
      <div class="vr-note">日時変更のご相談です。変更前の日時：<?= $esc(viewingFormatRange($case['prev_start_at'], $case['prev_end_at'])) ?>（変更前の予約は取り消しております）</div>
    <?php endif; ?>
  </section>

  <!-- ② 購入検討者属性（2026/9/20 追加ご依頼） -->
  <?php if ($buyerAttrs !== ''): ?>
  <section class="vr-card vr-card--attrs">
    <div class="vr-section-title">購入検討者属性</div>
    <div class="vr-attrs"><?= nl2br($esc($buyerAttrs)) ?></div>
  </section>
  <?php endif; ?>

  <!-- 担当エージェント -->
  <section class="vr-card">
    <div class="vr-section-title">ご依頼者（担当者）</div>
    <div class="vr-agent">
      <div class="vr-agent__name"><?= $esc(trim($agent['company'] . '　' . $agent['name'])) ?></div>
      <?php if ($agent['phone'] !== ''): ?><div class="vr-agent__row">TEL：<a href="tel:<?= $esc(preg_replace('/[^0-9+]/', '', $agent['phone'])) ?>"><?= $esc($agent['phone']) ?></a></div><?php endif; ?>
      <?php if ($agent['email'] !== ''): ?><div class="vr-agent__row">Mail：<a href="mailto:<?= $esc($agent['email']) ?>"><?= $esc($agent['email']) ?></a></div><?php endif; ?>
      <?php if ($agent['card_url'] !== ''): ?><div class="vr-agent__row"><a class="vr-link" href="<?= $esc($agent['card_url']) ?>" target="_blank" rel="noopener">プロフィール・名刺を見る</a></div><?php endif; ?>
      <?php if (!empty($agent['name_card_url'])): ?>
        <div class="vr-agent__card"><img src="<?= $esc($agent['name_card_url']) ?>" alt="担当者の名刺" loading="lazy"></div>
      <?php endif; ?>
    </div>
  </section>

<?php if ($closed): ?>
  <div class="vr-card vr-msg vr-msg--info">
    この内見は「<?= $esc(viewingStatusDefs()[$status]['label'] ?? $status) ?>」です。新たなご回答は受け付けておりません。
  </div>
<?php elseif ($answered): ?>
  <div class="vr-card vr-msg vr-msg--done">
    ご回答ありがとうございました。内見日時は <strong><?= $esc(viewingFormatRange($case['confirmed_start_at'], $case['confirmed_end_at'])) ?></strong> で確定しております。<br>
    変更・キャンセルのご連絡は、上記の担当者へ直接お願いいたします。
  </div>
<?php else: ?>
  <form id="vr-form" class="vr-card" data-token="<?= $esc($token) ?>" data-api="<?= $esc($apiBase) ?>">
    <!-- ③ 候補日時（1枠だけ選ぶ） -->
    <div class="vr-section-title">1. 内見日時をお選びください</div>
    <p class="vr-guide">候補の中から、最も早く内見できる日時を<strong>1つ</strong>お選びください。選択した日時は緑色になります。</p>
    <?php if (!$data['slots']): ?>
      <div class="vr-msg vr-msg--info">候補日時がまだ登録されていません。担当者へご連絡ください。</div>
    <?php else: ?>
      <div class="vr-slots">
        <?php foreach ($data['slots'] as $s): ?>
          <label class="vr-slot">
            <input type="radio" name="slot_id" value="<?= (int)$s['id'] ?>"<?= $s['state'] === 'seller' ? ' checked' : '' ?>>
            <span class="vr-slot__text"><?= $esc(viewingFormatRange($s['start_at'], $s['end_at'])) ?></span>
            <span class="vr-slot__state">選択中</span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ④ 鍵の受け渡し -->
    <div class="vr-section-title">2. 鍵の受け渡し方法をご入力ください</div>
    <p class="vr-guide">受け渡し方法を1つお選びのうえ、必要な項目をご入力ください。</p>
    <div class="vr-methods">
      <?php foreach ($data['key_methods'] as $code => $def): ?>
        <label class="vr-method">
          <input type="radio" name="key_method" value="<?= $esc($code) ?>"<?= $case['key_method'] === $code ? ' checked' : '' ?>>
          <span><?= $esc($def['label']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php foreach ($data['key_methods'] as $code => $def): ?>
      <fieldset class="vr-fields" data-method="<?= $esc($code) ?>" hidden>
        <?php foreach ($def['fields'] as $field => $label): ?>
          <div class="vr-field">
            <label for="key-<?= $esc($code) ?>-<?= $esc($field) ?>">
              <?= $esc($label) ?><?= in_array($field, $def['required'], true) ? '<span class="vr-req">必須</span>' : '' ?>
            </label>
            <?php $val = ($case['key_method'] === $code) ? (string)($case['key_data'][$field] ?? '') : ''; ?>
            <?php if ($field === 'note'): ?>
              <textarea id="key-<?= $esc($code) ?>-<?= $esc($field) ?>" data-key="<?= $esc($field) ?>" rows="3"><?= $esc($val) ?></textarea>
            <?php else: ?>
              <input type="text" id="key-<?= $esc($code) ?>-<?= $esc($field) ?>" data-key="<?= $esc($field) ?>" value="<?= $esc($val) ?>">
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($def['attachment'])): ?>
          <div class="vr-field">
            <label>写真・資料の添付<span class="vr-opt">任意</span></label>
            <input type="file" id="vr-file" accept="image/*,application/pdf">
            <div class="vr-attach-note">画像またはPDF（最大<?= (int)VIEWING_ATTACHMENT_MAX ?>件）。買主には公開されません。</div>
            <ul class="vr-attach-list" id="vr-attach-list">
              <?php foreach ($data['attachments'] as $a): ?>
                <li><a href="<?= $esc($apiBase) ?>/property/viewing-attachment.php?id=<?= (int)$a['id'] ?>&t=<?= $esc($token) ?>" target="_blank" rel="noopener"><?= $esc($a['original_name']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </fieldset>
    <?php endforeach; ?>

    <div id="vr-msg" class="vr-msg" hidden></div>

    <!-- ⑤ 回答ボタン -->
    <div class="vr-actions">
      <button type="button" class="vr-btn vr-btn--primary" data-action="accept">この内容で内見を承諾</button>
      <button type="button" class="vr-btn vr-btn--ghost" data-action="no_slot">候補日時では内見不可</button>
      <button type="button" class="vr-btn vr-btn--ghost" data-action="contracted">成約・申込済み</button>
    </div>
    <p class="vr-guide vr-guide--small">
      お申し込み・ご成約により内見できない場合は「成約・申込済み」を、
      候補の日時がすべて合わない場合は「候補日時では内見不可」をお選びください。鍵情報のご入力は不要です。
    </p>
  </form>
<?php endif; ?>
<?php endif; ?>

  <footer class="vr-foot">
    このページは、不動産AI名刺（<a href="https://www.ai-fcard.com/" target="_blank" rel="noopener">https://www.ai-fcard.com/</a>）の内見調整機能から開かれています。
  </footer>
</div>
<script src="assets/js/viewing-reply.js?v=20260920"></script>
</body>
</html>

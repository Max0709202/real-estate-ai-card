/* 物件選定機能 エージェント管理画面ロジック（window.PropertyAgent）。
 * edit.php の担当連絡 詳細ビュー内 #property-panel に物件選定UIを描画する。
 * 依存: property-core.js（window.PropertyUI） */
(function (w) {
  'use strict';
  var UI = w.PropertyUI;
  var API = w.location.origin + '/backend/api/property';
  var P; // 現在のパネル要素
  var SID; // セッションID

  function esc(s) { return UI.esc(s); }
  function notify(type, msg) {
    if (type === 'error' && typeof w.showError === 'function') return w.showError(msg);
    if (type !== 'error' && typeof w.showSuccess === 'function') return w.showSuccess(msg, { autoClose: 2500 });
    if (type === 'error') alert(msg); else console.log(msg);
  }
  function api(path, opts) {
    opts = opts || {}; opts.credentials = 'include';
    return fetch(API + path, opts).then(function (r) { return r.json(); });
  }

  /* ===== 一覧（§1） ===== */
  function renderList() {
    P.innerHTML = '<div class="prop-toolbar"><h4>物件選定</h4>' +
      '<button type="button" class="prop-btn prop-btn--primary" id="prop-add">' + UI.icon('plus') + '物件を追加</button></div>' +
      '<div id="prop-list-body"><div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div></div>';
    P.querySelector('#prop-add').addEventListener('click', openAddMethods);
    api('/list.php?session_id=' + encodeURIComponent(SID)).then(function (res) {
      var body = P.querySelector('#prop-list-body');
      if (!res.success) { body.innerHTML = '<div class="prop-empty">読み込みに失敗しました。</div>'; return; }
      var items = res.data.properties || [];
      if (!items.length) { body.innerHTML = '<div class="prop-empty">提案物件はまだありません。「物件を追加」から登録してください。</div>'; return; }
      body.innerHTML = '<div class="prop-list">' + items.map(function (p) { return UI.cardHtml(p, {}); }).join('') + '</div>';
      body.querySelectorAll('.prop-card').forEach(function (c) {
        c.addEventListener('click', function () { openDetail(parseInt(c.getAttribute('data-prop-id'), 10)); });
      });
    });
  }

  /* ===== 登録方法選択（§2） ===== */
  function openAddMethods() {
    var html = '<div class="prop-method-list">' +
      method('upload', 'upload', '販売図面をアップロード', 'PDF・画像をアップロードしてAIが物件情報を自動読み取り') +
      method('photo', 'camera', '写真を撮影して登録', 'その場で撮影してAIが物件情報を読み取り') +
      method('manual', 'manual', '手入力で登録', '販売図面がない場合など手入力で登録します') +
      method('url', 'url', '物件URLから登録', 'SUUMO・HOME\'S・アットホーム等のURLから自動取得') +
      '</div>';
    var m = UI.modal('提案物件追加', html);
    m.body.querySelector('[data-method="upload"]').addEventListener('click', function () { m.close(); pickFlyer(false); });
    m.body.querySelector('[data-method="photo"]').addEventListener('click', function () { m.close(); pickFlyer(true); });
    m.body.querySelector('[data-method="manual"]').addEventListener('click', function () { m.close(); openEditForm(null, false); });
    m.body.querySelector('[data-method="url"]').addEventListener('click', function () { m.close(); openUrlForm(); });
  }
  function method(key, ic, title, desc) {
    return '<button type="button" class="prop-method" data-method="' + key + '">' +
      '<span class="prop-method__icon prop-method__icon--' + key + '">' + UI.icon(ic) + '</span>' +
      '<span class="prop-method__body"><span class="prop-method__title">' + esc(title) + '</span>' +
      '<span class="prop-method__desc">' + esc(desc) + '</span></span>' +
      '<span class="prop-method__chev">' + UI.icon('chev') + '</span></button>';
  }

  /* ===== 販売図面アップロード→OCR（§2①②, §7, §8） ===== */
  function pickFlyer(camera) {
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/*,application/pdf'; inp.multiple = true;
    if (camera) inp.setAttribute('capture', 'environment');
    inp.addEventListener('change', function () {
      if (!inp.files || !inp.files.length) return;
      var fd = new FormData();
      fd.append('session_id', SID);
      for (var i = 0; i < inp.files.length; i++) fd.append('files[]', inp.files[i]);
      var m = UI.modal('AI読み取り中', '<div class="prop-empty"><span class="prop-spinner"></span> 販売図面を解析しています…<br>少々お待ちください。</div>');
      api('/analyze.php', { method: 'POST', body: fd }).then(function (res) {
        m.close();
        if (!res.success) { notify('error', res.message || '解析に失敗しました'); return; }
        if (res.data.ocr_error) notify('error', res.data.ocr_error);
        // §8: AI抽出結果を確認・編集する画面へ（ドラフト）
        openEditForm(res.data.property, true);
      }).catch(function () { m.close(); notify('error', '通信に失敗しました'); });
    });
    inp.click();
  }

  /* ===== URL登録（§2④/§18） ===== */
  function openUrlForm() {
    var html = '<div class="prop-field full"><label>物件URL</label>' +
      '<input type="url" id="prop-url" placeholder="https://suumo.jp/..."></div>' +
      '<div class="prop-msg prop-msg--info">URLからAIが物件情報を自動取得します（SUUMO / HOME\'S / アットホーム / Yahoo!不動産 等）。</div>' +
      '<div class="prop-form-actions"><button type="button" class="prop-btn prop-btn--primary" id="prop-url-go">取得して登録</button></div>';
    var m = UI.modal('物件URLから登録', html);
    m.body.querySelector('#prop-url-go').addEventListener('click', function () {
      var url = m.body.querySelector('#prop-url').value.trim();
      if (!/^https?:\/\//i.test(url)) { notify('error', '有効なURLを入力してください'); return; }
      var btn = m.body.querySelector('#prop-url-go'); btn.disabled = true; btn.innerHTML = '<span class="prop-spinner"></span> 取得中...';
      api('/analyze-url.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session_id: SID, url: url }) })
        .then(function (res) {
          m.close();
          if (!res.success) { notify('error', res.message || '取得に失敗しました'); return; }
          if (res.data.extract_error) notify('error', res.data.extract_error);
          openEditForm(res.data.property, true);
        }).catch(function () { m.close(); notify('error', '通信に失敗しました'); });
    });
  }

  /* ===== 編集フォーム（§8 OCR確認 / §11 編集・手入力新規） ===== */
  function openEditForm(prop, isOcrDraft) {
    prop = prop || {};
    var fields = UI.FIELDS;
    var typeSel = '<div class="prop-field"><label>物件種別</label><select data-f="property_type">' +
      Object.keys(UI.TYPES).map(function (k) {
        return '<option value="' + k + '"' + ((prop.property_type || 'mansion') === k ? ' selected' : '') + '>' + UI.TYPES[k] + '</option>';
      }).join('') + '</select></div>';
    var srcSel = '<div class="prop-field"><label>提案元</label><select data-f="source">' +
      '<option value="agent"' + (prop.source === 'customer' ? '' : ' selected') + '>エージェント提案</option>' +
      '<option value="customer"' + (prop.source === 'customer' ? ' selected' : '') + '>お客様から共有</option></select></div>';

    // 取引態様の選択肢（販売図面に記載があればそのまま採用、無ければプルダウン＋「その他」自由入力）
    var TT_OPTS = ['売主', '代理', '一般媒介', '専任媒介', '専属専任媒介', '媒介'];
    function transactionControl(v) {
      v = v || '';
      var isStd = TT_OPTS.indexOf(v) >= 0;
      var isOther = (v !== '' && !isStd);
      var opts = '<option value="">選択してください</option>' +
        TT_OPTS.map(function (o) { return '<option value="' + esc(o) + '"' + (v === o ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') +
        '<option value="その他"' + (isOther ? ' selected' : '') + '>その他</option>';
      return '<div class="prop-field full"><label>取引態様</label>' +
        '<select data-tt-select>' + opts + '</select>' +
        '<input type="text" data-tt-other placeholder="取引態様を入力" value="' + (isOther ? esc(v) : '') + '" style="margin-top:6px;' + (isOther ? '' : 'display:none') + '">' +
        '</div>';
    }

    function inputFor(f) {
      var key = f[0], label = f[1], group = f[2];
      var v = prop[key] != null ? String(prop[key]) : '';
      if (key === 'transaction_type') return transactionControl(v);
      var full = (key === 'remarks' || key === 'seller_remarks' || key === 'address' || key === 'transport') ? ' full' : '';
      var ctrl;
      if (key === 'remarks' || key === 'seller_remarks') ctrl = '<textarea data-f="' + key + '" rows="2">' + esc(v) + '</textarea>';
      else ctrl = '<input type="text" data-f="' + key + '" value="' + esc(v) + '">';
      return '<div class="prop-field' + full + '"><label>' + esc(label) + '</label>' + ctrl + '</div>';
    }

    var basicInputs = fields.filter(function (f) { return f[2] === 'basic'; }).map(inputFor).join('');
    var sellerInputs = fields.filter(function (f) { return f[2] === 'seller'; }).map(inputFor).join('');

    // 販売図面プレビュー（OCR内容を見ながら確認・訂正する。クリック/タップで拡大）
    var flyerList = (prop.flyers && prop.flyers.length) ? prop.flyers : [];
    var hasFlyer = flyerList.length > 0;
    var flyerPane = '';
    if (hasFlyer) {
      flyerPane = '<div class="prop-edit-flyer"><div class="prop-edit-flyer__label">販売図面（クリック／タップで拡大）</div>' +
        flyerList.map(function (f) {
          var u = f.preview_url || f.url;
          return '<img class="prop-edit-flyer__img" src="' + esc(u) + '" alt="販売図面" loading="lazy" data-full="' + esc(u) + '">';
        }).join('') + '</div>';
    }

    var formHtml = '<form class="prop-form" id="prop-edit-form">' +
      (isOcrDraft ? '<div class="prop-msg prop-msg--info full">AIが読み取った内容です。販売図面と照らし合わせ、誤りを修正して「確認して保存」してください（修正できるのはエージェントのみ）。</div>' : '') +
      typeSel + srcSel +
      '<div class="prop-field full"><label>掲載媒体</label><input type="text" data-f="source_media" value="' + esc(prop.source_media || 'manual') + '"></div>' +
      '<div class="prop-field full"><label>元URL</label><input type="url" data-f="source_url" value="' + esc(prop.source_url || '') + '"></div>' +
      basicInputs +
      '<div class="prop-section-title full">＜売主仲介会社情報（顧客には非表示）＞</div>' +
      sellerInputs +
      '<div class="prop-form-actions">' +
        (prop.id ? '<button type="button" class="prop-btn prop-btn--ghost" id="prop-edit-cancel">キャンセル</button>' : '') +
        '<button type="submit" class="prop-btn prop-btn--primary">' + (isOcrDraft ? '確認して保存' : '保存') + '</button>' +
      '</div></form>';

    var html = '<div class="prop-edit-wrap' + (hasFlyer ? ' has-flyer' : '') + '">' + flyerPane + formHtml + '</div>';

    var m = UI.modal(prop.id ? (isOcrDraft ? 'AI読取結果の確認' : '物件情報の編集') : '物件を手入力で登録', html);
    if (hasFlyer) {
      var modalEl = m.overlay.querySelector('.prop-modal');
      if (modalEl) modalEl.classList.add('prop-modal--wide');
      UI.bindLightbox(m.body); // 販売図面プレビューのクリックで拡大ビューアを開く
    }
    if (m.body.querySelector('#prop-edit-cancel')) m.body.querySelector('#prop-edit-cancel').addEventListener('click', m.close);
    // 取引態様: 「その他」選択時のみ自由入力を表示
    var ttSel = m.body.querySelector('[data-tt-select]');
    var ttOther = m.body.querySelector('[data-tt-other]');
    if (ttSel && ttOther) {
      ttSel.addEventListener('change', function () {
        if (ttSel.value === 'その他') { ttOther.style.display = ''; ttOther.focus(); }
        else { ttOther.style.display = 'none'; }
      });
    }
    m.body.querySelector('#prop-edit-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = { fields: {} };
      m.body.querySelectorAll('[data-f]').forEach(function (el) {
        var k = el.getAttribute('data-f');
        if (k === 'source' || k === 'source_media' || k === 'source_url') payload[k] = el.value.trim();
        else payload.fields[k] = el.value;
      });
      // 取引態様（プルダウン＋その他自由入力）
      if (ttSel) {
        payload.fields.transaction_type = (ttSel.value === 'その他') ? (ttOther ? ttOther.value.trim() : '') : ttSel.value;
      }
      if (prop.id) payload.property_id = prop.id;
      else payload.session_id = SID;
      if (isOcrDraft) payload.confirm_ocr = true;
      var btn = m.body.querySelector('button[type=submit]'); btn.disabled = true;
      api('/save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (res) {
          if (!res.success) { notify('error', res.message || '保存に失敗しました'); btn.disabled = false; return; }
          m.close(); notify('ok', '保存しました');
          if (prop.id && P.querySelector('#prop-detail')) openDetail(prop.id); else renderList();
        }).catch(function () { notify('error', '通信に失敗しました'); btn.disabled = false; });
    });
  }

  /* ===== 詳細（§9-§15, §19） ===== */
  function openDetail(id) {
    P.innerHTML = '<div id="prop-detail" class="prop-wrap"><div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div></div>';
    api('/get.php?id=' + id).then(function (res) {
      if (!res.success) { P.querySelector('#prop-detail').innerHTML = '<div class="prop-empty">取得に失敗しました。</div>'; return; }
      renderDetail(res.data.property);
    });
  }

  function renderDetail(p) {
    var d = P.querySelector('#prop-detail');
    var statusChips = Object.keys(UI.STATUS).filter(function (k) { return UI.STATUS[k].role === 'agent'; }).map(function (k) {
      var s = UI.STATUS[k]; var on = p.status === k;
      return '<button type="button" class="prop-status-opt' + (on ? ' is-selected' : '') + '" data-set-status="' + k + '" style="color:' + s.color + '">' +
        '<span class="prop-badge--icon" style="color:' + s.color + '">' + UI.icon(s.icon) + '</span>' + esc(s.label) + '</button>';
    }).join('');

    d.innerHTML =
      '<div class="prop-toolbar"><button type="button" class="prop-btn prop-btn--ghost" id="prop-back">← 一覧</button>' +
      '<button type="button" class="prop-btn prop-btn--ghost" id="prop-edit-btn">' + UI.icon('edit') + '編集</button>' +
      '<button type="button" class="prop-btn prop-btn--danger" id="prop-del-btn">' + UI.icon('trash') + '削除</button></div>' +
      UI.detailHeaderHtml(p) +
      '<div class="prop-section-title">対応ステータス（エージェント）</div>' +
      '<div class="prop-status-grid" id="prop-agent-status">' + statusChips + '</div>' +
      '<div class="prop-tabs">' +
        '<button class="prop-tab is-active" data-tab="basic">基本情報</button>' +
        '<button class="prop-tab" data-tab="hazard">ハザード等情報</button>' +
        '<button class="prop-tab" data-tab="flyer">販売図面</button>' +
        '<button class="prop-tab" data-tab="photo">写真・資料</button>' +
      '</div>' +
      '<div class="prop-tabpane is-active" data-pane="basic">' + UI.basicInfoHtml(p, true) + '</div>' +
      '<div class="prop-tabpane" data-pane="hazard"></div>' +
      '<div class="prop-tabpane" data-pane="flyer"></div>' +
      '<div class="prop-tabpane" data-pane="photo"></div>';

    d.querySelector('#prop-back').addEventListener('click', renderList);
    d.querySelector('#prop-edit-btn').addEventListener('click', function () { openEditForm(p, false); });
    d.querySelector('#prop-del-btn').addEventListener('click', function () {
      if (!confirm('この物件を削除します。よろしいですか？')) return;
      api('/delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id }) })
        .then(function (res) { if (res.success) { notify('ok', '削除しました'); renderList(); } else notify('error', res.message || '削除に失敗'); });
    });
    d.querySelectorAll('[data-set-status]').forEach(function (b) {
      b.addEventListener('click', function () {
        var st = b.getAttribute('data-set-status');
        if (p.status === st) st = ''; // 再タップで解除
        api('/status.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id, status: st }) })
          .then(function (res) { if (res.success) { p.status = res.data.property.status; renderDetail(p); } else notify('error', res.message); });
      });
    });

    // タブ
    var tabs = d.querySelectorAll('.prop-tab');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (x) { x.classList.remove('is-active'); });
        d.querySelectorAll('.prop-tabpane').forEach(function (x) { x.classList.remove('is-active'); });
        t.classList.add('is-active');
        var name = t.getAttribute('data-tab');
        d.querySelector('[data-pane="' + name + '"]').classList.add('is-active');
        if (name === 'hazard') loadHazard(p);
        if (name === 'flyer') loadImages(p, 'flyer');
        if (name === 'photo') loadImages(p, 'photo');
      });
    });
    UI.bindLightbox(d);
  }

  /* ハザード（§12/§13） */
  function loadHazard(p) {
    var pane = P.querySelector('[data-pane="hazard"]');
    var btnRow = '<div class="prop-toolbar" style="margin-top:8px">' +
      '<button type="button" class="prop-btn prop-btn--primary" id="prop-haz-get">' + UI.icon('refresh') +
      (p.hazard ? 'ハザード再取得' : 'ハザード取得') + '</button>' +
      (p.address ? '' : '<span class="prop-msg prop-msg--warn">所在地が未登録のため取得できません。</span>') + '</div>';
    pane.innerHTML = UI.hazardHtml(p.hazard, p.hazard_fetched_at) + btnRow;
    var btn = pane.querySelector('#prop-haz-get');
    if (btn) btn.addEventListener('click', function () {
      btn.disabled = true; btn.innerHTML = '<span class="prop-spinner"></span> 取得中...';
      api('/hazard.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id, force: p.hazard ? 1 : 0 }) })
        .then(function (res) {
          if (!res.success) { notify('error', res.message || '取得に失敗しました'); btn.disabled = false; return; }
          p.hazard = res.data.hazard; p.hazard_fetched_at = res.data.fetched_at;
          loadHazard(p);
        }).catch(function () { notify('error', '通信に失敗しました'); btn.disabled = false; });
    });
  }

  /* 画像（§14 販売図面 / §15 写真・資料）
     写真・資料は販売図面アップロード時にAIが自動抽出・分類して登録するため、ここでは閲覧のみ（手動追加/削除なし）。 */
  function loadImages(p, category) {
    var pane = P.querySelector('[data-pane="' + category + '"]');
    if (category === 'photo') {
      pane.innerHTML = '<div class="prop-msg prop-msg--info" style="margin-top:8px">販売図面のアップロード時に、AIが建物外観・間取り図・室内・設備・地図を自動で抽出し登録します（最大10枚）。会社情報を含む画像は登録されません。</div>' +
        '<div id="prop-img-body"><div class="prop-empty"><span class="prop-spinner"></span></div></div>';
      refreshImages(p, category);
      return;
    }
    // 販売図面の追加アップロード（OCR・AI解析・マスク生成まで自動実行）
    pane.innerHTML = '<div class="prop-toolbar" style="margin-top:8px">' +
      '<button type="button" class="prop-btn prop-btn--primary" id="prop-img-add">' + UI.icon('upload') + '販売図面を追加</button></div>' +
      '<div id="prop-img-body"><div class="prop-empty"><span class="prop-spinner"></span></div></div>';
    refreshImages(p, category);
    pane.querySelector('#prop-img-add').addEventListener('click', function () {
      var inp = document.createElement('input');
      inp.type = 'file'; inp.accept = 'image/*,application/pdf'; inp.multiple = true;
      inp.addEventListener('change', function () {
        if (!inp.files.length) return;
        var fd = new FormData();
        fd.append('property_id', p.id); fd.append('category', 'flyer');
        for (var i = 0; i < inp.files.length; i++) fd.append('files[]', inp.files[i]);
        var body = pane.querySelector('#prop-img-body');
        body.innerHTML = '<div class="prop-empty"><span class="prop-spinner"></span> アップロード・AI解析中...</div>';
        api('/image-upload.php', { method: 'POST', body: fd }).then(function (res) {
          if (!res.success) { notify('error', res.message || 'アップロードに失敗'); }
          refreshImages(p, 'flyer');
        }).catch(function () { notify('error', '通信に失敗しました'); refreshImages(p, 'flyer'); });
      });
      inp.click();
    });
  }
  function refreshImages(p, category) {
    var body = P.querySelector('[data-pane="' + category + '"] #prop-img-body');
    api('/get.php?id=' + p.id).then(function (res) {
      if (!res.success) return;
      var prop = res.data.property;
      if (category === 'flyer') { renderFlyerList(p, body, prop.flyers || []); return; }
      // 写真・資料: 閲覧のみ（分類ラベル付き）
      var photos = prop.photos || [];
      if (!photos.length) { body.innerHTML = '<div class="prop-empty">販売図面をアップロードすると、AIが抽出した写真・資料が自動で表示されます。</div>'; return; }
      body.innerHTML = '<div class="prop-gallery">' + photos.map(function (im) {
        var url = UI.addAuth(im.url, {});
        var cap = im.subcategory ? '<span class="prop-photo-cap">' + UI.esc(im.subcategory) + '</span>' : '';
        return '<div class="prop-thumb"><img src="' + UI.esc(url) + '" alt="" loading="lazy" data-full="' + UI.esc(url) + '">' + cap + '</div>';
      }).join('') + '</div>';
      UI.bindLightbox(body);
    });
  }
  function bindDeletes(body, p, category) {
    body.querySelectorAll('[data-del-img]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!confirm('この画像を削除しますか？')) return;
        api('/image-delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_id: parseInt(b.getAttribute('data-del-img'), 10) }) })
          .then(function (r) { if (r.success) refreshImages(p, category); else notify('error', r.message || '削除に失敗'); });
      });
    });
    UI.bindLightbox(body);
  }

  /* ===== 販売図面リスト（マスク状態・顧客公開状態つき） ===== */
  function flyerStatusChip(f) {
    if ((f.customer_visible | 0) === 1) return '<span class="prop-mask-chip prop-mask-chip--ok">顧客に公開中</span>';
    if (f.mask_status === 'masked') return '<span class="prop-mask-chip prop-mask-chip--pending">確認待ち（顧客に非公開）</span>';
    return '<span class="prop-mask-chip prop-mask-chip--none">処理中／未処理（顧客に非公開）</span>';
  }
  function renderFlyerList(p, body, flyers) {
    if (!flyers.length) { body.innerHTML = '<div class="prop-empty">販売図面はまだありません。</div>'; return; }
    body.innerHTML = '<div class="prop-msg prop-msg--info">アップロード時にAIが売主仲介会社情報（会社名・住所・電話・QR等）のマスク範囲を提案します。<b>「マスク編集」で内容を確認・修正して保存すると顧客に公開されます。</b>編集が完了するまで顧客には表示されません。</div>' +
      '<div class="prop-flyer-list">' + flyers.map(function (f) {
      var thumb = f.preview_url ? '<img src="' + UI.esc(f.preview_url) + '" alt="" loading="lazy">' : '<div class="prop-thumb__pdf">図面</div>';
      var actions = '<button type="button" class="prop-btn prop-btn--primary" data-mask-edit="' + f.id + '">' + UI.icon('edit') + 'マスク編集' + ((f.customer_visible | 0) === 1 ? '' : '（確認して公開）') + '</button>';
      if ((f.customer_visible | 0) === 1 && f.masked_url) actions += '<a class="prop-btn prop-btn--ghost" href="' + UI.esc(f.masked_url) + '" target="_blank" rel="noopener noreferrer">顧客用PDFを確認</a>';
      if ((f.customer_visible | 0) === 1) actions += '<button type="button" class="prop-btn prop-btn--ghost" data-flyer-hide="' + f.id + '">公開を停止</button>';
      actions += '<a class="prop-btn prop-btn--ghost" href="' + UI.esc(f.url) + '" target="_blank" rel="noopener noreferrer">元PDF</a>';
      actions += '<button type="button" class="prop-btn prop-btn--danger" data-del-img="' + f.id + '">' + UI.icon('trash') + '削除</button>';
      return '<div class="prop-flyer-row"><div class="prop-flyer-thumb">' + thumb + '</div>' +
        '<div class="prop-flyer-main">' + flyerStatusChip(f) +
        '<div class="prop-flyer-actions">' + actions + '</div></div></div>';
    }).join('') + '</div>';

    body.querySelectorAll('[data-mask-edit]').forEach(function (b) {
      b.addEventListener('click', function () { openMaskEditor(p, parseInt(b.getAttribute('data-mask-edit'), 10)); });
    });
    body.querySelectorAll('[data-flyer-hide]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = parseInt(b.getAttribute('data-flyer-hide'), 10);
        api('/flyer-visibility.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_id: id, visible: 0 }) })
          .then(function (r) { if (r.success) { notify('ok', r.message || '公開を停止しました'); refreshImages(p, 'flyer'); } else notify('error', r.message || '失敗しました'); });
      });
    });
    bindDeletes(body, p, 'flyer');
  }

  /* ===== マスク編集モーダル =====
     フロー: マスク編集 → 編集画面（白抜き半透明）→ 顧客用プレビュー → 「この内容で確定」/「範囲を再編集」
     「この内容で確定」を押すまで顧客には販売図面を表示しない（customer_visible=0）。 */
  var PROP_DEFAULT_BAND = { x: 0, y: 0.857, w: 1, h: 0.143 }; // A4横の下3cm相当

  function openMaskEditor(p, imageId) {
    var m = UI.modal('販売図面のマスク編集', '<div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div>');
    var modalEl = m.overlay.querySelector('.prop-modal');
    if (modalEl) modalEl.classList.add('prop-modal--wide');
    var moveHandler = null, upHandler = null;
    function teardownDrag() {
      if (moveHandler) document.removeEventListener('pointermove', moveHandler);
      if (upHandler) document.removeEventListener('pointerup', upHandler);
      moveHandler = upHandler = null;
    }
    var baseClose = m.close;
    m.close = function () { teardownDrag(); baseClose(); };

    api('/flyer-mask.php?image_id=' + imageId).then(function (res) {
      if (!res.success || !res.data.preview_url) { m.body.innerHTML = '<div class="prop-msg prop-msg--err">プレビューを表示できませんでした。</div>'; return; }
      var d = res.data;
      var regions = (d.regions && d.regions.length) ? d.regions.map(function (r) {
        return { x: +r.x || 0, y: +r.y || 0, w: +r.w || 0, h: +r.h || 0 };
      }) : [Object.assign({}, PROP_DEFAULT_BAND)]; // 既定: A4横の下3cmを白抜き

      // ベース画像を先読み（編集・プレビュー双方で使用）
      var baseImg = new Image();
      baseImg.onload = renderEdit;
      baseImg.onerror = function () { m.body.innerHTML = '<div class="prop-msg prop-msg--err">プレビュー画像を読み込めませんでした。</div>'; };
      baseImg.src = d.preview_url;

      /* --- 編集画面（ドラッグで範囲設定・白抜きは半透明で下地が少し見える） --- */
      function renderEdit() {
        teardownDrag();
        m.body.innerHTML =
          '<div class="prop-msg prop-msg--info">マスク（見えなくなる）する範囲をドラッグで設定できます。売主仲介会社の情報など、不要な情報をマスクしてください。なお、「この内容で確定」ボタンを押さない限り、顧客には販売図面は表示されません。</div>' +
          '<div class="prop-mask-editor"><div class="prop-mask-canvas" id="prop-mask-canvas">' +
          '<img src="' + UI.esc(d.preview_url) + '" alt="" id="prop-mask-img" draggable="false"></div></div>' +
          '<div class="prop-form-actions">' +
          '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-add">' + UI.icon('plus') + '範囲を追加</button>' +
          '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-cancel">キャンセル</button>' +
          '<button type="button" class="prop-btn prop-btn--primary" id="prop-mask-preview">顧客用プレビューを確認</button></div>';
        var canvas = m.body.querySelector('#prop-mask-canvas');

        function setRectStyle(el, r) {
          el.style.left = (r.x * 100) + '%'; el.style.top = (r.y * 100) + '%';
          el.style.width = (r.w * 100) + '%'; el.style.height = (r.h * 100) + '%';
        }
        function render() {
          canvas.querySelectorAll('.prop-mask-rect').forEach(function (n) { n.remove(); });
          regions.forEach(function (r, i) {
            var el = document.createElement('div');
            el.className = 'prop-mask-rect';
            el.setAttribute('data-i', i);
            setRectStyle(el, r);
            el.innerHTML = '<button type="button" class="prop-mask-del" aria-label="削除">×</button><span class="prop-mask-handle"></span>';
            canvas.appendChild(el);
          });
        }
        var drag = null;
        canvas.addEventListener('pointerdown', function (e) {
          var del = e.target.closest('.prop-mask-del');
          if (del) { var di = +del.parentNode.getAttribute('data-i'); regions.splice(di, 1); render(); e.preventDefault(); return; }
          var rectEl = e.target.closest('.prop-mask-rect');
          if (!rectEl) return;
          var i = +rectEl.getAttribute('data-i');
          var cb = canvas.getBoundingClientRect();
          drag = {
            el: rectEl, i: i, resize: e.target.classList.contains('prop-mask-handle'),
            sx: e.clientX, sy: e.clientY, orig: { x: regions[i].x, y: regions[i].y, w: regions[i].w, h: regions[i].h },
            cw: cb.width || 1, ch: cb.height || 1
          };
          e.preventDefault();
        });
        moveHandler = function (e) {
          if (!drag) return;
          var dx = (e.clientX - drag.sx) / drag.cw;
          var dy = (e.clientY - drag.sy) / drag.ch;
          var r = regions[drag.i];
          if (drag.resize) {
            r.w = Math.max(0.03, Math.min(1 - drag.orig.x, drag.orig.w + dx));
            r.h = Math.max(0.02, Math.min(1 - drag.orig.y, drag.orig.h + dy));
          } else {
            r.x = Math.max(0, Math.min(1 - r.w, drag.orig.x + dx));
            r.y = Math.max(0, Math.min(1 - r.h, drag.orig.y + dy));
          }
          setRectStyle(drag.el, r);
        };
        upHandler = function () { drag = null; };
        document.addEventListener('pointermove', moveHandler);
        document.addEventListener('pointerup', upHandler);

        m.body.querySelector('#prop-mask-add').addEventListener('click', function () {
          regions.push(Object.assign({}, PROP_DEFAULT_BAND)); render();
        });
        m.body.querySelector('#prop-mask-cancel').addEventListener('click', function () { m.close(); });
        m.body.querySelector('#prop-mask-preview').addEventListener('click', renderPreview);
        render();
      }

      /* --- 顧客用プレビュー（実際の白抜き結果を表示） --- */
      function renderPreview() {
        teardownDrag();
        m.body.innerHTML =
          '<div class="prop-msg prop-msg--info">顧客に表示される販売図面のプレビューです。問題なければ「この内容で確定」を押すと顧客に公開されます。修正する場合は「範囲を再編集」を押してください。</div>' +
          '<div class="prop-mask-editor"><canvas id="prop-preview-canvas" class="prop-preview-canvas"></canvas></div>' +
          '<div class="prop-form-actions">' +
          '<button type="button" class="prop-btn prop-btn--ghost" id="prop-reedit">範囲を再編集</button>' +
          '<button type="button" class="prop-btn prop-btn--primary" id="prop-confirm">この内容で確定</button></div>';
        var cv = m.body.querySelector('#prop-preview-canvas');
        var nw = baseImg.naturalWidth || 1000, nh = baseImg.naturalHeight || 1414;
        cv.width = nw; cv.height = nh;
        var ctx = cv.getContext('2d');
        ctx.drawImage(baseImg, 0, 0, nw, nh);
        ctx.fillStyle = '#ffffff'; // 実際の出力は白べた
        regions.forEach(function (r) { ctx.fillRect(r.x * nw, r.y * nh, r.w * nw, r.h * nh); });

        m.body.querySelector('#prop-reedit').addEventListener('click', renderEdit);
        m.body.querySelector('#prop-confirm').addEventListener('click', function () {
          var btn = m.body.querySelector('#prop-confirm'); btn.disabled = true; btn.innerHTML = '<span class="prop-spinner"></span> 確定中...';
          api('/flyer-mask.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_id: imageId, regions: regions }) })
            .then(function (r) {
              if (!r.success) { notify('error', r.message || '失敗しました'); btn.disabled = false; return; }
              m.close(); notify('ok', '確定しました。顧客に公開されました'); refreshImages(p, 'flyer');
            }).catch(function () { notify('error', '通信に失敗しました'); btn.disabled = false; });
        });
      }
    });
  }

  function init(sessionId) {
    if (!UI) { return; }
    SID = sessionId;
    P = document.getElementById('property-panel');
    if (!P) return;
    P.classList.add('prop-wrap');
    renderList();
  }

  w.PropertyAgent = { init: init };
})(window);

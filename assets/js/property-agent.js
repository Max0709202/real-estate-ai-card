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

    function inputFor(f) {
      var key = f[0], label = f[1], group = f[2];
      var v = prop[key] != null ? String(prop[key]) : '';
      var full = (key === 'remarks' || key === 'seller_remarks' || key === 'address' || key === 'transport') ? ' full' : '';
      var ctrl;
      if (key === 'remarks' || key === 'seller_remarks') ctrl = '<textarea data-f="' + key + '" rows="2">' + esc(v) + '</textarea>';
      else ctrl = '<input type="text" data-f="' + key + '" value="' + esc(v) + '">';
      return '<div class="prop-field' + full + '"><label>' + esc(label) + '</label>' + ctrl + '</div>';
    }

    var basicInputs = fields.filter(function (f) { return f[2] === 'basic'; }).map(inputFor).join('');
    var sellerInputs = fields.filter(function (f) { return f[2] === 'seller'; }).map(inputFor).join('');

    var html = '<form class="prop-form" id="prop-edit-form">' +
      (isOcrDraft ? '<div class="prop-msg prop-msg--info full">AIが読み取った内容です。誤りを修正して「確認して保存」してください（修正できるのはエージェントのみ）。</div>' : '') +
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

    var m = UI.modal(prop.id ? (isOcrDraft ? 'AI読取結果の確認' : '物件情報の編集') : '物件を手入力で登録', html);
    if (m.body.querySelector('#prop-edit-cancel')) m.body.querySelector('#prop-edit-cancel').addEventListener('click', m.close);
    m.body.querySelector('#prop-edit-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = { fields: {} };
      m.body.querySelectorAll('[data-f]').forEach(function (el) {
        var k = el.getAttribute('data-f');
        if (k === 'source' || k === 'source_media' || k === 'source_url') payload[k] = el.value.trim();
        else payload.fields[k] = el.value;
      });
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

  /* 画像（§14 販売図面 / §15 写真・資料） */
  function loadImages(p, category) {
    var pane = P.querySelector('[data-pane="' + category + '"]');
    var isPhoto = category === 'photo';
    var sub = isPhoto ? '<select id="prop-img-sub" class="prop-field"><option value="">区分なし</option>' +
      ['間取り図', '外観写真', '室内写真', 'その他資料'].map(function (s) { return '<option>' + s + '</option>'; }).join('') + '</select>' : '';
    pane.innerHTML = '<div class="prop-toolbar" style="margin-top:8px">' + sub +
      '<button type="button" class="prop-btn prop-btn--primary" id="prop-img-add">' + UI.icon('upload') +
      (isPhoto ? '写真・資料を追加（最大10枚）' : '販売図面を追加') + '</button></div>' +
      '<div id="prop-img-body"><div class="prop-empty"><span class="prop-spinner"></span></div></div>';
    refreshImages(p, category);
    pane.querySelector('#prop-img-add').addEventListener('click', function () {
      var inp = document.createElement('input');
      inp.type = 'file'; inp.accept = isPhoto ? 'image/*' : 'image/*,application/pdf'; inp.multiple = true;
      inp.addEventListener('change', function () {
        if (!inp.files.length) return;
        var fd = new FormData();
        fd.append('property_id', p.id); fd.append('category', category);
        var subEl = pane.querySelector('#prop-img-sub');
        if (subEl && subEl.value) fd.append('subcategory', subEl.value);
        for (var i = 0; i < inp.files.length; i++) fd.append('files[]', inp.files[i]);
        var body = pane.querySelector('#prop-img-body');
        body.innerHTML = '<div class="prop-empty"><span class="prop-spinner"></span> アップロード中...</div>';
        api('/image-upload.php', { method: 'POST', body: fd }).then(function (res) {
          if (!res.success) { notify('error', res.message || 'アップロードに失敗'); }
          refreshImages(p, category);
        }).catch(function () { notify('error', '通信に失敗しました'); refreshImages(p, category); });
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
      body.innerHTML = UI.galleryHtml(prop.photos, { removable: true, emptyText: '写真・資料はまだありません。' });
      bindDeletes(body, p, category);
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

  /* ===== 販売図面リスト（マスク状態つき・§売主情報の自動非表示） ===== */
  function flyerStatusChip(st) {
    if (st === 'masked') return '<span class="prop-mask-chip prop-mask-chip--ok">顧客共有OK（マスク済）</span>';
    if (st === 'pending') return '<span class="prop-mask-chip prop-mask-chip--pending">マスク未確定（顧客非公開）</span>';
    return '<span class="prop-mask-chip prop-mask-chip--none">マスク未設定（顧客非公開）</span>';
  }
  function renderFlyerList(p, body, flyers) {
    if (!flyers.length) { body.innerHTML = '<div class="prop-empty">販売図面はまだありません。</div>'; return; }
    body.innerHTML = '<div class="prop-msg prop-msg--info">アップロードした販売図面は、売主仲介会社情報（会社名・住所・電話・QR等）をマスクしてから顧客に共有されます。AIが範囲を提案します。</div>' +
      '<div class="prop-flyer-list">' + flyers.map(function (f) {
      var thumb = f.preview_url ? '<img src="' + UI.esc(f.preview_url) + '" alt="" loading="lazy">' : '<div class="prop-thumb__pdf">図面</div>';
      var actions = '<button type="button" class="prop-btn prop-btn--primary" data-mask-edit="' + f.id + '">' + UI.icon('edit') + 'マスク編集</button>';
      if (f.mask_status !== 'masked') actions += '<button type="button" class="prop-btn prop-btn--ghost" data-mask-quick="' + f.id + '">そのまま確定</button>';
      if (f.masked_url) actions += '<a class="prop-btn prop-btn--ghost" href="' + UI.esc(f.masked_url) + '" target="_blank" rel="noopener noreferrer">顧客用PDFを確認</a>';
      actions += '<button type="button" class="prop-btn prop-btn--danger" data-del-img="' + f.id + '">' + UI.icon('trash') + '削除</button>';
      return '<div class="prop-flyer-row"><div class="prop-flyer-thumb">' + thumb + '</div>' +
        '<div class="prop-flyer-main">' + flyerStatusChip(f.mask_status) +
        '<div class="prop-flyer-actions">' + actions + '</div></div></div>';
    }).join('') + '</div>';

    body.querySelectorAll('[data-mask-edit]').forEach(function (b) {
      b.addEventListener('click', function () { openMaskEditor(p, parseInt(b.getAttribute('data-mask-edit'), 10)); });
    });
    body.querySelectorAll('[data-mask-quick]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = parseInt(b.getAttribute('data-mask-quick'), 10);
        var f = flyers.filter(function (x) { return x.id === id; })[0];
        b.disabled = true; b.innerHTML = '<span class="prop-spinner"></span> 処理中...';
        api('/flyer-mask.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_id: id, regions: (f && f.mask_regions) || [] }) })
          .then(function (r) { if (r.success) { notify('ok', '顧客共有用に作成しました'); refreshImages(p, 'flyer'); } else { notify('error', r.message || '失敗しました'); refreshImages(p, 'flyer'); } });
      });
    });
    bindDeletes(body, p, 'flyer');
  }

  /* ===== マスク編集モーダル（ドラッグで範囲修正・§③） ===== */
  function openMaskEditor(p, imageId) {
    var m = UI.modal('販売図面のマスク編集', '<div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div>');
    api('/flyer-mask.php?image_id=' + imageId).then(function (res) {
      if (!res.success || !res.data.preview_url) { m.body.innerHTML = '<div class="prop-msg prop-msg--err">プレビューを表示できませんでした。</div>'; return; }
      var d = res.data;
      var regions = (d.regions && d.regions.length) ? d.regions.slice() : [];
      m.body.innerHTML =
        '<div class="prop-msg prop-msg--info">黒く塗る範囲（売主情報）をドラッグで移動・隅で拡縮できます。「範囲を追加」で増やせます。9割の図面は下段が対象です。</div>' +
        '<div class="prop-mask-editor"><div class="prop-mask-canvas" id="prop-mask-canvas">' +
        '<img src="' + UI.esc(d.preview_url) + '" alt="" id="prop-mask-img" draggable="false"></div></div>' +
        '<div class="prop-form-actions">' +
        '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-add">' + UI.icon('plus') + '範囲を追加</button>' +
        '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-cancel">キャンセル</button>' +
        '<button type="button" class="prop-btn prop-btn--primary" id="prop-mask-save">この内容で確定</button></div>';
      var canvas = m.body.querySelector('#prop-mask-canvas');
      var img = m.body.querySelector('#prop-mask-img');
      function draw() {
        canvas.querySelectorAll('.prop-mask-rect').forEach(function (n) { n.remove(); });
        regions.forEach(function (r, i) { canvas.appendChild(makeRect(r, i)); });
      }
      function makeRect(r, idx) {
        var el = document.createElement('div');
        el.className = 'prop-mask-rect';
        el.style.left = (r.x * 100) + '%'; el.style.top = (r.y * 100) + '%';
        el.style.width = (r.w * 100) + '%'; el.style.height = (r.h * 100) + '%';
        el.innerHTML = '<button type="button" class="prop-mask-del" data-i="' + idx + '">×</button><span class="prop-mask-handle"></span>';
        el.querySelector('.prop-mask-del').addEventListener('click', function (e) { e.stopPropagation(); regions.splice(idx, 1); draw(); });
        el.addEventListener('pointerdown', function (e) {
          if (e.target.classList.contains('prop-mask-del')) return;
          var resize = e.target.classList.contains('prop-mask-handle');
          startDrag(e, idx, resize);
        });
        return el;
      }
      var drag = null;
      function startDrag(e, idx, resize) {
        e.preventDefault();
        var rect = canvas.getBoundingClientRect();
        drag = { idx: idx, resize: resize, startX: e.clientX, startY: e.clientY, orig: Object.assign({}, regions[idx]), cw: rect.width, ch: rect.height };
        canvas.setPointerCapture && canvas.setPointerCapture(e.pointerId);
      }
      canvas.addEventListener('pointermove', function (e) {
        if (!drag) return;
        var dx = (e.clientX - drag.startX) / drag.cw;
        var dy = (e.clientY - drag.startY) / drag.ch;
        var r = regions[drag.idx];
        if (drag.resize) {
          r.w = Math.max(0.03, Math.min(1 - drag.orig.x, drag.orig.w + dx));
          r.h = Math.max(0.02, Math.min(1 - drag.orig.y, drag.orig.h + dy));
        } else {
          r.x = Math.max(0, Math.min(1 - r.w, drag.orig.x + dx));
          r.y = Math.max(0, Math.min(1 - r.h, drag.orig.y + dy));
        }
        draw();
      });
      function endDrag() { drag = null; }
      canvas.addEventListener('pointerup', endDrag);
      canvas.addEventListener('pointercancel', endDrag);

      m.body.querySelector('#prop-mask-add').addEventListener('click', function () {
        regions.push({ x: 0.1, y: 0.4, w: 0.5, h: 0.12 }); draw();
      });
      m.body.querySelector('#prop-mask-cancel').addEventListener('click', m.close);
      m.body.querySelector('#prop-mask-save').addEventListener('click', function () {
        var btn = m.body.querySelector('#prop-mask-save'); btn.disabled = true; btn.innerHTML = '<span class="prop-spinner"></span> 作成中...';
        api('/flyer-mask.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_id: imageId, regions: regions }) })
          .then(function (r) {
            if (!r.success) { notify('error', r.message || '失敗しました'); btn.disabled = false; return; }
            m.close(); notify('ok', '顧客共有用のマスク済販売図面を作成しました'); refreshImages(p, 'flyer');
          }).catch(function () { notify('error', '通信に失敗しました'); btn.disabled = false; });
      });
      if (img.complete) draw(); else img.addEventListener('load', draw);
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

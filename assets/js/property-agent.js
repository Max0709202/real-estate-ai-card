/* 物件選定機能 エージェント管理画面ロジック（window.PropertyAgent）。
 * edit.php の担当連絡 詳細ビュー内 #property-panel に物件選定UIを描画する。
 * 依存: property-core.js（window.PropertyUI） */
(function (w) {
  'use strict';
  var UI = w.PropertyUI;
  var API = w.location.origin + '/backend/api/property';
  var P; // 現在のパネル要素
  var SID; // セッションID
  // 一覧の並び替え（''=既定のステータス順 / views_total / views_week / last_viewed）。
  // 画面を開いている間は選択を保持する。
  var SORT = '';
  // 直近に読み込んだ一覧（フォルダーの開閉・格納の再描画で使い回す）。
  var ITEMS = [];
  var FOLDERS = [];
  // フォルダーの開閉状態（フォルダーID → true=閉じている）。既定は開いた状態。
  var FOLDER_CLOSED = {};
  // フォルダー名の最大文字数（PHP propertyFolderNameMaxLength と一致）。
  var FOLDER_NAME_MAX = 100;

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
  /* 並び替えの選択肢（§3 要望）。閲覧状況は担当向けレスポンスにしか含まれないため担当一覧のみ。 */
  var SORT_OPTIONS = [
    ['', '既定（ステータス順）'],
    ['views_total', '累計閲覧回数が多い順'],
    ['views_week', '直近1週間の閲覧回数が多い順'],
    ['last_viewed', '最終閲覧日時が新しい順']
  ];
  function sortSelectHtml() {
    return '<select class="prop-sort" id="prop-sort" title="並び替え" aria-label="並び替え">' +
      SORT_OPTIONS.map(function (o) {
        return '<option value="' + o[0] + '"' + (SORT === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('') + '</select>';
  }

  function renderList() {
    P.innerHTML = '<div class="prop-toolbar"><h4>物件選定</h4>' + sortSelectHtml() +
      '<button type="button" class="prop-btn prop-btn--ghost" id="prop-folder-add">' + UI.icon('folder') + 'フォルダーを作成</button>' +
      '<button type="button" class="prop-btn prop-btn--primary" id="prop-add">' + UI.icon('plus') + '物件を追加</button></div>' +
      '<div class="prop-list-drop" id="prop-list-drop">' +
      '<div id="prop-list-body"><div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div></div>' +
      '<div class="prop-list-drop__hint">この枠に販売図面をドラッグ＆ドロップすると、まとめて登録できます（複数ファイル可）。</div>' +
      '</div>';
    P.querySelector('#prop-add').addEventListener('click', openAddMethods);
    bindListDrop(P.querySelector('#prop-list-drop'));
    P.querySelector('#prop-folder-add').addEventListener('click', function () { openFolderForm(null); });
    var sel = P.querySelector('#prop-sort');
    if (sel) sel.addEventListener('change', function () { SORT = sel.value; loadList(); });
    loadList();
  }

  /* 一覧の読み込み（並び替えはサーバー側で行う）。 */
  function loadList() {
    var body = P.querySelector('#prop-list-body');
    if (!body) return;
    body.innerHTML = '<div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div>';
    api('/list.php?session_id=' + encodeURIComponent(SID) + (SORT ? '&sort=' + encodeURIComponent(SORT) : '')).then(function (res) {
      if (!res.success) { body.innerHTML = '<div class="prop-empty">読み込みに失敗しました。</div>'; return; }
      ITEMS = res.data.properties || [];
      FOLDERS = res.data.folders || [];
      renderListBody();
    });
  }

  /* 一覧の描画。フォルダーを上に、フォルダーに入っていない物件をその下に表示する。
     views: true → お客様の閲覧状況（回数バッジ・累計/直近1週間/最終閲覧）を担当側の一覧にだけ表示する。
     folderMove: true → カードのフォルダーボタン（格納先の変更）を担当側の一覧にだけ表示する。 */
  function renderListBody() {
    var body = P.querySelector('#prop-list-body');
    if (!body) return;
    if (!ITEMS.length && !FOLDERS.length) {
      body.innerHTML = '<div class="prop-empty">提案物件はまだありません。「物件を追加」から登録してください。</div>';
      return;
    }
    var grouped = UI.groupByFolder(ITEMS, FOLDERS);
    var html = '';
    grouped.groups.forEach(function (g) {
      var actions = '<div class="prop-folder__actions">' +
        '<button type="button" class="prop-folder__act" data-folder-rename="' + esc(g.folder.id) + '" title="フォルダー名を変更">' + UI.icon('edit') + '</button>' +
        '<button type="button" class="prop-folder__act prop-folder__act--danger" data-folder-delete="' + esc(g.folder.id) + '" title="フォルダーを削除">' + UI.icon('trash') + '</button>' +
        '</div>';
      html += UI.folderSectionHtml(g.folder, cardsHtml(g.items), {
        open: !FOLDER_CLOSED[g.folder.id],
        count: g.items.length,
        actions: actions,
        empty: 'この中に物件はまだありません。物件カードのフォルダーボタンから格納できます。'
      });
    });
    if (grouped.ungrouped.length) {
      html += (grouped.groups.length ? '<div class="prop-folder-rest">フォルダーに入っていない物件</div>' : '') +
        cardsHtml(grouped.ungrouped);
    }
    body.innerHTML = html;
    bindListEvents(body);
  }

  function cardsHtml(items) {
    if (!items.length) return '';
    return '<div class="prop-list">' + items.map(function (p) {
      return UI.cardHtml(p, { views: true, folderMove: true });
    }).join('') + '</div>';
  }

  function bindListEvents(body) {
    // フォルダーの開閉（開閉状態は画面を開いている間だけ保持する）。
    body.querySelectorAll('[data-folder-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-folder-toggle');
        var sec = b.closest('.prop-folder');
        var open = !sec.classList.contains('is-open');
        sec.classList.toggle('is-open', open);
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        FOLDER_CLOSED[id] = !open;
      });
    });
    body.querySelectorAll('[data-folder-rename]').forEach(function (b) {
      b.addEventListener('click', function () { openFolderForm(findFolder(b.getAttribute('data-folder-rename'))); });
    });
    body.querySelectorAll('[data-folder-delete]').forEach(function (b) {
      b.addEventListener('click', function () { deleteFolder(findFolder(b.getAttribute('data-folder-delete'))); });
    });
    // フォルダーボタンはカード（詳細）を開かずに格納先を選ぶ。
    body.querySelectorAll('.prop-card__folder').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        openFolderPicker(findProperty(b.getAttribute('data-folder-move')));
      });
    });
    body.querySelectorAll('.prop-card').forEach(function (c) {
      c.addEventListener('click', function () { openDetail(parseInt(c.getAttribute('data-prop-id'), 10)); });
    });
  }

  function findFolder(id) {
    var found = null;
    FOLDERS.forEach(function (f) { if (String(f.id) === String(id)) found = f; });
    return found;
  }
  function findProperty(id) {
    var found = null;
    ITEMS.forEach(function (p) { if (String(p.id) === String(id)) found = p; });
    return found;
  }

  /* ===== 提案物件フォルダー =====
     ・「フォルダーを作成」で自分で名前を付けたフォルダーを作る（例: 2026年8月21日ご案内物件）
     ・物件カードのフォルダーボタンから、その物件の格納先を選ぶ
     ・一覧はフォルダーが上、フォルダーに入っていない物件がその下（お客様の画面も同じ並び）
     ・フォルダーを削除しても中の物件は削除されず、フォルダーの外に戻る */

  /* フォルダーの作成・名前変更。
     onCreated を渡すと、作成後に一覧を読み直す代わりにそのフォルダーを引数に呼ぶ。 */
  function openFolderForm(folder, onCreated) {
    var isEdit = !!folder;
    var html = '<div class="prop-field full"><label>フォルダー名</label>' +
      '<input type="text" id="prop-folder-name" maxlength="' + FOLDER_NAME_MAX + '"' +
      ' placeholder="例）2026年8月21日ご案内物件" value="' + esc(isEdit ? folder.name : '') + '"></div>' +
      '<div class="prop-msg prop-msg--info">フォルダーはお客様の物件選定画面にも同じ名前で表示されます。</div>' +
      '<div class="prop-form-actions"><button type="button" class="prop-btn prop-btn--primary" id="prop-folder-save">' +
      (isEdit ? '変更する' : '作成する') + '</button></div>';
    var m = UI.modal(isEdit ? 'フォルダー名を変更' : 'フォルダーを作成', html);
    var input = m.body.querySelector('#prop-folder-name');
    var btn = m.body.querySelector('#prop-folder-save');

    function submit() {
      var name = input.value.trim();
      if (!name) { notify('error', 'フォルダー名を入力してください'); return; }
      var payload = isEdit
        ? { action: 'rename', folder_id: folder.id, name: name }
        : { action: 'create', session_id: SID, name: name };
      btn.disabled = true;
      api('/folder.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (res) {
          btn.disabled = false;
          if (!res.success) { notify('error', res.message || '保存に失敗しました'); return; }
          m.close();
          notify('ok', res.message);
          FOLDERS = res.data.folders || FOLDERS;
          if (!isEdit && onCreated) onCreated(res.data.folder);
          else loadList();
        })
        .catch(function () { btn.disabled = false; notify('error', '通信に失敗しました'); });
    }
    btn.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    input.focus();
  }

  function deleteFolder(folder) {
    if (!folder) return;
    if (!confirm('フォルダー「' + folder.name + '」を削除します。\n中の物件は削除されず、フォルダーの外に戻ります。よろしいですか？')) return;
    api('/folder.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', folder_id: folder.id })
    }).then(function (res) {
      if (!res.success) { notify('error', res.message || '削除に失敗しました'); return; }
      notify('ok', res.message);
      delete FOLDER_CLOSED[folder.id];
      loadList();
    }).catch(function () { notify('error', '通信に失敗しました'); });
  }

  /* 物件の格納先フォルダーを選ぶ。
     onMoved を渡すと、移動後に一覧を読み直す代わりに（folder_id, folder）を引数に呼ぶ。 */
  function openFolderPicker(p, onMoved) {
    if (!p) return;
    var current = p.folder_id == null ? '' : String(p.folder_id);
    var rows = FOLDERS.map(function (f) {
      var on = current === String(f.id);
      return '<button type="button" class="prop-folder-opt' + (on ? ' is-selected' : '') + '" data-pick="' + esc(f.id) + '">' +
        '<span class="prop-folder-opt__icon">' + UI.icon(on ? 'folderFilled' : 'folder') + '</span>' +
        '<span class="prop-folder-opt__name">' + esc(f.name) + '</span>' +
        '<span class="prop-folder-opt__count">' + esc(f.property_count || 0) + '</span></button>';
    }).join('');
    if (current) {
      rows += '<button type="button" class="prop-folder-opt" data-pick="0">' +
        '<span class="prop-folder-opt__icon">' + UI.icon('folder') + '</span>' +
        '<span class="prop-folder-opt__name">フォルダーから出す</span></button>';
    }
    if (!FOLDERS.length) rows = '<div class="prop-empty">フォルダーがまだありません。新しく作成してください。</div>';

    var m = UI.modal('フォルダーに格納',
      '<div class="prop-folder-picker">' + rows + '</div>' +
      '<div class="prop-form-actions"><button type="button" class="prop-btn prop-btn--ghost" id="prop-folder-new">' +
      UI.icon('plus') + '新しいフォルダーを作成</button></div>');

    m.body.querySelectorAll('[data-pick]').forEach(function (b) {
      b.addEventListener('click', function () {
        moveToFolder(p, parseInt(b.getAttribute('data-pick'), 10) || 0, m, onMoved);
      });
    });
    // 作成したフォルダーへそのまま格納する。
    m.body.querySelector('#prop-folder-new').addEventListener('click', function () {
      m.close();
      openFolderForm(null, function (folder) {
        if (folder && folder.id) moveToFolder(p, folder.id, null, onMoved);
        else loadList();
      });
    });
  }

  function moveToFolder(p, folderId, m, onMoved) {
    api('/folder-move.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: p.id, folder_id: folderId })
    }).then(function (res) {
      if (m) m.close();
      if (!res.success) { notify('error', res.message || '変更に失敗しました'); return; }
      notify('ok', res.message);
      p.folder_id = res.data.folder_id;
      if (onMoved) onMoved(res.data.folder_id);
      else loadList();
    }).catch(function () { if (m) m.close(); notify('error', '通信に失敗しました'); });
  }

  /* ===== 登録方法選択（§2） ===== */
  function openAddMethods() {
    var html = '<div class="prop-method-list">' +
      method('upload', 'upload', '販売図面をアップロード', '1件の物件として登録します（複数ページの図面もまとめて1件）') +
      method('bulk', 'upload', '複数の物件をまとめて登録', '販売図面を複数選ぶと、1枚ずつ別の物件として一括登録します') +
      method('photo', 'camera', '写真を撮影して登録', 'その場で撮影してAIが物件情報を読み取り') +
      method('manual', 'manual', '手入力で登録', '販売図面がない場合など手入力で登録します') +
      method('url', 'url', '物件URLから登録', 'SUUMO・HOME\'S・アットホーム等のURLから自動取得') +
      '</div>';
    var m = UI.modal('提案物件追加', html);
    m.body.querySelector('[data-method="upload"]').addEventListener('click', function () { m.close(); pickFlyer(false); });
    m.body.querySelector('[data-method="bulk"]').addEventListener('click', function () { m.close(); pickFlyersBulk(); });
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

  /* ===== 複数物件の一括登録（改善要望 2-3） =====
     販売図面を複数まとめて選ぶ／ドロップすると、1ファイル＝1物件として順番に登録する。
     AI解析は1件ずつサーバーに投げる（同時に走らせると解析が詰まるため）。
     途中で失敗したファイルがあっても止めず、最後にまとめて結果を知らせる。 */
  var BULK_MAX = 20;               // 一度に登録できる販売図面の数

  function pickFlyersBulk() {
    var inp = document.createElement('input');
    inp.type = 'file'; inp.accept = 'image/*,application/pdf'; inp.multiple = true;
    inp.addEventListener('change', function () {
      if (inp.files && inp.files.length) bulkAddFlyers(inp.files);
    });
    inp.click();
  }

  /* ファイル配列を1件ずつ登録する。進み具合を出しながら順番に処理する。 */
  function bulkAddFlyers(fileList) {
    var files = Array.prototype.slice.call(fileList || []);
    if (!files.length) return;
    if (files.length === 1) {
      // 1件だけなら、これまでどおり内容を確認する画面を開く。
      uploadSingleFlyer(files[0]);
      return;
    }
    var over = false;
    if (files.length > BULK_MAX) { files = files.slice(0, BULK_MAX); over = true; }

    var total = files.length;
    var m = UI.modal('複数の物件を登録中', '<div class="prop-empty"><span class="prop-spinner"></span> <span id="prop-bulk-progress">0 / ' + total + ' 件</span><br>販売図面を1件ずつAIが読み取っています。この画面は閉じないでください。</div>');
    var okCount = 0;
    var failed = [];

    function step(i) {
      if (i >= total) { finish(); return; }
      var label = m.body.querySelector('#prop-bulk-progress');
      if (label) label.textContent = (i + 1) + ' / ' + total + ' 件';
      var fd = new FormData();
      fd.append('session_id', SID);
      fd.append('files[]', files[i]);
      api('/analyze.php', { method: 'POST', body: fd }).then(function (res) {
        if (res && res.success) okCount++;
        else failed.push(files[i].name || ('' + (i + 1) + '件目'));
        step(i + 1);
      }).catch(function () {
        failed.push(files[i].name || ('' + (i + 1) + '件目'));
        step(i + 1);
      });
    }

    function finish() {
      m.close();
      loadList();
      if (okCount) {
        notify('ok', okCount + '件の物件を登録しました。内容を確認して保存してください。');
      }
      if (failed.length) {
        notify('error', failed.length + '件を登録できませんでした（' + failed.slice(0, 3).join('、') + (failed.length > 3 ? ' ほか' : '') + '）');
      }
      if (over) {
        notify('error', '一度に登録できるのは' + BULK_MAX + '件までです。残りは改めてお試しください。');
      }
    }

    step(0);
  }

  /* 1件だけ選ばれたとき（＝これまでと同じ流れ）。 */
  function uploadSingleFlyer(file) {
    var fd = new FormData();
    fd.append('session_id', SID);
    fd.append('files[]', file);
    var m = UI.modal('AI読み取り中', '<div class="prop-empty"><span class="prop-spinner"></span> 販売図面を解析しています…<br>少々お待ちください。</div>');
    api('/analyze.php', { method: 'POST', body: fd }).then(function (res) {
      m.close();
      if (!res.success) { notify('error', res.message || '解析に失敗しました'); return; }
      if (res.data.ocr_error) notify('error', res.data.ocr_error);
      openEditForm(res.data.property, true);
    }).catch(function () { m.close(); notify('error', '通信に失敗しました'); });
  }

  /* 一覧に販売図面をドラッグ＆ドロップしたときも、まとめて登録する。 */
  function bindListDrop(zone) {
    if (!zone) return;
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
        e.preventDefault(); e.stopPropagation();
        zone.classList.add('is-over');
      });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        if (e.target !== zone) return;
        zone.classList.remove('is-over');
      });
    });
    zone.addEventListener('drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      e.preventDefault(); e.stopPropagation();
      zone.classList.remove('is-over');
      bulkAddFlyers(e.dataTransfer.files);
    });
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
      savePropertyWithDuplicateCheck(payload, m, btn, prop);
    });
  }

  // 保存API呼び出し。同じお客様へ同じ物件を過去に提案済みの場合、サーバーは
  // 保存せず duplicate=true を返す。その時は確認ダイアログを挟み、「再提案する」で
  // confirm_duplicate=true を付けて再送する（§ 追加要望：エラーにはせず確認後に続行）。
  function savePropertyWithDuplicateCheck(payload, m, btn, prop) {
    api('/save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (res) {
        if (res && res.success && res.data && res.data.duplicate) {
          btn.disabled = false;
          showDuplicateConfirm(res.data, payload, m, btn, prop);
          return;
        }
        if (!res || !res.success) { notify('error', (res && res.message) || '保存に失敗しました'); btn.disabled = false; return; }
        m.close(); notify('ok', '保存しました');
        if (prop.id && P.querySelector('#prop-detail')) openDetail(prop.id); else renderList();
      }).catch(function () { notify('error', '通信に失敗しました'); btn.disabled = false; });
  }

  // 重複提案の確認ダイアログ。前回提案日・前回提案価格・現在価格を表示し、
  // 価格が変わっている場合はその旨を強調する。
  function showDuplicateConfirm(info, payload, m, btn, prop) {
    var prev = info.previous || {};
    var cur = info.current || {};
    var rows = '';
    if (prev.date_label) rows += '<div>前回提案日：' + esc(prev.date_label) + '</div>';
    if (prev.price_text) rows += '<div>前回提案価格：' + esc(prev.price_text) + '</div>';
    if (cur.price_text) {
      rows += '<div>現在価格：' + esc(cur.price_text) +
        (info.price_changed ? ' <span style="color:#d9534f;font-weight:600;">（価格が変更されています）</span>' : '') + '</div>';
    }
    var html = '<div class="prop-msg prop-msg--info full">この物件は、すでに同じお客様へ提案済みです。</div>' +
      (rows ? '<div style="margin:12px 0;line-height:1.9;">' + rows + '</div>' : '') +
      '<div style="margin-bottom:4px;">価格変更などの理由で、再度提案しますか？</div>' +
      '<div class="prop-form-actions" style="margin-top:14px;">' +
        '<button type="button" class="prop-btn prop-btn--ghost" id="prop-dup-cancel">キャンセル</button>' +
        '<button type="button" class="prop-btn prop-btn--primary" id="prop-dup-ok">再提案する</button>' +
      '</div>';
    var cm = UI.modal('提案済みの物件です', html);
    // キャンセル: 確認ダイアログを閉じ、物件選定（入力）画面に戻る（入力内容は保持）。
    cm.body.querySelector('#prop-dup-cancel').addEventListener('click', function () { cm.close(); });
    // 再提案する: confirm_duplicate を付けてそのまま登録を続行する。
    cm.body.querySelector('#prop-dup-ok').addEventListener('click', function () {
      cm.close();
      btn.disabled = true;
      var p2 = {};
      for (var k in payload) { if (Object.prototype.hasOwnProperty.call(payload, k)) p2[k] = payload[k]; }
      p2.confirm_duplicate = true;
      savePropertyWithDuplicateCheck(p2, m, btn, prop);
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

    // 現在の格納先フォルダー（未格納なら「フォルダー」と表示し、押すと格納先を選べる）。
    var curFolder = findFolder(p.folder_id);
    d.innerHTML =
      '<div class="prop-toolbar"><button type="button" class="prop-btn prop-btn--ghost" id="prop-back">← 一覧</button>' +
      '<button type="button" class="prop-btn prop-btn--ghost" id="prop-folder-btn" title="フォルダーに格納">' +
        UI.icon(curFolder ? 'folderFilled' : 'folder') + esc(curFolder ? curFolder.name : 'フォルダー') + '</button>' +
      '<button type="button" class="prop-btn prop-btn--ghost" id="prop-edit-btn">' + UI.icon('edit') + '編集</button>' +
      '<button type="button" class="prop-btn prop-btn--danger" id="prop-del-btn">' + UI.icon('trash') + '削除</button></div>' +
      UI.detailHeaderHtml(p) +
      UI.passReasonBlockHtml(p, { withAI: true }) +
      '<div class="prop-pr" id="prop-pr"></div>' +
      '<div class="prop-section-title">対応ステータス（エージェント）</div>' +
      '<div class="prop-status-grid" id="prop-agent-status">' + statusChips + '</div>' +
      '<div class="prop-tabs">' +
        '<button class="prop-tab is-active" data-tab="basic">基本情報</button>' +
        '<button class="prop-tab" data-tab="hazard">ハザード等情報</button>' +
        '<button class="prop-tab" data-tab="flyer">販売図面</button>' +
        '<button class="prop-tab" data-tab="photo">写真・資料</button>' +
        '<button class="prop-tab" data-tab="document">追加資料</button>' +
        '<button class="prop-tab" data-tab="map">マップ</button>' +
      '</div>' +
      '<div class="prop-tabpane is-active" data-pane="basic">' + UI.basicInfoHtml(p, true) + '</div>' +
      '<div class="prop-tabpane" data-pane="hazard"></div>' +
      '<div class="prop-tabpane" data-pane="flyer"></div>' +
      '<div class="prop-tabpane" data-pane="photo"></div>' +
      '<div class="prop-tabpane" data-pane="document"></div>' +
      '<div class="prop-tabpane" data-pane="map"></div>';

    // マップは詳細を開き直すたびに描き直す（前回の物件の地図が残らないようにする）。
    MAP_PROPERTY_ID = null;

    // PRコメント（お客様へ届ける紹介文）。AI生成・ブラッシュアップ前の下書き状態は
    // 詳細を開き直したらリセットする。
    PR_AI_DRAFT = null; PR_POLISH_DRAFT = null;
    renderPr(p, { editing: false });

    d.querySelector('#prop-back').addEventListener('click', renderList);
    // 詳細からも格納先フォルダーを変更できる（変更後は詳細をそのまま描き直す）。
    d.querySelector('#prop-folder-btn').addEventListener('click', function () {
      openFolderPicker(p, function (folderId) { p.folder_id = folderId; renderDetail(p); });
    });
    d.querySelector('#prop-edit-btn').addEventListener('click', function () { openEditForm(p, false); });
    d.querySelector('#prop-del-btn').addEventListener('click', function () {
      if (!confirm('この物件を削除します。よろしいですか？')) return;
      api('/delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id }) })
        .then(function (res) { if (res.success) { notify('ok', '削除しました'); renderList(); } else notify('error', res.message || '削除に失敗'); });
    });
    // 顧客が付けた「見送り」バッジを押すと、見送り理由を表示する（§5）。
    d.querySelectorAll('[data-pass-reason]').forEach(function (el) {
      el.addEventListener('click', function () { UI.showPassReason(p); });
    });
    // 見送り理由のAI所見が未生成なら、この画面を開いたときに生成して掲出する。
    if (p.status === 'passed' && d.querySelector('[data-ai-pending]')) {
      api('/pass-reason-ai.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id }) })
        .then(function (res) {
          var block = d.querySelector('#prop-reason-block');
          if (!block) return;
          if (res && res.success && res.data && res.data.pass_reason_ai) {
            p.pass_reason_ai = res.data.pass_reason_ai;
            block.outerHTML = UI.passReasonBlockHtml(p, { withAI: true });
          } else {
            var ph = d.querySelector('[data-ai-pending] .prop-reason-view__ai-body');
            if (ph) ph.textContent = 'AI所見は生成できませんでした。';
          }
        })
        .catch(function () {
          var ph = d.querySelector('[data-ai-pending] .prop-reason-view__ai-body');
          if (ph) ph.textContent = 'AI所見の生成に失敗しました。';
        });
    }
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
        if (name === 'document') loadImages(p, 'document');
        if (name === 'map') loadMap(p);
      });
    });
    UI.bindLightbox(d);
  }

  /* ===== PRコメント（物件提案時にお客様へ届ける営業コメント） =====
     ・営業担当者が最初から自分で入力できる（追加・編集・削除）
     ・「AIでPRコメントを生成」で下書きを作り、そのまま入力欄で加筆・修正できる
     ・「AIで再生成」で別の切り口の下書きに作り直せる
     ・「AIでブラッシュアップ」で、自分で書いた文章の誤字・言い回しだけを整えられる
       （内容は変えない。結果はプレビューで確認し、採用したときだけ入力欄に反映する）
     ・保存するのは担当者が確認・編集したあとの文章だけ（AIの出力は自動保存しない）
     AI側は①住戸固有 ②マンション全体 ③立地・周辺環境 を分析して訴求ポイントを選び、
     語る軸・順番・書き出しまで判断する。選ばれたポイントは入力欄の下に表示する。 */
  var PR_MAX = 1000;                 // 保存できる最大文字数（PHP propertyPrCommentMaxLength と一致）
  var PR_SOURCE_LABELS = { manual: '手入力', ai: 'AI生成', ai_edited: 'AI生成を編集', ai_polished: 'AIでブラッシュアップ' };
  var PR_FOCUS_LABELS = { unit: '住戸', building: 'マンション', location: '立地' };
  var PR_CATEGORY_LABELS = { unit: '住戸', building: '建物', location: '立地' };
  var PR_AI_DRAFT = null;            // 直近にAIが生成した文章（保存時の入力方法の判定に使う）
  var PR_POLISH_DRAFT = null;        // 直近にブラッシュアップを採用した文章（同上）

  function prHost() { return P ? P.querySelector('#prop-pr') : null; }

  /* AIが選んだ訴求ポイント（生成直後のみ表示。担当者が根拠を確認して編集できるようにする） */
  function prPlanHtml(state) {
    if (!state.points || !state.points.length) return '';
    var focus = PR_FOCUS_LABELS[state.focus];
    return '<div class="prop-pr__plan">' +
      '<div class="prop-pr__plan-head">AIが選んだ訴求ポイント' + (focus ? '（' + esc(focus) + 'を軸に構成）' : '') + '</div>' +
      '<ol class="prop-pr__plan-list">' +
        state.points.map(function (pt) {
          var cat = PR_CATEGORY_LABELS[pt.category];
          return '<li>' + (cat ? '<span class="prop-pr__plan-cat">' + esc(cat) + '</span>' : '') + esc(pt.point) + '</li>';
        }).join('') +
      '</ol></div>';
  }

  /* ブラッシュアップ結果のプレビュー（担当連絡チャットの推敲と同じく、採用するまで入力欄は書き換えない） */
  function prPolishHtml(state) {
    if (!state.polish) return '';
    return '<div class="prop-pr__polish">' +
      '<div class="prop-pr__polish-head">ブラッシュアップ結果プレビュー' +
        '<span class="prop-pr__polish-len">' + state.polish.length + '字</span></div>' +
      '<div class="prop-pr__polish-body">' + esc(state.polish) + '</div>' +
      '<div class="prop-pr__polish-actions">' +
        '<button type="button" class="prop-btn prop-btn--primary" data-pr="polish-apply">この文章に置き換える</button>' +
        '<button type="button" class="prop-btn prop-btn--ghost" data-pr="polish-close">閉じる</button>' +
      '</div></div>';
  }

  /* state: { editing: 編集中か, draft: 入力欄に表示する文章（未指定なら保存済みの文章）,
             points/focus: AIが選んだ訴求ポイント（生成直後のみ）,
             polish: ブラッシュアップ結果（採用・閉じるまで表示） } */
  function renderPr(p, state) {
    var host = prHost();
    if (!host) return;
    state = state || {};
    var saved = p.pr_comment || '';
    var text = state.editing && state.draft != null ? state.draft : saved;
    var head = '<div class="prop-pr__head">' + UI.icon('manual') + 'PRコメント' +
      '<span class="prop-pr__note">保存するとお客様の物件詳細に表示されます</span></div>';
    var html;

    if (state.editing) {
      html = head +
        '<textarea class="prop-pr__input" id="prop-pr-input" rows="9" maxlength="' + PR_MAX + '" ' +
          'placeholder="お客様へ届けるPRコメントを入力してください（250〜350字程度）。「AIでPRコメントを生成」で下書きを作ることも、書いた文章を「AIでブラッシュアップ」で整えることもできます。">' +
          esc(text) + '</textarea>' +
        '<div class="prop-pr__count" id="prop-pr-count"></div>' +
        prPlanHtml(state) +
        prPolishHtml(state) +
        '<div class="prop-pr__actions">' +
          '<button type="button" class="prop-btn prop-btn--ghost" data-pr="gen">' + UI.icon('refresh') +
            (text.trim() ? 'AIで再生成' : 'AIでPRコメントを生成') + '</button>' +
          '<button type="button" class="prop-btn prop-btn--ghost" data-pr="polish">' + UI.icon('edit') +
            'AIでブラッシュアップ</button>' +
          '<button type="button" class="prop-btn prop-btn--primary" data-pr="save">保存</button>' +
          '<button type="button" class="prop-btn prop-btn--ghost" data-pr="cancel">キャンセル</button>' +
        '</div>';
    } else if (text.trim()) {
      var meta = [];
      if (PR_SOURCE_LABELS[p.pr_comment_source]) meta.push(PR_SOURCE_LABELS[p.pr_comment_source]);
      meta.push(text.length + '字');
      if (p.pr_comment_updated_at) meta.push(UI.formatDate(p.pr_comment_updated_at) + ' 更新');
      html = head +
        '<div class="prop-pr__body">' + esc(text) + '</div>' +
        '<div class="prop-pr__meta">' + esc(meta.join('　/　')) + '</div>' +
        '<div class="prop-pr__actions">' +
          '<button type="button" class="prop-btn prop-btn--ghost" data-pr="edit">' + UI.icon('edit') + '編集</button>' +
          '<button type="button" class="prop-btn prop-btn--danger" data-pr="del">' + UI.icon('trash') + '削除</button>' +
        '</div>';
    } else {
      html = head +
        '<div class="prop-pr__empty">PRコメントはまだ登録されていません。</div>' +
        '<div class="prop-pr__actions">' +
          '<button type="button" class="prop-btn prop-btn--primary" data-pr="edit">' + UI.icon('plus') + 'コメントを入力</button>' +
          '<button type="button" class="prop-btn prop-btn--ghost" data-pr="gen">' + UI.icon('refresh') + 'AIでPRコメントを生成</button>' +
        '</div>';
    }

    host.innerHTML = html;
    bindPr(p, state);
  }

  function bindPr(p, state) {
    var host = prHost();
    if (!host) return;
    var input = host.querySelector('#prop-pr-input');
    var count = host.querySelector('#prop-pr-count');

    function updateCount() {
      if (!input || !count) return;
      var n = input.value.length;
      var off = n > 0 && (n < 250 || n > 350);
      count.innerHTML = '<span class="prop-pr__count-num' + (off ? ' is-off' : '') + '">' + n + '</span>字　/　目安 250〜350字';
    }
    if (input) { updateCount(); input.addEventListener('input', updateCount); }

    host.querySelectorAll('[data-pr]').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.getAttribute('data-pr');
        if (act === 'edit') {
          renderPr(p, { editing: true, draft: p.pr_comment || '' });
          var i = prHost().querySelector('#prop-pr-input');
          if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); }
          return;
        }
        if (act === 'cancel') { renderPr(p, { editing: false }); return; }
        if (act === 'gen') { prGenerate(p, input ? input.value : '', !!input); return; }
        if (act === 'polish') { prPolish(p, input ? input.value : ''); return; }
        // 採用＝入力欄を推敲後の文章に置き換える／閉じる＝入力中の文章を保ったままプレビューだけ消す。
        if (act === 'polish-apply') {
          PR_POLISH_DRAFT = state.polish;
          renderPr(p, { editing: true, draft: state.polish, points: state.points, focus: state.focus });
          return;
        }
        if (act === 'polish-close') {
          renderPr(p, { editing: true, draft: input ? input.value : '', points: state.points, focus: state.focus });
          return;
        }
        if (act === 'save') { prSave(p, input ? input.value : ''); return; }
        if (act === 'del') { prDelete(p); return; }
      });
    });
  }

  /* AI生成（保存はしない）。入力欄に文章があるときは「再生成」として前回分を避けた文章を作る。
     wasEditing: 生成を押した時点で編集中だったか（失敗時に元の状態へ戻すため）。 */
  function prGenerate(p, current, wasEditing) {
    var host = prHost();
    if (!host) return;
    host.querySelectorAll('[data-pr]').forEach(function (b) { b.disabled = true; });
    var btn = host.querySelector('[data-pr="gen"]');
    // 分析→執筆→点検（似すぎ・定型表現なら書き直し）と複数回やり取りするため少し時間がかかる。
    if (btn) btn.innerHTML = '<span class="prop-spinner"></span> 分析・作成中...';

    api('/pr-comment-ai.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: p.id, current: current || '' })
    }).then(function (res) {
      if (!res.success || !res.data || !res.data.pr_comment) {
        notify('error', (res && res.message) || 'PRコメントを生成できませんでした');
        renderPr(p, wasEditing ? { editing: true, draft: current || '' } : { editing: false });
        return;
      }
      // 生成結果は入力欄に表示するだけ。担当者が確認・編集して「保存」を押すまで保存しない。
      PR_AI_DRAFT = res.data.pr_comment;
      renderPr(p, {
        editing: true, draft: res.data.pr_comment,
        points: res.data.points || [], focus: res.data.focus || ''
      });
      notify('ok', 'PRコメントを生成しました。内容をご確認・編集のうえ保存してください。');
    }).catch(function () {
      notify('error', '通信に失敗しました');
      renderPr(p, wasEditing ? { editing: true, draft: current || '' } : { editing: false });
    });
  }

  /* ブラッシュアップ（保存はしない）。担当者が書いた文章の内容は変えず、誤字・言い回しだけ整える。
     結果はプレビューに表示し、「この文章に置き換える」を押したときだけ入力欄へ反映する。 */
  function prPolish(p, current) {
    var text = (current || '').trim();
    if (!text) { notify('error', 'ブラッシュアップする文章を入力してください'); return; }
    if (text.length > PR_MAX) { notify('error', 'PRコメントは' + PR_MAX + '字以内で入力してください'); return; }
    var host = prHost();
    if (!host) return;
    host.querySelectorAll('[data-pr]').forEach(function (b) { b.disabled = true; });
    var btn = host.querySelector('[data-pr="polish"]');
    if (btn) btn.innerHTML = '<span class="prop-spinner"></span> 推敲中...';

    api('/pr-comment-polish.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: p.id, text: current })
    }).then(function (res) {
      if (!res.success || !res.data || !res.data.pr_comment) {
        notify('error', (res && res.message) || 'ブラッシュアップできませんでした');
        renderPr(p, { editing: true, draft: current || '' });
        return;
      }
      renderPr(p, { editing: true, draft: current || '', polish: res.data.pr_comment });
      notify('ok', 'ブラッシュアップしました。内容をご確認のうえ、採用する場合は入力欄へ反映してください。');
    }).catch(function () {
      notify('error', '通信に失敗しました');
      renderPr(p, { editing: true, draft: current || '' });
    });
  }

  function prSave(p, value) {
    var text = (value || '').trim();
    if (!text) { notify('error', 'PRコメントを入力してください'); return; }
    if (text.length > PR_MAX) { notify('error', 'PRコメントは' + PR_MAX + '字以内で入力してください'); return; }
    // 入力方法（担当画面の表示用）: AI生成をそのまま／AIでブラッシュアップした文章をそのまま／
    // AIの文章を編集して保存したか、手入力か。
    var source = 'manual';
    if (PR_AI_DRAFT != null && text === PR_AI_DRAFT.trim()) source = 'ai';
    else if (PR_POLISH_DRAFT != null && text === PR_POLISH_DRAFT.trim()) source = 'ai_polished';
    else if (PR_AI_DRAFT != null || PR_POLISH_DRAFT != null) source = 'ai_edited';
    var host = prHost();
    if (host) {
      host.querySelectorAll('[data-pr]').forEach(function (b) { b.disabled = true; });
      var sb = host.querySelector('[data-pr="save"]');
      if (sb) sb.innerHTML = '<span class="prop-spinner"></span> 保存中...';
    }
    api('/pr-comment.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: p.id, pr_comment: text, source: source })
    }).then(function (res) {
      if (!res.success) {
        notify('error', res.message || '保存に失敗しました');
        renderPr(p, { editing: true, draft: text });
        return;
      }
      p.pr_comment = res.data.pr_comment;
      p.pr_comment_source = res.data.pr_comment_source;
      p.pr_comment_updated_at = res.data.pr_comment_updated_at;
      PR_AI_DRAFT = null; PR_POLISH_DRAFT = null;
      renderPr(p, { editing: false });
      notify('ok', 'PRコメントを保存しました');
    }).catch(function () {
      notify('error', '通信に失敗しました');
      renderPr(p, { editing: true, draft: text });
    });
  }

  function prDelete(p) {
    if (!confirm('PRコメントを削除します。よろしいですか？')) return;
    api('/pr-comment.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: p.id, action: 'delete' })
    }).then(function (res) {
      if (!res.success) { notify('error', res.message || '削除に失敗しました'); return; }
      p.pr_comment = null; p.pr_comment_source = null; p.pr_comment_updated_at = null;
      PR_AI_DRAFT = null; PR_POLISH_DRAFT = null;
      renderPr(p, { editing: false });
      notify('ok', 'PRコメントを削除しました');
    }).catch(function () { notify('error', '通信に失敗しました'); });
  }

  /* ハザード（§12/§13） */
  /* ===== マップ・周辺情報（マップ情報表示依頼 2026.9.3） =====
     「マップ」タブを開いた時点で初めて描画する。この時点では現在の物件・検討中物件・
     Googleマップだけを表示し、Places API は呼ばない（§11）。 */
  var MAP_PROPERTY_ID = null;   // 現在マップを描画済みの物件ID（タブを行き来しても作り直さない）
  function loadMap(p) {
    var pane = P.querySelector('[data-pane="map"]');
    if (!pane) return;
    if (MAP_PROPERTY_ID === p.id) return;
    if (!w.PropertyMap) { pane.innerHTML = '<div class="prop-empty">マップを表示できません。</div>'; return; }
    MAP_PROPERTY_ID = p.id;
    w.PropertyMap.mount(pane, {
      propertyId: p.id,
      apiBase: API,
      authQS: '',                 // 担当はログインセッション（Cookie）で認証する
      credentials: 'include',
      // 検討中物件の吹き出しから、その物件の詳細へ移動する（§3）。
      onOpenProperty: function (id) { openDetail(id); }
    });
  }

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
     写真・資料は販売図面アップロード時にAIが自動抽出・分類して登録する。
     加えて担当者が手動で追加でき（ファイル選択・ドラッグ＆ドロップ・貼り付け）、
     写真の名前（建物外観など）の変更と、一覧サムネイルに使う写真の指定ができる。
     誤抽出された写真は、各写真の削除ボタンで個別に削除できる。 */
  var PHOTO_MAX = 10;              // 「写真・資料」の上限枚数（PHP image-upload.php と一致）
  var PHOTO_LABEL_MAX = 30;        // 写真の名前の最大文字数（PHP propertyPhotoLabelMaxLength と一致）
  var PHOTO_LABELS = ['建物外観', '間取り図', '室内写真', '設備写真', '地図', 'その他'];
  var DOC_MAX = 10;                // 「追加資料」の上限件数（PHP image-upload.php と一致）
  var DOC_LABELS = ['管理規約', '重要事項説明書', 'マンション概要', '長期修繕計画', '周辺情報', 'その他資料'];
  var PASTE_BOUND = null;          // 貼り付け（Ctrl+V / ⌘V）用のドキュメントリスナー

  function loadImages(p, category) {
    var pane = P.querySelector('[data-pane="' + category + '"]');
    if (category === 'photo') {
      pane.innerHTML = '<div class="prop-msg prop-msg--info" style="margin-top:8px">販売図面のアップロード時に、AIが建物外観・間取り図・室内・設備・地図を自動で抽出し登録します（最大' + PHOTO_MAX + '枚）。会社情報を含む画像は登録されません。建物外観の写真が無い場合は抽出されません。誤って抽出された写真は、各写真の削除ボタンで削除できます。</div>' +
        '<div class="prop-msg prop-msg--info">お客様の物件一覧に表示するサムネイル写真は、<b>下の写真をクリック</b>すると変更できます。使える写真が無い場合は、下の枠から写真・資料を追加してください。<b>写真の下のコメント（間取り図・建物外観など）は、その文字をクリックするといつでも変更できます。</b></div>' +
        photoUploaderHtml() +
        '<div id="prop-img-body"><div class="prop-empty"><span class="prop-spinner"></span></div></div>';
      bindPhotoUploader(pane, p);
      refreshImages(p, category);
      return;
    }
    if (category === 'document') {
      // 追加資料: 物件に紐づく参考資料（管理規約・重要事項説明書・周辺情報など）。
      // 販売図面のようなマスク処理は行わず、登録したものがそのままお客様にも表示される。
      pane.innerHTML = '<div class="prop-msg prop-msg--info" style="margin-top:8px">この物件に関連する追加資料を登録できます（最大' + DOC_MAX + '件）。登録した資料は、お客様の物件詳細の「追加資料」にそのまま表示されます。<b>販売図面のような売主情報の自動マスクは行われません</b>ので、お客様にお渡ししてよい資料かをご確認のうえ登録してください。</div>' +
        documentUploaderHtml() +
        '<div id="prop-img-body"><div class="prop-empty"><span class="prop-spinner"></span></div></div>';
      bindDocumentUploader(pane, p);
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
      if (category === 'document') {
        var docs = prop.documents || [];
        p.documents = docs;
        renderDocumentList(p, body, docs);
        return;
      }
      // 写真・資料: 分類ラベル付き。写真をクリックすると一覧サムネイルに設定できる。
      //             誤抽出時は写真ごとに削除でき、名前は鉛筆ボタンから変更できる。
      var photos = prop.photos || [];
      // 一覧に戻ったときのために、詳細側の保持データも最新の状態にそろえておく。
      p.photos = photos;
      p.thumbnail_image_id = prop.thumbnail_image_id || null;
      p.main_image_url = prop.main_image_url || null;
      updatePhotoUploaderState(photos.length);
      if (!photos.length) {
        body.innerHTML = '<div class="prop-empty">写真・資料はまだありません。販売図面をアップロードするとAIが抽出した写真が表示されます。上の枠から手動で追加することもできます。</div>';
        return;
      }
      var thumbId = prop.thumbnail_image_id ? parseInt(prop.thumbnail_image_id, 10) : 0;
      // 未指定のときは先頭の写真（画像）が自動でサムネイルになる（PHP propertySerialize と同じ判定）。
      var autoId = 0;
      photos.forEach(function (x) {
        if (autoId) return;
        if (!x.mime_type || x.mime_type.indexOf('image/') === 0) autoId = parseInt(x.id, 10);
      });
      body.innerHTML = '<div class="prop-gallery">' + photos.map(function (im) {
        var url = UI.addAuth(im.url, {});
        // PDF等の資料はサムネイルに使えないため、クリックでの指定対象から外す。
        var isImg = !im.mime_type || im.mime_type.indexOf('image/') === 0;
        var isThumb = isImg && (thumbId ? (parseInt(im.id, 10) === thumbId) : (parseInt(im.id, 10) === autoId));
        // サムネイルの印は写真の下に置く（写真の上に重ねると拡大ボタンが隠れてしまうため）。
        // 印が無い写真も同じ高さの行を確保し、写真の大きさをそろえる。
        var mark = '<div class="prop-photo-mark">' + (isThumb
          ? '<span class="prop-photo-mark__chip">' + (thumbId ? 'サムネイル' : '自動サムネイル') + '</span>' : '') + '</div>';
        var inner = isImg
          ? '<img src="' + UI.esc(url) + '" alt="" loading="lazy">' +
            '<button type="button" class="prop-thumb__zoom" data-full="' + UI.esc(url) + '" aria-label="写真を拡大" title="写真を拡大">' + UI.icon('considering') + '</button>'
          : '<a class="prop-thumb__pdf" href="' + UI.esc(url) + '" target="_blank" rel="noopener noreferrer">PDF</a>';
        return '<div class="prop-photo-item">' +
          '<div class="prop-thumb' + (isImg ? ' prop-thumb--pick' : '') + (isThumb ? ' is-thumb' : '') + '"' +
          (isImg ? ' data-pick-thumb="' + im.id + '" title="クリックして一覧のサムネイルにする"' : '') + '>' +
          inner +
          '<button type="button" class="prop-thumb__del" data-del-img="' + im.id + '" aria-label="この写真を削除" title="この写真を削除">' + UI.icon('trash') + '</button>' +
          '<button type="button" class="prop-photo-cap" data-rename-img="' + im.id + '"' +
          ' aria-label="この写真のコメントを変更" title="クリックしてコメントを変更">' +
          '<span class="prop-photo-cap__txt">' + UI.esc(im.subcategory || 'コメントを入力') + '</span>' +
          '<span class="prop-photo-cap__edit" aria-hidden="true">' + UI.icon('edit') + '</span></button>' +
          '</div>' + mark +
          '</div>';
      }).join('') + '</div>';
      bindDeletes(body, p, category);
      bindPhotoActions(body, p, photos, thumbId);
    });
  }

  /* ===== 追加資料（改善要望 2-5） =====
     物件に紐づく参考資料を登録し、お客様の物件詳細にも「追加資料」として表示する。
     写真・資料と違い一覧サムネイルには使わないため、資料名とファイルを並べるだけの簡素な表示にする。 */
  function documentUploaderHtml() {
    return '<div class="prop-photo-add" id="prop-doc-drop">' +
      '<div class="prop-photo-add__row">' +
        '<label class="prop-photo-add__label" for="prop-doc-name">資料名</label>' +
        '<input type="text" id="prop-doc-name" class="prop-photo-add__name" list="prop-doc-names" maxlength="' + PHOTO_LABEL_MAX + '" placeholder="例）管理規約">' +
        '<datalist id="prop-doc-names">' + DOC_LABELS.map(function (n) { return '<option value="' + esc(n) + '"></option>'; }).join('') + '</datalist>' +
        '<button type="button" class="prop-btn prop-btn--primary" id="prop-doc-pick">' + UI.icon('upload') + 'ファイルを選択</button>' +
      '</div>' +
      '<div class="prop-photo-add__hint" id="prop-doc-hint">この枠にドラッグ＆ドロップでも追加できます（PDF・画像、複数可）。</div>' +
      '</div>';
  }

  /* 残り件数の表示と、上限に達したときの追加ボタンの無効化。 */
  function updateDocumentUploaderState(count) {
    var pane = P.querySelector('[data-pane="document"]');
    if (!pane) return;
    var hint = pane.querySelector('#prop-doc-hint');
    var btn = pane.querySelector('#prop-doc-pick');
    var rest = Math.max(0, DOC_MAX - (count | 0));
    if (hint) hint.textContent = rest > 0
      ? 'この枠にドラッグ＆ドロップでも追加できます（PDF・画像、複数可）。あと' + rest + '件登録できます。'
      : '追加資料は最大' + DOC_MAX + '件までです。登録するには、いずれかを削除してください。';
    if (btn) btn.disabled = rest <= 0;
  }

  function bindDocumentUploader(pane, p) {
    var zone = pane.querySelector('#prop-doc-drop');
    var pick = pane.querySelector('#prop-doc-pick');
    if (!zone || !pick) return;

    function send(files) {
      var list = Array.prototype.slice.call(files || []);
      if (!list.length) return;
      var fd = new FormData();
      fd.append('property_id', p.id);
      fd.append('category', 'document');
      var name = (pane.querySelector('#prop-doc-name') || {}).value || '';
      if (name.trim()) fd.append('subcategory', name.trim());
      list.forEach(function (f) { fd.append('files[]', f); });
      var body = pane.querySelector('#prop-img-body');
      if (body) body.innerHTML = '<div class="prop-empty"><span class="prop-spinner"></span> アップロード中...</div>';
      api('/image-upload.php', { method: 'POST', body: fd }).then(function (res) {
        if (!res.success) notify('error', res.message || 'アップロードに失敗しました');
        else notify('ok', res.message || '保存しました');
        refreshImages(p, 'document');
      }).catch(function () { notify('error', '通信に失敗しました'); refreshImages(p, 'document'); });
    }

    pick.addEventListener('click', function () {
      var inp = document.createElement('input');
      inp.type = 'file'; inp.accept = 'image/*,application/pdf'; inp.multiple = true;
      inp.addEventListener('change', function () { send(inp.files); });
      inp.click();
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
        e.preventDefault(); e.stopPropagation(); zone.classList.add('is-over');
      });
    });
    zone.addEventListener('dragleave', function (e) { if (e.target === zone) zone.classList.remove('is-over'); });
    zone.addEventListener('drop', function (e) {
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      e.preventDefault(); e.stopPropagation(); zone.classList.remove('is-over');
      send(e.dataTransfer.files);
    });
  }

  /* 追加資料の一覧。資料名は鉛筆ボタンから変更でき、削除もできる。 */
  function renderDocumentList(p, body, docs) {
    updateDocumentUploaderState(docs.length);
    if (!docs.length) {
      body.innerHTML = '<div class="prop-empty">追加資料はまだありません。上の枠から登録してください。</div>';
      return;
    }
    body.innerHTML = '<div class="prop-doc-list">' + docs.map(function (im) {
      var isImg = (im.mime_type || '').indexOf('image/') === 0;
      var url = im.url;
      return '<div class="prop-doc-item">' +
        '<span class="prop-doc-item__icon">' + UI.icon(isImg ? 'upload' : 'manual') + '</span>' +
        '<span class="prop-doc-item__body">' +
          '<a class="prop-doc-item__name" href="' + UI.esc(url) + '" target="_blank" rel="noopener noreferrer">' +
            UI.esc(im.subcategory || im.original_name || '資料') + '</a>' +
          '<span class="prop-doc-item__sub">' + UI.esc(im.original_name || '') + '</span>' +
        '</span>' +
        '<button type="button" class="prop-doc-item__act" data-rename-doc="' + im.id + '" aria-label="資料名を変更" title="資料名を変更">' + UI.icon('edit') + '</button>' +
        '<button type="button" class="prop-doc-item__act prop-doc-item__act--danger" data-del-img="' + im.id + '" aria-label="この資料を削除" title="この資料を削除">' + UI.icon('trash') + '</button>' +
        '</div>';
    }).join('') + '</div>';
    bindDeletes(body, p, 'document');
    body.querySelectorAll('[data-rename-doc]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = parseInt(b.getAttribute('data-rename-doc'), 10);
        var doc = docs.filter(function (x) { return parseInt(x.id, 10) === id; })[0];
        if (doc) openDocumentLabelForm(p, doc);
      });
    });
  }

  function openDocumentLabelForm(p, doc) {
    var html = '<div class="prop-field full"><label>資料名</label>' +
      '<input type="text" id="prop-doc-label" list="prop-doc-label-list" maxlength="' + PHOTO_LABEL_MAX + '"' +
      ' placeholder="例）管理規約" value="' + esc(doc.subcategory || '') + '">' +
      '<datalist id="prop-doc-label-list">' + DOC_LABELS.map(function (n) { return '<option value="' + esc(n) + '"></option>'; }).join('') + '</datalist></div>' +
      '<div class="prop-msg prop-msg--info">お客様の「追加資料」に、この名前で表示されます。</div>' +
      '<div class="prop-form-actions"><button type="button" class="prop-btn prop-btn--primary" id="prop-doc-label-save">変更する</button></div>';
    var m = UI.modal('資料名を変更', html);
    var input = m.body.querySelector('#prop-doc-label');
    var btn = m.body.querySelector('#prop-doc-label-save');
    function submit() {
      btn.disabled = true;
      api('/image-label.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ image_id: doc.id, subcategory: input.value })
      }).then(function (res) {
        btn.disabled = false;
        if (!res.success) { notify('error', res.message || '変更に失敗しました'); return; }
        m.close();
        notify('ok', res.message || '資料名を変更しました');
        refreshImages(p, 'document');
      }).catch(function () { btn.disabled = false; notify('error', '通信に失敗しました'); });
    }
    btn.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    input.focus();
  }

  /* ===== 写真・資料の追加（ファイル選択 / ドラッグ＆ドロップ / 貼り付け） ===== */
  function photoUploaderHtml() {
    return '<div class="prop-photo-add" id="prop-photo-drop">' +
      '<div class="prop-photo-add__row">' +
        '<label class="prop-photo-add__label" for="prop-photo-name">写真の名前</label>' +
        '<input type="text" id="prop-photo-name" class="prop-photo-add__name" list="prop-photo-names" maxlength="' + PHOTO_LABEL_MAX + '" value="建物外観" placeholder="例）建物外観">' +
        '<datalist id="prop-photo-names">' + PHOTO_LABELS.map(function (n) { return '<option value="' + esc(n) + '"></option>'; }).join('') + '</datalist>' +
        '<button type="button" class="prop-btn prop-btn--primary" id="prop-photo-pick">' + UI.icon('upload') + 'ファイルを選択</button>' +
      '</div>' +
      '<div class="prop-photo-add__hint" id="prop-photo-hint">この枠にドラッグ＆ドロップ、またはコピーした画像の貼り付け（Ctrl+V / ⌘V）でも追加できます。</div>' +
      '</div>';
  }

  /* 残り枚数の表示と、上限に達したときの追加ボタンの無効化。 */
  function updatePhotoUploaderState(count) {
    var pane = P.querySelector('[data-pane="photo"]');
    if (!pane) return;
    var hint = pane.querySelector('#prop-photo-hint');
    var btn = pane.querySelector('#prop-photo-pick');
    var full = count >= PHOTO_MAX;
    if (hint) {
      hint.textContent = full
        ? '写真・資料は最大' + PHOTO_MAX + '枚です。追加するには、不要な写真を削除してください。'
        : 'この枠にドラッグ＆ドロップ、またはコピーした画像の貼り付け（Ctrl+V / ⌘V）でも追加できます。（あと' + (PHOTO_MAX - count) + '枚）';
    }
    if (btn) btn.disabled = full;
  }

  function bindPhotoUploader(pane, p) {
    var dz = pane.querySelector('#prop-photo-drop');
    var nameInput = pane.querySelector('#prop-photo-name');
    if (!dz) return;
    function label() { return nameInput ? nameInput.value.trim() : ''; }

    pane.querySelector('#prop-photo-pick').addEventListener('click', function () {
      var inp = document.createElement('input');
      inp.type = 'file'; inp.accept = 'image/*,application/pdf'; inp.multiple = true;
      inp.addEventListener('change', function () { if (inp.files.length) uploadPhotos(p, inp.files, label()); });
      inp.click();
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dz.classList.add('is-over'); });
    });
    ['dragleave', 'dragend'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-over'); });
    });
    dz.addEventListener('drop', function (e) {
      e.preventDefault(); e.stopPropagation(); dz.classList.remove('is-over');
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) uploadPhotos(p, files, label());
    });

    // 貼り付け（Ctrl+V / ⌘V）は「写真・資料」タブを開いている間だけ有効にする。
    if (PASTE_BOUND) { document.removeEventListener('paste', PASTE_BOUND); PASTE_BOUND = null; }
    PASTE_BOUND = function (e) {
      var cur = P ? P.querySelector('[data-pane="photo"]') : null;
      if (!cur || !document.body.contains(cur)) {
        document.removeEventListener('paste', PASTE_BOUND); PASTE_BOUND = null; return;
      }
      if (!cur.classList.contains('is-active')) return;
      if (document.querySelector('.prop-modal-overlay')) return;   // モーダルを開いている間は無効
      var items = (e.clipboardData && e.clipboardData.items) || [];
      var files = [];
      for (var i = 0; i < items.length; i++) {
        if (items[i].kind === 'file') { var f = items[i].getAsFile(); if (f) files.push(f); }
      }
      if (!files.length) return;   // 文字列の貼り付け（名前欄への入力など）はそのまま通す
      e.preventDefault();
      uploadPhotos(p, files, label());
    };
    document.addEventListener('paste', PASTE_BOUND);
  }

  function uploadPhotos(p, files, label) {
    var body = P.querySelector('[data-pane="photo"] #prop-img-body');
    var fd = new FormData();
    fd.append('property_id', p.id);
    fd.append('category', 'photo');
    if (label) fd.append('subcategory', label);
    for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
    if (body) body.innerHTML = '<div class="prop-empty"><span class="prop-spinner"></span> アップロード中...</div>';
    api('/image-upload.php', { method: 'POST', body: fd })
      .then(function (res) {
        if (!res.success) notify('error', res.message || 'アップロードに失敗しました');
        else notify('ok', '写真・資料を追加しました');
        refreshImages(p, 'photo');
      })
      .catch(function () { notify('error', '通信に失敗しました'); refreshImages(p, 'photo'); });
  }

  /* ===== 写真のクリック＝サムネイルに設定 / 名前の変更 ===== */
  function bindPhotoActions(body, p, photos, thumbId) {
    body.querySelectorAll('[data-pick-thumb]').forEach(function (tile) {
      tile.addEventListener('click', function (e) {
        if (e.target.closest('button')) return;   // 拡大・削除・名前変更のボタンは除く
        var id = parseInt(tile.getAttribute('data-pick-thumb'), 10);
        if (id === thumbId) return;               // すでにサムネイルに指定済み
        setThumbnail(p, id);
      });
    });
    body.querySelectorAll('[data-rename-img]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = parseInt(b.getAttribute('data-rename-img'), 10);
        var im = null;
        photos.forEach(function (x) { if (parseInt(x.id, 10) === id) im = x; });
        if (im) openPhotoLabelForm(p, im);
      });
    });
  }

  function setThumbnail(p, imageId) {
    api('/thumbnail.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_id: p.id, image_id: imageId })
    }).then(function (res) {
      if (!res.success) { notify('error', res.message || 'サムネイルを変更できませんでした'); return; }
      notify('ok', res.message || 'サムネイルを変更しました');
      refreshImages(p, 'photo');
    }).catch(function () { notify('error', '通信に失敗しました'); });
  }

  function openPhotoLabelForm(p, image) {
    var html = '<div class="prop-field full"><label>写真の下に表示するコメント</label>' +
      '<input type="text" id="prop-photo-label" list="prop-photo-label-list" maxlength="' + PHOTO_LABEL_MAX + '"' +
      ' placeholder="例）建物外観" value="' + esc(image.subcategory || '') + '">' +
      '<datalist id="prop-photo-label-list">' + PHOTO_LABELS.map(function (n) { return '<option value="' + esc(n) + '"></option>'; }).join('') + '</datalist></div>' +
      '<div class="prop-msg prop-msg--info">AIが自動で付けたコメントは、ここで何度でも自由に変更できます。空欄にするとコメントなしになります。</div>' +
      '<div class="prop-form-actions"><button type="button" class="prop-btn prop-btn--primary" id="prop-photo-label-save">変更する</button></div>';
    var m = UI.modal('写真のコメントを変更', html);
    var input = m.body.querySelector('#prop-photo-label');
    var btn = m.body.querySelector('#prop-photo-label-save');

    function submit() {
      btn.disabled = true;
      api('/image-label.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ image_id: image.id, subcategory: input.value })
      }).then(function (res) {
        btn.disabled = false;
        if (!res.success) { notify('error', res.message || '変更に失敗しました'); return; }
        m.close();
        notify('ok', 'コメントを変更しました');
        refreshImages(p, 'photo');
      }).catch(function () { btn.disabled = false; notify('error', '通信に失敗しました'); });
    }
    btn.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    input.focus();
  }
  function bindDeletes(body, p, category) {
    body.querySelectorAll('[data-del-img]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!confirm(category === 'document' ? 'この資料を削除しますか？' : 'この画像を削除しますか？')) return;
        api('/image-delete.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_id: parseInt(b.getAttribute('data-del-img'), 10) }) })
          .then(function (r) { if (r.success) refreshImages(p, category); else notify('error', r.message || '削除に失敗'); });
      });
    });
    // 拡大表示は要素に一度だけ結び付ける（再描画のたびに重ねない）。
    if (!body.dataset.lightboxBound) { body.dataset.lightboxBound = '1'; UI.bindLightbox(body); }
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
     フロー: マスク編集 → 編集画面（塗りつぶしを半透明で表示）→ 顧客用プレビュー → 「この内容で確定」/「範囲を再編集」
     塗りつぶしの色は既定で白。スポイトで販売図面から色を拾って指定することもできる（改善要望 2-4）。
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
      var bandUrl = d.band_url || null; // 登録済み自社帯画像URL（未登録なら null）

      // ベース画像（販売図面）と自社帯画像を先読み
      var baseImg = new Image();
      baseImg.onerror = function () { m.body.innerHTML = '<div class="prop-msg prop-msg--err">プレビュー画像を読み込めませんでした。</div>'; };
      var bandImg = null;
      if (bandUrl) { bandImg = new Image(); bandImg.src = bandUrl; }

      // 自社帯の縦横比・寸法ヘルパー（縦横比は帯画像の比率で固定する）
      var SELF_BAND_WIDTH = 0.8;   // 既定の帯幅（フル幅の80%）
      var SELF_BAND_MARGIN = 0.02; // 下端の余白（印刷時の端切れ対策）
      function bandAspect() {
        return (bandImg && bandImg.naturalWidth > 0 && bandImg.naturalHeight > 0)
          ? (bandImg.naturalWidth / bandImg.naturalHeight) : null;
      }
      // 帯の幅(正規化)から、図面上で縦横比を保つ高さ(正規化)を求める
      function bandHeightForWidth(w) {
        var fw = baseImg.naturalWidth || 0, fh = baseImg.naturalHeight || 0;
        var asp = bandAspect();
        if (asp && fw && fh) return (w * fw / asp) / fh;
        return w * PROP_DEFAULT_BAND.h; // 画像未読込時のフォールバック（従来比率）
      }
      /* 色を #rrggbb に揃える。取れない値は null（＝白扱い）。 */
      function normHex(v) {
        var t = String(v == null ? '' : v).trim().toLowerCase();
        if (!t) return null;
        if (t.charAt(0) !== '#') t = '#' + t;
        if (/^#[0-9a-f]{3}$/.test(t)) t = '#' + t[1] + t[1] + t[2] + t[2] + t[3] + t[3];
        return /^#[0-9a-f]{6}$/.test(t) ? t : null;
      }
      function toHex(r, g, b) {
        function p(n) { var h = (n & 255).toString(16); return h.length < 2 ? '0' + h : h; }
        return '#' + p(r) + p(g) + p(b);
      }

      /* 画面上のクリック位置から、販売図面のその場所の色を取り出す。
         表示は縮小されているため、画像本来の大きさに換算してから読み取る。 */
      var sampleCanvas = null;
      function samplePixelColor(clientX, clientY) {
        var imgEl = m.body.querySelector('#prop-mask-img');
        if (!imgEl || !baseImg.complete || !baseImg.naturalWidth) return null;
        var rect = imgEl.getBoundingClientRect();
        if (!rect.width || !rect.height) return null;
        var rx = (clientX - rect.left) / rect.width;
        var ry = (clientY - rect.top) / rect.height;
        if (rx < 0 || rx > 1 || ry < 0 || ry > 1) return null;
        try {
          if (!sampleCanvas) {
            sampleCanvas = document.createElement('canvas');
            sampleCanvas.width = baseImg.naturalWidth;
            sampleCanvas.height = baseImg.naturalHeight;
            sampleCanvas.getContext('2d').drawImage(baseImg, 0, 0);
          }
          var px = Math.min(sampleCanvas.width - 1, Math.max(0, Math.round(rx * sampleCanvas.width)));
          var py = Math.min(sampleCanvas.height - 1, Math.max(0, Math.round(ry * sampleCanvas.height)));
          var d2 = sampleCanvas.getContext('2d').getImageData(px, py, 1, 1).data;
          return toHex(d2[0], d2[1], d2[2]);
        } catch (err) {
          // 画像が別ドメインから来ている場合などは読み取れない。
          return null;
        }
      }

      // 帯領域を縦横比に合わせて再フィット（高さを補正し、はみ出しを抑える）
      function fitBandRegion(r) {
        r.h = bandHeightForWidth(r.w);
        if (r.y + r.h > 1) r.y = Math.max(0, 1 - r.h);
      }
      // 既定の自社帯領域（幅80%・中央寄せ・下端に余白）
      function makeSelfBand() {
        var w = SELF_BAND_WIDTH, h = bandHeightForWidth(w);
        return { x: (1 - w) / 2, y: Math.max(0, 1 - SELF_BAND_MARGIN - h), w: w, h: h, t: 'band' };
      }

      var regions = (d.regions && d.regions.length) ? d.regions.map(function (r) {
        return { x: +r.x || 0, y: +r.y || 0, w: +r.w || 0, h: +r.h || 0,
                 t: (r.t === 'band' ? 'band' : 'mask'), c: normHex(r.c) };
      }) : [];
      // スポイトで拾った色。次に追加するマスクにも引き継ぐ（既定は白）。
      var pickedColor = '#ffffff';
      var eyedropperOn = false;
      // 既定領域: 自社帯が登録済みなら、下端に自社帯をデフォルト表示（幅80%・中央）。
      // 領域が空の場合は、社名周りを確実に隠す全幅の白マスクを土台として敷く。
      // 未登録なら従来どおり白マスク。帯が既にあれば重複追加しない。
      if (bandUrl && !regions.some(function (r) { return r.t === 'band'; })) {
        if (!regions.length) regions.push(Object.assign({}, PROP_DEFAULT_BAND, { t: 'mask' }));
        regions.push(makeSelfBand());
      } else if (!regions.length) {
        regions.push(Object.assign({}, PROP_DEFAULT_BAND, { t: 'mask' }));
      }

      var rerenderEditor = null; // renderEdit 内で設定。帯画像の遅延読込後に再描画するため。
      baseImg.onload = renderEdit;
      baseImg.src = d.preview_url;

      if (bandImg) {
        bandImg.onload = function () {
          // 帯画像の縦横比が判明したら、帯領域を再フィットして再描画
          regions.forEach(function (r) { if (r.t === 'band') fitBandRegion(r); });
          if (rerenderEditor) rerenderEditor();
        };
      }

      /* --- 編集画面（ドラッグで範囲設定・塗りつぶしは半透明で下地が少し見える） --- */
      function renderEdit() {
        teardownDrag();
        // 帯領域を縦横比に合わせて再フィット（再入時・画像読込後の整合）
        regions.forEach(function (r) { if (r.t === 'band') fitBandRegion(r); });
        var bandNote = bandUrl
          ? '図面下端には自社帯をデフォルト表示しています。'
          : '<b>自社帯は未登録です。</b>「自社帯登録」画面で帯を登録すると、白マスクの代わりに自社帯を表示できます。';
        m.body.innerHTML =
          '<div class="prop-msg prop-msg--info">マスク（塗りつぶし）と自社帯をドラッグで配置できます。売主仲介会社の情報などはマスクで隠し、必要に応じて自社帯を重ねてください。マスクの色は既定で白ですが、<b>スポイトで図面の色を取り込んで指定</b>することもできます。' + bandNote + 'なお、「この内容で確定」ボタンを押さない限り、顧客には販売図面は表示されません。</div>' +
          '<div class="prop-mask-tools">' +
            '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-eyedrop" title="図面の色をスポイトで取る">' + UI.icon('considering') + 'スポイト</button>' +
            '<label class="prop-mask-color"><span>塗りつぶす色</span>' +
            '<input type="color" id="prop-mask-color" value="#ffffff"></label>' +
            '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-apply-color">選んだ色をマスクに適用</button>' +
            '<span class="prop-mask-tools__hint" id="prop-mask-tools-hint">「スポイト」を押してから図面をクリックすると、その場所の色を取り込めます。</span>' +
          '</div>' +
          '<div class="prop-mask-editor"><div class="prop-mask-canvas" id="prop-mask-canvas">' +
          '<img src="' + UI.esc(d.preview_url) + '" alt="" id="prop-mask-img" draggable="false"></div></div>' +
          '<div class="prop-form-actions">' +
          '<button type="button" class="prop-btn prop-btn--ghost" id="prop-band-add"' + (bandUrl ? '' : ' disabled title="先に「自社帯登録」で帯を登録してください"') + '>' + UI.icon('plus') + '自社帯追加</button>' +
          '<button type="button" class="prop-btn prop-btn--ghost" id="prop-mask-add">' + UI.icon('plus') + 'マスク追加</button>' +
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
            var isBand = (r.t === 'band');
            el.className = 'prop-mask-rect' + (isBand ? ' prop-mask-rect--band' : '');
            el.setAttribute('data-i', i);
            setRectStyle(el, r);
            if (isBand && bandUrl) {
              el.style.backgroundImage = 'url("' + bandUrl + '")';
            } else if (!isBand) {
              // 塗りつぶす色を編集画面でもそのまま見せる（下地が少し透ける半透明のまま）。
              el.style.backgroundColor = r.c || '#ffffff';
            }
            el.innerHTML = '<button type="button" class="prop-mask-del" aria-label="削除">×</button>' +
              (isBand ? '<span class="prop-mask-tag">自社帯</span>' : '') +
              '<span class="prop-mask-handle"></span>';
            canvas.appendChild(el);
          });
        }
        // 帯画像の遅延読込後などに、編集画面が表示中なら再描画する
        rerenderEditor = function () { if (canvas && canvas.isConnected) render(); };
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
            if (r.t === 'band') {
              // 自社帯は縦横比を固定（高さは幅から決定）
              r.h = bandHeightForWidth(r.w);
              if (drag.orig.y + r.h > 1) r.h = Math.max(0.01, 1 - drag.orig.y);
            } else {
              r.h = Math.max(0.02, Math.min(1 - drag.orig.y, drag.orig.h + dy));
            }
          } else {
            r.x = Math.max(0, Math.min(1 - r.w, drag.orig.x + dx));
            r.y = Math.max(0, Math.min(1 - r.h, drag.orig.y + dy));
          }
          setRectStyle(drag.el, r);
        };
        upHandler = function () { drag = null; };
        document.addEventListener('pointermove', moveHandler);
        document.addEventListener('pointerup', upHandler);

        var bandAddBtn = m.body.querySelector('#prop-band-add');
        if (bandAddBtn && bandUrl) {
          bandAddBtn.addEventListener('click', function () {
            regions.push(makeSelfBand()); render();
          });
        }
        m.body.querySelector('#prop-mask-add').addEventListener('click', function () {
          regions.push(Object.assign({}, PROP_DEFAULT_BAND, { t: 'mask', c: pickedColor })); render();
        });

        /* --- スポイト（改善要望 2-4） ---
           「スポイト」を押すと図面のクリック待ちになり、押した場所の色を取り込む。
           取り込んだ色は色見本に入り、「選んだ色をマスクに適用」で今あるマスクへ反映できる。
           何も選ばずに新しいマスクを足したときも、その色で作られる。 */
        var colorInput = m.body.querySelector('#prop-mask-color');
        var dropBtn = m.body.querySelector('#prop-mask-eyedrop');
        var applyBtn = m.body.querySelector('#prop-mask-apply-color');
        var toolsHint = m.body.querySelector('#prop-mask-tools-hint');
        if (colorInput) colorInput.value = pickedColor;

        function setEyedropper(on) {
          eyedropperOn = !!on;
          if (dropBtn) dropBtn.classList.toggle('is-active', eyedropperOn);
          if (canvas) canvas.classList.toggle('is-picking', eyedropperOn);
          if (toolsHint) toolsHint.textContent = eyedropperOn
            ? '図面の色を取りたい場所をクリックしてください。'
            : '「スポイト」を押してから図面をクリックすると、その場所の色を取り込めます。';
        }
        if (dropBtn) dropBtn.addEventListener('click', function () { setEyedropper(!eyedropperOn); });
        if (colorInput) colorInput.addEventListener('input', function () {
          pickedColor = normHex(colorInput.value) || '#ffffff';
        });
        if (applyBtn) applyBtn.addEventListener('click', function () {
          var changed = 0;
          regions.forEach(function (r) { if (r.t !== 'band') { r.c = pickedColor; changed++; } });
          render();
          notify('ok', changed ? 'マスクの色を変更しました' : '色を変えるマスクがありません');
        });

        // 図面上のクリックで色を取り込む（マスクの移動と競合しないよう、スポイト中だけ拾う）。
        canvas.addEventListener('click', function (e) {
          if (!eyedropperOn) return;
          e.preventDefault(); e.stopPropagation();
          var hex = samplePixelColor(e.clientX, e.clientY);
          setEyedropper(false);
          if (!hex) { notify('error', '色を取得できませんでした'); return; }
          pickedColor = hex;
          if (colorInput) colorInput.value = hex;
          notify('ok', '色を取り込みました（' + hex + '）。「選んだ色をマスクに適用」で反映できます。');
        }, true);
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
        // 1) マスクを先に描画（範囲ごとの色。指定が無ければ従来どおり白）
        regions.forEach(function (r) {
          if (r.t === 'band') return;
          ctx.fillStyle = r.c || '#ffffff';
          ctx.fillRect(r.x * nw, r.y * nh, r.w * nw, r.h * nh);
        });
        // 2) 自社帯を上に重ねて描画（帯画像が読み込めない場合は白べたでフォールバック）
        regions.forEach(function (r) {
          if (r.t !== 'band') return;
          var dx = r.x * nw, dy = r.y * nh, dw = r.w * nw, dh = r.h * nh;
          if (bandImg && bandImg.complete && bandImg.naturalWidth > 0) {
            ctx.drawImage(bandImg, dx, dy, dw, dh);
          } else {
            ctx.fillStyle = '#ffffff'; ctx.fillRect(dx, dy, dw, dh);
          }
        });

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

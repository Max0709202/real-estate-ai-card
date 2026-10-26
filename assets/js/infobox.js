/**
 * 情報BOX 画面（infobox.php）。
 * 表示・操作の可否はすべてサーバーが閲覧者ごとに判定して返す値（can_* / is_mine など）に従う。
 * この画面側で権限を広げることはしない。
 */
(function () {
  'use strict';

  var app = document.getElementById('ib-app');
  if (!app) return;
  var MODE = app.getAttribute('data-mode');
  var BOX_ID = parseInt(app.getAttribute('data-box-id') || '0', 10);
  var API = 'backend/api/infobox/';
  var S = { box: null, viewer: null, notices: null, pollTimer: null, chat: null };

  /* ───────── 共通 ───────── */
  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function yen(n) {
    if (n === null || n === undefined || n === '') return '未設定';
    return Number(n).toLocaleString('ja-JP') + ' 円';
  }
  function fmtDate(s, withTime) {
    if (!s) return '';
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return String(s);
    return m[1] + '/' + m[2] + '/' + m[3] + (withTime && m[4] ? ' ' + m[4] + ':' + m[5] : '');
  }
  function jpDate(s) {
    if (!s) return '';
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return String(s);
    return parseInt(m[1], 10) + '年' + parseInt(m[2], 10) + '月' + parseInt(m[3], 10) + '日' + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
  }
  function opKey() {
    var a = new Uint8Array(12);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  var toastTimer = null;
  function toast(msg, isError) {
    var t = document.querySelector('.ib-toast');
    if (!t) { t = document.createElement('div'); document.body.appendChild(t); }
    t.className = 'ib-toast' + (isError ? ' is-error' : '');
    t.textContent = msg;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, isError ? 6000 : 3000);
  }

  /** API 呼び出し。opts: {method, body(object), form(FormData)} */
  function api(file, params, opts) {
    opts = opts || {};
    var url = API + file;
    if (params) {
      var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
      if (q) url += '?' + q;
    }
    var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-IBOX': '1' } };
    if (opts.form) {
      init.method = 'POST';
      init.body = opts.form;
    } else if (opts.body) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { return { success: false, message: '通信に失敗しました（' + r.status + '）' }; })
        .then(function (json) {
          json._status = r.status;
          if (!json.success && (json.reason === 'unauthenticated' || json.reason === 'expired' || json.reason === 'suspended' || json.reason === 'disabled')) {
            showFatal(json.message);
          }
          return json;
        });
    }).catch(function () {
      return { success: false, message: '通信に失敗しました。通信状態をご確認のうえ、もう一度お試しください。', _network: true };
    });
  }
  function post(file, body) { return api(file, null, { body: body }); }

  function showFatal(message) {
    stopPolling();
    var tabs = document.getElementById('ib-tabs');
    if (tabs) tabs.classList.add('ib-hidden');
    app.innerHTML = '<div class="ib-card"><div class="ib-error">' + esc(message) + '</div></div>';
  }

  /** モーダル。content は HTML 文字列、onMount(el, close) で操作を付ける。 */
  function modal(title, lead, content, onMount, wide) {
    var back = document.createElement('div');
    back.className = 'ib-modal-back';
    back.innerHTML = '<div class="ib-modal' + (wide ? ' is-wide' : '') + '" role="dialog" aria-modal="true">'
      + '<h2>' + esc(title) + '</h2>' + (lead ? '<p class="ib-page-lead">' + esc(lead) + '</p>' : '')
      + '<div class="ib-modal-body">' + content + '</div></div>';
    document.body.appendChild(back);
    document.body.style.overflow = 'hidden';
    function close() {
      if (back.parentNode) back.parentNode.removeChild(back);
      if (!document.querySelector('.ib-modal-back')) document.body.style.overflow = '';
    }
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    if (onMount) onMount(back.querySelector('.ib-modal'), close);
    return close;
  }

  /** 開いているモーダルだけを印刷する（原本管理票など）。 */
  function printModal(modalEl) {
    var back = modalEl.parentNode;
    back.classList.add('is-printing');
    document.body.classList.add('ib-print-modal');
    var done = function () {
      back.classList.remove('is-printing');
      document.body.classList.remove('ib-print-modal');
      window.removeEventListener('afterprint', done);
    };
    window.addEventListener('afterprint', done);
    window.print();
    setTimeout(done, 1000);
  }

  function confirmBox(title, text, okLabel, onOk, danger) {
    modal(title, '', '<p>' + esc(text) + '</p><div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button>'
      + '<button class="ib-btn ' + (danger ? 'ib-btn-danger' : 'ib-btn-primary') + '" data-ok>' + esc(okLabel) + '</button></div>',
      function (m, close) {
        m.querySelector('[data-x]').onclick = close;
        m.querySelector('[data-ok]').onclick = function () { close(); onOk(); };
      });
  }

  function field(name, label, value, opts) {
    opts = opts || {};
    var type = opts.type || 'text';
    var attrs = ' name="' + esc(name) + '" id="f-' + esc(name) + '"' + (opts.max ? ' max="' + esc(opts.max) + '"' : '')
      + (opts.placeholder ? ' placeholder="' + esc(opts.placeholder) + '"' : '') + (opts.readonly ? ' readonly' : '');
    var input = type === 'textarea'
      ? '<textarea' + attrs + '>' + esc(value) + '</textarea>'
      : type === 'select'
        ? '<select' + attrs + '>' + opts.options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'
        : '<input type="' + type + '"' + attrs + ' value="' + esc(value) + '">';
    return '<div class="ib-field' + (opts.wide ? ' is-wide' : '') + (opts.missing ? ' is-missing' : '') + '"><label for="f-' + esc(name) + '">' + esc(label)
      + (opts.required ? '<span class="ib-req">必須</span>' : '') + '</label>' + input + (opts.note ? '<p class="ib-note">' + esc(opts.note) + '</p>' : '') + '</div>';
  }
  function formValues(root) {
    var out = {};
    root.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (i) {
      if (i.type === 'checkbox') out[i.name] = i.checked ? '1' : '';
      else out[i.name] = i.value;
    });
    return out;
  }
  function busy(btn, on) { if (btn) btn.disabled = !!on; }

  function stopPolling() { if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = null; } }

  /* ───────── 一覧（名刺所有者） ───────── */
  function renderList() {
    api('box.php', { action: 'list' }).then(function (list) {
      if (!list.success) { showFatal(list.message); return; }
      var canCreate = list.data.can_create;
      var extra = canCreate ? [api('box.php', { action: 'properties' }), api('ledger.php', { action: 'index' })] : [];
      return Promise.all(extra).then(function (res) { drawList(list, res[0] || { success: false }, res[1] || { success: false }); });
    });
  }

  function drawList(list, props, ledgers) {
    var canCreate = list.data.can_create;
    var boxes = list.data.boxes;
    var joined = list.data.joined || [];
    var properties = props.success ? props.data.properties : [];
    var html = '<h1 class="ib-page-title">情報BOX</h1><p class="ib-page-lead">お取引ごとに、書類の共有・関係者との連絡・原本の受渡し・取引台帳の作成をまとめて行えます。</p>';

    if (joined.length) {
      html += '<div class="ib-card"><h3>参加中の情報BOX（ご招待を受けたお取引）</h3><div class="ib-table-wrap"><table class="ib-table is-stack"><thead><tr><th>物件</th><th>あなたの役割</th><th>状態</th><th></th></tr></thead><tbody>'
        + joined.map(function (b) {
          return '<tr><td><b>' + esc(b.property_name) + '</b><div class="ib-sub">' + esc(b.address) + '</div></td><td>' + esc(b.role_label) + '</td>'
            + '<td>' + (b.status === 'ended' ? '<span class="ib-badge is-gray">取引終了</span>' : '<span class="ib-badge is-green">進行中</span>') + '</td>'
            + '<td style="text-align:right"><a class="ib-btn ib-btn-sm" href="infobox.php?box=' + b.id + '">開く</a></td></tr>';
        }).join('') + '</tbody></table></div></div>';
    }
    if (!canCreate) { app.innerHTML = html; return; }

    html += '<div class="ib-card"><h3>新しい情報BOXを作成</h3><div class="ib-form is-2col" id="ib-create">'
      + field('property_id', '物件詳細から選ぶ', '', { type: 'select', options: [['', '選択しない（手入力）']].concat(properties.map(function (p) { return [p.id, p.property_name + (p.address ? '（' + p.address + '）' : '')]; })), wide: true, note: '物件詳細の情報（物件名・所在地・価格・種別）を取り込みます。元の物件詳細は変更されません。' })
      + field('property_name', '物件名', '', { required: true })
      + field('address', '所在地', '')
      + field('price', '売買価格（円）', '', { placeholder: '未設定の場合は空欄' })
      + field('property_type', '物件種別', 'mansion', { type: 'select', options: [['mansion', 'マンション'], ['house', '一戸建て']] })
      + field('owner_side', 'あなたの立場', 'buyer', { type: 'select', options: [['buyer', '買主仲介'], ['seller', '売主仲介']] })
      + field('contract_planned_date', '契約予定日', '', { type: 'date' })
      + '<div class="ib-field is-wide"><label class="ib-check"><input type="checkbox" name="dual_agency"> 自社が両手仲介（買主側・売主側の両方を担当）</label></div>'
      + '</div><div class="ib-actions"><button class="ib-btn ib-btn-primary" id="ib-create-btn">情報BOXを作成</button></div></div>';

    html += '<div class="ib-card"><h3>お取引の情報BOX</h3>';
    if (!boxes.length) html += '<div class="ib-empty">まだ情報BOXはありません。</div>';
    else {
      html += '<div class="ib-table-wrap"><table class="ib-table is-stack"><thead><tr><th>物件</th><th>取引ID</th><th>状態</th><th></th></tr></thead><tbody>';
      boxes.forEach(function (b) {
        html += '<tr><td><b>' + esc(b.property_name) + '</b><div class="ib-sub">' + esc(b.address) + '</div></td><td>' + esc(b.transaction_code) + '</td>'
          + '<td>' + (b.status === 'ended' ? '<span class="ib-badge is-gray">取引終了 ' + esc(fmtDate(b.end_date)) + '</span>' : '<span class="ib-badge is-green">進行中</span>') + '</td>'
          + '<td style="text-align:right"><a class="ib-btn ib-btn-sm" href="infobox.php?box=' + b.id + '">開く</a></td></tr>';
      });
      html += '</tbody></table></div>';
    }
    html += '</div>';

    html += '<div class="ib-card"><h3>取引台帳の一覧（所有者専用）</h3><div class="ib-actions is-left" style="margin-top:0">'
      + '<input type="number" id="ib-ledger-year" class="ib-search" style="width:140px;margin:0" placeholder="事業年度">'
      + '<input type="text" id="ib-ledger-office" class="ib-search" style="width:220px;margin:0" placeholder="事務所名">'
      + '<button class="ib-btn" id="ib-ledger-search">検索</button><button class="ib-btn" id="ib-ledger-close">この事業年度を閉鎖</button></div>'
      + '<p class="ib-note">取引の都度記録し、事務所ごとに管理します。事業年度末に閉鎖すると、その年度の台帳は新しい版を作れなくなり、閉鎖後5年間以上保存されます（自動では削除しません）。</p>'
      + '<div id="ib-ledger-index" style="margin-top:12px"></div></div>';
    app.innerHTML = html;

    renderLedgerIndex(ledgers.success ? ledgers.data.ledgers : []);
    function searchLedgers() {
      api('ledger.php', { action: 'index', year: document.getElementById('ib-ledger-year').value, office: document.getElementById('ib-ledger-office').value })
        .then(function (r) { renderLedgerIndex(r.success ? r.data.ledgers : []); });
    }
    document.getElementById('ib-ledger-search').onclick = searchLedgers;
    document.getElementById('ib-ledger-close').onclick = function () {
      var year = document.getElementById('ib-ledger-year').value.trim();
      var office = document.getElementById('ib-ledger-office').value.trim();
      if (!/^\d{4}$/.test(year)) { toast('閉鎖する事業年度（例：2026）を入力してください。', true); return; }
      confirmBox('事業年度を閉鎖しますか？', year + '年度' + (office ? '（' + office + '）' : '（全事務所）') + 'の取引台帳を閉鎖します。閉鎖後はこの年度の台帳に新しい版を作成できません。', '閉鎖する', function () {
        post('ledger.php', { action: 'close_year', fiscal_year: year, office_name: office }).then(function (r) { toast(r.message, !r.success); if (r.success) searchLedgers(); });
      });
    };

    var create = document.getElementById('ib-create');
    var propSelect = create.querySelector('[name=property_id]');
    propSelect.onchange = function () {
      var p = properties.filter(function (x) { return String(x.id) === propSelect.value; })[0];
      if (!p) return;
      create.querySelector('[name=property_name]').value = p.property_name || '';
      create.querySelector('[name=address]').value = p.address || '';
      create.querySelector('[name=price]').value = p.price === null ? '' : p.price;
      create.querySelector('[name=property_type]').value = p.property_type;
    };
    document.getElementById('ib-create-btn').onclick = function () {
      var btn = this;
      var v = formValues(create);
      v.action = 'create';
      v.dual_agency = v.dual_agency === '1';
      busy(btn, true);
      post('box.php', v).then(function (r) {
        busy(btn, false);
        if (r.success) { window.location.href = 'infobox.php?box=' + r.data.box_id; return; }
        if (r.existing_box_id) {
          confirmBox('情報BOXは作成済みです', r.message + ' 既存の情報BOXを開きますか？', '開く', function () { window.location.href = 'infobox.php?box=' + r.existing_box_id; });
          return;
        }
        toast(r.message, true);
      });
    };
  }

  function renderLedgerIndex(rows) {
    var el = document.getElementById('ib-ledger-index');
    if (!rows.length) { el.innerHTML = '<div class="ib-empty">作成済みの取引台帳はありません。</div>'; return; }
    el.innerHTML = '<div class="ib-table-wrap"><table class="ib-table is-stack"><thead><tr><th>事業年度・台帳番号</th><th>事務所</th><th>物件・取引ID</th><th>版・作成日</th><th></th></tr></thead><tbody>'
      + rows.map(function (r) {
        return '<tr><td>' + esc(r.fiscal_year ? r.fiscal_year + '年度' : '―') + '<div class="ib-sub">' + esc(r.ledger_no) + '</div></td><td>' + esc(r.office_name) + '</td><td>' + esc(r.property_name) + '<div class="ib-sub">' + esc(r.transaction_code) + '</div></td>'
          + '<td>第' + r.version + '版<div class="ib-sub">' + esc(fmtDate(r.created_at)) + '</div>'
          + (r.closed_at ? '<div class="ib-sub">閉鎖 ' + esc(fmtDate(r.closed_at)) + '／保存期限 ' + esc(fmtDate(r.retain_until)) + ' 以降</div>' : '') + '</td>'
          + '<td style="text-align:right"><a class="ib-btn ib-btn-sm" target="_blank" rel="noopener" href="' + esc(r.url) + '">閲覧</a> <a class="ib-btn ib-btn-sm" href="infobox.php?box=' + r.box_id + '#ledger">台帳を開く</a></td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  /* ───────── BOX ───────── */
  function loadBox() {
    return api('box.php', { action: 'get', box_id: BOX_ID }).then(function (r) {
      if (!r.success) { if (!r.reason) showFatal(r.message); return false; }
      S.box = r.data.box;
      S.viewer = r.data.viewer;
      S.notices = r.data.notices;
      S.overview = r.data;
      if (r.data.first_access) showFirstAccess();
      return true;
    });
  }

  function showFirstAccess() {
    modal('情報BOXへようこそ', '', '<div class="ib-notice"><strong>書類の秘密保持について</strong>' + esc(S.notices.document) + '</div>'
      + '<div class="ib-notice"><strong>フォルダーの色</strong>' + esc(S.notices.color) + '</div>'
      + '<div class="ib-notice"><strong>チャット</strong>' + esc(S.notices.chat) + '</div>'
      + '<div class="ib-notice"><strong>ご利用期限</strong>' + esc(S.viewer.access_notice) + '</div>'
      + '<div class="ib-actions"><button class="ib-btn ib-btn-primary" data-x>確認しました</button></div>',
      function (m, close) { m.querySelector('[data-x]').onclick = close; });
  }

  function setTab(tab) {
    document.querySelectorAll('.ib-tab').forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-tab') === tab); });
  }

  function route() {
    stopPolling();
    var hash = (location.hash || '#overview').slice(1);
    if (hash.indexOf('folder-') === 0) { setTab('folders'); renderFolder(parseInt(hash.slice(7), 10)); }
    else if (hash === 'participants') { setTab('participants'); renderParticipants(); }
    else if (hash === 'folders') { setTab('folders'); renderFolders(); }
    else if (hash === 'chat') { setTab('chat'); renderChat(); }
    else if (hash === 'ledger') { setTab('overview'); renderLedger(); }
    else if (hash === 'end') { setTab('overview'); renderEnd(); }
    else { setTab('overview'); renderOverview(); }
    window.scrollTo(0, 0);
    startUnreadPolling();
  }

  function go(hash) {
    if (location.hash === '#' + hash) route(); else location.hash = hash;
  }

  function readonlyBanner() {
    return S.box.readonly ? '<div class="ib-warn">利用期限を過ぎたため、この情報BOXは閲覧のみです。</div>' : '';
  }

  /* 画面1 取引概要 */
  function renderOverview() {
    loadBox().then(function (ok) {
      if (!ok) return;
      var b = S.box, v = S.viewer, d = S.overview;
      var html = '<h1 class="ib-page-title">お取引の情報BOX</h1><p class="ib-page-lead">書類の準備状況と、関係者からの連絡を確認できます。</p>' + readonlyBanner();
      html += '<div class="ib-card"><div class="ib-prop-head"><span class="ib-chip">' + esc(b.property_type_label) + '・売買</span>'
        + (v.is_owner && !b.readonly ? '<button class="ib-btn ib-btn-sm" id="ib-edit-prop">物件情報を修正</button>' : '') + '</div>'
        + '<div class="ib-prop-name">' + esc(b.property_name) + '</div><div class="ib-prop-addr">' + esc(b.address) + '</div>'
        + '<div class="ib-prop-price"><span class="ib-prop-addr">売買価格</span><strong>' + esc(yen(b.price)) + '</strong></div>'
        + '<div class="ib-meta-row"><span>取引ID <b>' + esc(b.transaction_code) + '</b></span>'
        + '<span>契約予定日 <b>' + esc(b.contract_planned_date ? jpDate(b.contract_planned_date) : '未設定') + '</b></span>'
        + '<span>取引状態 <b>' + (b.status === 'ended' ? '取引終了（' + esc(jpDate(b.end_date)) + '）' : '進行中') + '</b></span></div>'
        + '<div class="ib-meta-row"><span>ご利用期限 <b>' + esc(v.access_notice) + '</b></span></div></div>';

      var c = d.counts;
      html += '<div class="ib-grid-4">'
        + '<div class="ib-stat"><strong>' + c.total + '</strong>対象フォルダー</div>'
        + '<div class="ib-stat is-blue"><strong>' + c.uploaded + '</strong>アップロード済み</div>'
        + '<div class="ib-stat is-red"><strong>' + c.missing + '</strong>未アップロード</div>'
        + '<div class="ib-stat"><strong>' + c.unneeded + '</strong>不要・書類なし</div></div>';

      html += '<div class="ib-grid-2"><div class="ib-card"><h3>確認が必要な項目</h3>';
      if (!d.attention.length) html += '<p class="ib-note">現在、確認が必要な項目はありません。</p>';
      d.attention.forEach(function (a) {
        if (a.type === 'unneeded_request') {
          html += '<p style="margin:6px 0 2px">書類なしの申告</p><p class="ib-note" style="margin:0 0 8px">' + esc(a.label) + (a.reason ? '（' + esc(a.reason) + '）' : '') + '</p>'
            + '<button class="ib-btn ib-btn-sm" data-folder="' + a.folder_id + '">申告内容を確認</button>';
        } else {
          html += '<p style="margin:6px 0 8px">' + esc(a.label) + '</p><button class="ib-btn ib-btn-sm" data-go="participants">取引関係者を確認</button>';
        }
      });
      html += '</div><div class="ib-card"><h3>チャット</h3>';
      if (d.latest_chat) {
        html += '<p style="margin:0">' + esc(d.latest_chat.sender) + ' <span class="ib-note">' + esc(d.latest_chat.sender_role) + '</span></p>'
          + '<p style="margin:6px 0 10px">' + esc(d.latest_chat.body) + '</p>';
      } else {
        html += '<p class="ib-note">まだ投稿はありません。</p>';
      }
      html += '<button class="ib-btn ib-btn-sm" data-go="chat">チャットを開く</button></div></div>';

      html += '<div class="ib-actions">';
      if (v.is_owner) {
        html += '<button class="ib-btn" data-go="end">' + (b.status === 'ended' ? '取引終了の確認・訂正' : '取引終了') + '</button>'
          + '<button class="ib-btn" data-go="ledger">取引台帳を作成する</button>';
      }
      html += '<button class="ib-btn" data-go="participants">取引関係者を確認</button><button class="ib-btn ib-btn-primary" data-go="folders">書類フォルダーを開く</button></div>';
      app.innerHTML = html;

      app.querySelectorAll('[data-go]').forEach(function (btn) { btn.onclick = function () { go(btn.getAttribute('data-go')); }; });
      app.querySelectorAll('[data-folder]').forEach(function (btn) { btn.onclick = function () { go('folder-' + btn.getAttribute('data-folder')); }; });
      var ep = document.getElementById('ib-edit-prop');
      if (ep) ep.onclick = openPropertyEdit;
    });
  }

  /* 画面2 対象物件の修正 */
  function openPropertyEdit() {
    var b = S.box;
    var content = '<div class="ib-form is-2col" id="ib-prop-form">'
      + field('property_name', '物件名', b.property_name, { required: true, wide: true })
      + field('address', '所在地', b.address, { wide: true })
      + field('price', '売買価格（円）', b.price === null ? '' : b.price, { placeholder: '未設定の場合は空欄（0円とは区別します）' })
      + field('property_type', '物件種別', b.property_type, { type: 'select', options: [['mansion', 'マンション'], ['house', '一戸建て']] })
      + field('contract_planned_date', '契約予定日', b.contract_planned_date || '', { type: 'date', note: '管理用の項目です。利用期限は実際の取引終了日で決まります。' })
      + '</div><div id="ib-prop-diff"></div>'
      + '<p class="ib-note">ここでの変更は情報BOXだけに保存され、元の物件詳細には反映されません。</p>'
      + '<div class="ib-actions">' + (b.property_id ? '<button class="ib-btn" data-refetch>物件詳細から再取得</button>' : '')
      + '<button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-save>保存</button></div>';
    modal('対象物件の修正', '物件詳細の情報をBOX側へ取り込み、必要な項目だけを修正します。', content, function (m, close) {
      var form = m.querySelector('#ib-prop-form');
      m.querySelector('[data-x]').onclick = close;
      var rf = m.querySelector('[data-refetch]');
      if (rf) rf.onclick = function () {
        api('box.php', { action: 'refetch', box_id: BOX_ID }).then(function (r) {
          if (!r.success) { toast(r.message, true); return; }
          var keys = Object.keys(r.data.diff);
          var labels = { property_name: '物件名', address: '所在地', price: '売買価格', property_type: '物件種別' };
          var box = m.querySelector('#ib-prop-diff');
          if (!keys.length) { box.innerHTML = '<div class="ib-notice">物件詳細との差分はありません。</div>'; return; }
          box.innerHTML = '<div class="ib-notice"><strong>物件詳細との差分</strong>' + keys.map(function (k) {
            var dd = r.data.diff[k];
            var f = function (val) { return k === 'price' ? yen(val) : (k === 'property_type' ? (val === 'house' ? '一戸建て' : 'マンション') : (val || '（空欄）')); };
            return '<div>' + esc(labels[k]) + '：' + esc(f(dd.before)) + ' → ' + esc(f(dd.after)) + '</div>';
          }).join('') + '<div class="ib-actions is-left"><button class="ib-btn ib-btn-sm" data-apply>この内容を入力欄に反映</button></div></div>';
          box.querySelector('[data-apply]').onclick = function () {
            keys.forEach(function (k) {
              var input = form.querySelector('[name=' + k + ']');
              if (input) input.value = r.data.diff[k].after === null ? '' : r.data.diff[k].after;
            });
            toast('入力欄に反映しました。「保存」で確定します。');
          };
        });
      };
      m.querySelector('[data-save]').onclick = function () {
        var btn = this;
        var v = formValues(form);
        v.action = 'update_property';
        v.box_id = BOX_ID;
        busy(btn, true);
        post('box.php', v).then(function (r) {
          busy(btn, false);
          if (!r.success) { toast(r.message, true); return; }
          close();
          toast(r.message + (r.data.folders_added ? '（不足していた初期フォルダーを' + r.data.folders_added + '件追加）' : ''));
          renderOverview();
        });
      };
    });
  }

  /* 画面3〜6 取引関係者 */
  function notifyBadge(p) {
    if (!p) return '―';
    if (p.status !== 'active') return '<span class="ib-badge is-gray">参加停止</span>';
    var map = { owner: ['is-gray', '所有者'], sent: ['is-blue', '送信済み'], unsent: ['is-red', '未送信'], sending: ['is-gray', '送信中'], failed: ['is-red', '送信失敗'] };
    var m = map[p.notify_status] || ['is-gray', p.notify_status];
    return '<span class="ib-badge ' + m[0] + '">' + m[1] + '</span>';
  }
  function participantInfo(p) {
    if (!p) return '未登録';
    if (p.is_person) return esc(p.name || '（氏名未入力）') + '<div class="ib-sub">連絡先は非表示</div>';
    var html = esc([p.company_name, p.name].filter(Boolean).join('　'));
    if (p.address) html += '<div class="ib-sub">' + esc(p.address) + '</div>';
    if (p.email || p.phone) html += '<div class="ib-sub">' + esc([p.email, p.phone].filter(Boolean).join(' ｜ ')) + '</div>';
    return html;
  }

  function renderParticipants(notifyId) {
    api('participants.php', { action: 'list', box_id: BOX_ID }).then(function (r) {
      if (!r.success) { if (!r.reason) toast(r.message, true); return; }
      var d = r.data;
      S.participants = d;
      var ro = S.box && S.box.readonly;
      var rows = d.slots.map(function (s) { return { label: s.role_label, role: s.role, kind: s.kind, p: s.participant, canRegister: s.can_register }; })
        .concat(d.others.map(function (p) { return { label: 'その他の関係者', role: 'other', kind: 'company', p: p, canRegister: false }; }));
      var html = '<h1 class="ib-page-title">取引関係者</h1><p class="ib-page-lead">登録内容と登録者、通知の状況を確認できます。</p>' + readonlyBanner()
        + '<div class="ib-card"><div class="ib-table-wrap"><table class="ib-table is-stack"><thead><tr><th>役割</th><th>名前・会社情報</th><th>登録者</th><th>通知</th><th></th></tr></thead><tbody>';
      rows.forEach(function (row, i) {
        var p = row.p;
        var actions = '';
        if (!ro) {
          if (p && p.can_edit && !(p.is_owner && row.role !== (S.box.owner_side === 'seller' ? 'seller_agent' : 'buyer_agent') && !S.box.dual_agency)) actions += '<button class="ib-btn ib-btn-sm" data-edit="' + i + '">修正</button> ';
          if (p && p.can_edit && !p.is_owner) actions += p.status === 'active'
            ? '<button class="ib-btn ib-btn-sm ib-btn-danger" data-suspend="' + p.id + '">参加停止</button>'
            : '<button class="ib-btn ib-btn-sm" data-reactivate="' + p.id + '">参加再開</button>';
          if ((!p || p.status !== 'active') && row.canRegister) actions += ' <button class="ib-btn ib-btn-sm ib-btn-primary" data-new="' + i + '">登録</button>';
        }
        html += '<tr><td>' + esc(row.label) + '</td><td>' + participantInfo(p) + '</td><td>' + esc(p ? p.registered_by_name || '―' : '―') + '</td><td>' + notifyBadge(p) + '</td><td style="text-align:right;white-space:nowrap">' + actions + '</td></tr>';
      });
      html += '</tbody></table></div></div>';
      html += '<p class="ib-note">買主・売主の住所・メール・電話は一覧に表示しません（名刺所有者と登録した方の入力画面でのみ確認できます）。未登録の買主2・売主2・専門家がいても先へ進めます。</p>';
      if (!ro) {
        html += '<div class="ib-actions">' + (d.can_add_other ? '<button class="ib-btn" id="ib-add-other">＋ 関係者追加</button>' : '')
          + '<button class="ib-btn ib-btn-primary" id="ib-notify">関係者に通知</button></div>';
      }
      app.innerHTML = html;

      app.querySelectorAll('[data-edit]').forEach(function (b) { b.onclick = function () { var row = rows[+b.getAttribute('data-edit')]; openParticipantForm(row.role, row.kind, row.label, row.p); }; });
      app.querySelectorAll('[data-new]').forEach(function (b) { b.onclick = function () { var row = rows[+b.getAttribute('data-new')]; openParticipantForm(row.role, row.kind, row.label, null); }; });
      app.querySelectorAll('[data-suspend]').forEach(function (b) {
        b.onclick = function () {
          confirmBox('参加を停止しますか？', '停止すると、この方はすぐに情報BOXを利用できなくなり、送付済みのURLも無効になります。', '参加停止', function () {
            post('participants.php', { action: 'suspend', box_id: BOX_ID, id: +b.getAttribute('data-suspend') }).then(function (res) { toast(res.message, !res.success); renderParticipants(); });
          }, true);
        };
      });
      app.querySelectorAll('[data-reactivate]').forEach(function (b) {
        b.onclick = function () {
          post('participants.php', { action: 'reactivate', box_id: BOX_ID, id: +b.getAttribute('data-reactivate') }).then(function (res) { toast(res.message, !res.success); renderParticipants(); });
        };
      });
      var addOther = document.getElementById('ib-add-other');
      if (addOther) addOther.onclick = function () { openParticipantForm('other', 'company', 'その他の関係者', null); };
      var notify = document.getElementById('ib-notify');
      if (notify) notify.onclick = function () { openNotify(rows); };
      if (notifyId) openNotify(rows, notifyId);
    });
  }

  /* 画面4・5 関係者の情報入力 */
  function openParticipantForm(role, kind, label, p) {
    var isPerson = kind === 'person' && !(p && p.is_owner);
    var v = p || {};
    var content = '<div class="ib-form is-2col" id="ib-p-form">'
      + (isPerson ? '' : field('company_name', '会社・事務所名', v.company_name || '', { wide: true }))
      + field('name', isPerson ? '氏名' : '担当者名', v.name || '', { required: true })
      + field('address', '住所', v.address || '')
      + field('email', 'メールアドレス', v.email || '', { type: 'email', required: true })
      + field('phone', '電話番号', v.phone || '', { type: 'tel', required: true })
      + '</div><p class="ib-note">通知には氏名・メールアドレス・電話番号が必要です。保存だけではメールは送信されません。</p>';
    var imports = '';
    if (S.viewer.is_owner && (role === 'buyer_agent' || role === 'seller_agent')) {
      if (p && p.is_owner) imports = '<button class="ib-btn" data-import="card">自分の名刺から取得</button>';
      else if (S.box.property_id) imports = '<button class="ib-btn" data-import="property">物件詳細から取得</button>';
    }
    var canNotifyAfter = !(p && p.is_owner) && (!p || p.can_notify);
    content += '<div class="ib-actions">' + imports + '<button class="ib-btn" data-x>キャンセル</button><button class="ib-btn" data-save>下書き保存</button>'
      + (canNotifyAfter ? '<button class="ib-btn ib-btn-primary" data-save-notify>保存して関係者に通知</button>' : '') + '</div>';
    modal((p ? '修正：' : '登録：') + label, isPerson ? '個人の氏名・住所・メール・電話を入力します。' : '会社・事務所名、住所、担当者名、メール・電話を入力します。', content, function (m, close) {
      var form = m.querySelector('#ib-p-form');
      m.querySelector('[data-x]').onclick = close;
      m.querySelectorAll('[data-import]').forEach(function (b) {
        b.onclick = function () {
          api('participants.php', { action: 'import', box_id: BOX_ID, source: b.getAttribute('data-import') }).then(function (r) {
            if (!r.success) { toast(r.message, true); return; }
            Object.keys(r.data.values).forEach(function (k) {
              var input = form.querySelector('[name=' + k + ']');
              if (input && r.data.values[k]) input.value = r.data.values[k];
            });
            toast('取り込みました。内容を確認して保存してください。');
          });
        };
      });
      function save(btn, thenNotify) {
        var vals = formValues(form);
        vals.action = 'save';
        vals.box_id = BOX_ID;
        vals.role = role;
        if (p) vals.id = p.id;
        busy(btn, true);
        post('participants.php', vals).then(function (r) {
          busy(btn, false);
          if (!r.success) { toast(r.message, true); return; }
          close();
          toast(r.message);
          // 下書き保存だけではメールを送らない。「関係者に通知」は対象確認画面へ進む。
          renderParticipants(thenNotify ? (p ? p.id : r.data.created_id) : 0);
        });
      }
      m.querySelector('[data-save]').onclick = function () { save(this, false); };
      var sn = m.querySelector('[data-save-notify]');
      if (sn) sn.onclick = function () { save(this, true); };
    });
  }

  /* 画面6 関係者への通知対象の確認 */
  function openNotify(rows, onlyId) {
    var seen = {};
    var targets = [];
    rows.forEach(function (row) {
      var p = row.p;
      if (!p || !p.can_notify || p.status !== 'active' || seen[p.id]) return;
      seen[p.id] = true;
      targets.push({ p: p, label: row.label });
    });
    if (!targets.length) { toast('通知できる関係者がいません。先に関係者を登録してください。', true); return; }
    var subject = '［不動産AI名刺］' + S.box.property_name + 'の情報BOX利用のご案内';
    var content = '<div class="ib-notice"><strong>件名</strong>' + esc(subject) + '</div>'
      + '<div class="ib-table-wrap"><table class="ib-table"><thead><tr><th></th><th>氏名・役割</th><th>送信先</th><th>状態</th><th>最終送信日時</th></tr></thead><tbody>'
      + targets.map(function (t) {
        var p = t.p;
        // 未送信・失敗を初期選択。保存直後に開いた場合は、その方だけを選択する（送信済みの再送は明示選択）。
        var checked = onlyId ? p.id === onlyId : (p.notify_status === 'unsent' || p.notify_status === 'failed');
        var missing = [];
        if (!p.name) missing.push('氏名');
        if (p.email === undefined || !p.email) missing.push('メール');
        if (p.phone === undefined || !p.phone) missing.push('電話');
        return '<tr><td><input type="checkbox" data-id="' + p.id + '"' + (checked ? ' checked' : '') + (missing.length ? ' disabled' : '') + '></td>'
          + '<td>' + esc(p.display_name) + '<div class="ib-sub">' + esc(t.label) + '</div>' + (missing.length ? '<div class="ib-sub" style="color:#cc3340">未入力：' + esc(missing.join('・')) + '</div>' : '') + '</td>'
          + '<td>' + esc(p.email || '―') + '</td><td data-status="' + p.id + '">' + notifyBadge(p) + '</td><td>' + esc(fmtDate(p.notified_at, true) || '―') + '</td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<p class="ib-note">未送信・送信失敗の方を初期選択しています。送信済みの方への再送は、チェックを付けた場合だけ行います。送信に失敗しても登録内容はそのまま残ります。</p>'
      + '<div class="ib-actions"><button class="ib-btn" data-x>閉じる</button><button class="ib-btn ib-btn-primary" data-send>選択した関係者に送信</button></div>';
    modal('関係者への通知対象の確認', '送信対象・宛先・件名を確認してから送信します。', content, function (m, close) {
      m.querySelector('[data-x]').onclick = function () { close(); renderParticipants(); };
      m.querySelector('[data-send]').onclick = function () {
        var btn = this;
        var ids = Array.prototype.map.call(m.querySelectorAll('input[data-id]:checked'), function (i) { return +i.getAttribute('data-id'); });
        if (!ids.length) { toast('送信先を選択してください。', true); return; }
        busy(btn, true);
        ids.forEach(function (id) { var cell = m.querySelector('[data-status="' + id + '"]'); if (cell) cell.innerHTML = '<span class="ib-badge is-gray">送信中</span>'; });
        post('participants.php', { action: 'notify', box_id: BOX_ID, ids: ids }).then(function (r) {
          busy(btn, false);
          (r.data && r.data.results || []).forEach(function (res) {
            var cell = m.querySelector('[data-status="' + res.id + '"]');
            if (cell) cell.innerHTML = res.ok ? '<span class="ib-badge is-blue">送信済み</span>' : '<span class="ib-badge is-red">' + esc(res.status === 'sending' ? '送信中' : '送信失敗') + '</span><div class="ib-sub">' + esc(res.message) + '</div>';
            var box = m.querySelector('input[data-id="' + res.id + '"]');
            if (box) box.checked = !res.ok;
          });
          toast(r.message, !r.success || (r.data && r.data.results.some(function (x) { return !x.ok; })));
        });
      };
    }, true);
  }

  /* 画面9 書類フォルダー一覧 */
  var folderFilter = 'all';
  var folderQuery = '';
  function renderFolders() {
    api('folders.php', { action: 'list', box_id: BOX_ID }).then(function (r) {
      if (!r.success) { if (!r.reason) toast(r.message, true); return; }
      var folders = r.data.folders;
      var counts = { all: folders.length, missing: 0, uploaded: 0, unneeded: 0 };
      folders.forEach(function (f) { if (f.color === 'blue') counts.uploaded++; else if (f.color === 'black') counts.unneeded++; else counts.missing++; });
      var html = '<div class="ib-crumb">' + esc(S.box ? S.box.property_name : '') + '</div>'
        + '<div class="ib-prop-head"><div><h1 class="ib-page-title">書類フォルダー</h1><p class="ib-page-lead">色と状態名で書類の準備状況を確認できます。件数は、あなたが閲覧できる書類だけで数えています。</p></div>'
        + (r.data.can_add ? '<button class="ib-btn" id="ib-add-folder">＋ 書類フォルダーを追加</button>' : '') + '</div>' + readonlyBanner()
        + '<div class="ib-filters">'
        + [['all', 'すべて'], ['missing', '未アップロード'], ['uploaded', 'アップロード済み'], ['unneeded', '不要・書類なし']].map(function (f) {
          return '<button class="ib-filter' + (folderFilter === f[0] ? ' is-active' : '') + '" data-filter="' + f[0] + '">' + f[1] + ' ' + counts[f[0]] + '</button>';
        }).join('') + '</div>'
        + '<input type="search" class="ib-search" id="ib-folder-q" placeholder="フォルダー名で検索" value="' + esc(folderQuery) + '">'
        + '<div class="ib-folders" id="ib-folder-grid"></div>'
        + '<p class="ib-note">' + esc(S.notices ? S.notices.color : '') + '</p>';
      app.innerHTML = html;

      function draw() {
        var grid = document.getElementById('ib-folder-grid');
        var list = folders.filter(function (f) {
          if (folderFilter === 'uploaded' && f.color !== 'blue') return false;
          if (folderFilter === 'unneeded' && f.color !== 'black') return false;
          if (folderFilter === 'missing' && f.color !== 'red') return false;
          return !folderQuery || (f.name + f.target_name).indexOf(folderQuery) !== -1;
        });
        grid.innerHTML = list.length ? list.map(function (f) {
          return '<button class="ib-folder" data-id="' + f.id + '"><div class="ib-folder-top"><span class="ib-folder-icon is-' + f.color + '"></span>'
            + '<span class="ib-badge is-' + f.color + '">' + esc(f.status_label) + '</span></div>'
            + '<div class="ib-folder-name">' + (f.template_id ? '<span class="ib-folder-id">' + esc(f.template_id) + '</span>' : '') + esc(f.name) + (f.target_name ? '（' + esc(f.target_name) + '）' : '') + '</div>'
            + '<div class="ib-folder-meta">ファイル ' + f.file_count + '件</div>'
            + '<div class="ib-folder-meta">書類担当者：' + esc(f.uploader_names.length ? f.uploader_names.join('・') : '未設定') + '</div>'
            + (f.creator_name ? '<div class="ib-folder-meta">追加：' + esc(f.creator_name) + '</div>' : '') + '</button>';
        }).join('') : '<div class="ib-empty">該当するフォルダーはありません。</div>';
        grid.querySelectorAll('[data-id]').forEach(function (b) { b.onclick = function () { go('folder-' + b.getAttribute('data-id')); }; });
      }
      draw();
      app.querySelectorAll('[data-filter]').forEach(function (b) {
        b.onclick = function () {
          folderFilter = b.getAttribute('data-filter');
          app.querySelectorAll('[data-filter]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
          draw();
        };
      });
      document.getElementById('ib-folder-q').oninput = function () { folderQuery = this.value.trim(); draw(); };
      var add = document.getElementById('ib-add-folder');
      if (add) add.onclick = openAddFolder;
    });
  }

  /* 画面17 書類フォルダーの追加 */
  function openAddFolder() {
    api('folders.php', { action: 'catalog', box_id: BOX_ID }).then(function (r) {
      if (!r.success) { toast(r.message, true); return; }
      var items = r.data.items;
      var options = [['', '自由な名前で追加']].concat(items.map(function (it) { return [it.id, it.id + ' ' + it.name + (it.basic ? '' : '（条件付き）')]; }));
      var content = '<div class="ib-form" id="ib-add-form">'
        + field('template_id', '書類の候補', '', { type: 'select', options: options })
        + '<div id="ib-add-purpose"></div>'
        + field('name', 'フォルダー名', '', { required: true })
        + field('target_name', '対象者（本人確認資料などは対象者ごとに分けてください）', '', { placeholder: '例：買主1 山田太郎' })
        + '</div><div class="ib-notice">追加したあなたがこのフォルダーの責任者になります。他の方の初期権限は一覧での表示まで（閲覧・印刷は書類ごとに登録者が指定）です。'
        + 'フォルダー名には個人の秘密を含めないでください。</div>'
        + '<div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-save>追加する</button></div>';
      modal('書類フォルダーの追加', '候補から選ぶか、自由な名前で追加できます。', content, function (m, close) {
        var form = m.querySelector('#ib-add-form');
        var sel = form.querySelector('[name=template_id]');
        sel.onchange = function () {
          var it = items.filter(function (x) { return x.id === sel.value; })[0];
          form.querySelector('[name=name]').value = it ? it.name : '';
          m.querySelector('#ib-add-purpose').innerHTML = it ? '<div class="ib-notice">' + (it.purpose ? esc(it.purpose) + '<br>' : '')
            + '担当候補：' + esc(it.uploader_codes) + '／閲覧候補：' + esc(it.viewer_codes) + '<br><span class="ib-note">候補は目安です。自動では付与されません。</span></div>' : '';
        };
        m.querySelector('[data-x]').onclick = close;
        function save(confirmDup) {
          var v = formValues(form);
          v.action = 'add';
          v.box_id = BOX_ID;
          if (confirmDup) v.confirm_duplicate = 1;
          post('folders.php', v).then(function (res) {
            if (res.duplicate) {
              // 既存を開くか、別用途として追加するかを選ばせる（画面17・5-3）
              modal('同じフォルダーがあります', '', '<p>' + esc(res.message) + '</p><div class="ib-actions"><button class="ib-btn" data-c>キャンセル</button>'
                + '<button class="ib-btn" data-open>既存のフォルダーを開く</button><button class="ib-btn ib-btn-primary" data-add>別の用途として追加</button></div>', function (dm, dclose) {
                dm.querySelector('[data-c]').onclick = dclose;
                dm.querySelector('[data-open]').onclick = function () { dclose(); close(); go('folder-' + res.existing_folder_id); };
                dm.querySelector('[data-add]').onclick = function () { dclose(); save(true); };
              });
              return;
            }
            if (!res.success) { toast(res.message, true); return; }
            close();
            toast(res.message);
            go('folder-' + res.data.folder_id);
          });
        }
        m.querySelector('[data-save]').onclick = function () { save(false); };
      });
    });
  }

  /* 画面10〜12 フォルダーの中 */
  function renderFolder(folderId) {
    api('folders.php', { action: 'get', box_id: BOX_ID, folder_id: folderId }).then(function (r) {
      if (!r.success) { if (!r.reason) { toast(r.message, true); go('folders'); } return; }
      var d = r.data, f = d.folder;
      var ro = S.box && S.box.readonly;
      var html = '<div class="ib-crumb"><a href="#folders">書類フォルダー</a> ／ ' + esc(f.name) + '</div>'
        + '<div class="ib-prop-head"><h1 class="ib-page-title">' + (f.template_id ? '<span class="ib-folder-id">' + esc(f.template_id) + '</span>' : '') + esc(f.name) + (f.target_name ? '（' + esc(f.target_name) + '）' : '') + '</h1>'
        + '<span class="ib-badge is-' + f.color + '">' + esc(f.status_label) + '・' + f.file_count + '件</span></div>'
        + '<p class="ib-page-lead">書類担当者：' + esc(f.uploader_names.length ? f.uploader_names.join('・') : '未設定') + (f.creator_name ? '　／　責任者：' + esc(f.creator_name) : '') + '</p>' + readonlyBanner();
      if (f.unneeded_state !== 'none') {
        html += '<div class="ib-warn">' + (f.unneeded_state === 'requested' ? '不要・書類なしの申告中（確認待ち）' : '不要・書類なしとして確定済み') + (f.unneeded_reason ? '：' + esc(f.unneeded_reason) : '') + '</div>';
      }

      html += '<div class="ib-card"><div class="ib-table-wrap"><table class="ib-table is-stack"><thead><tr><th>ファイル名</th><th>アップロード者・登録日時</th><th>操作</th></tr></thead><tbody>';
      if (!d.documents.length) html += '<tr><td colspan="3" class="ib-empty">あなたが閲覧できる書類はありません。</td></tr>';
      d.documents.forEach(function (doc) {
        html += '<tr><td>' + esc(doc.name) + '<div class="ib-sub">' + esc(doc.ext) + '　｜　第' + doc.version + '版</div></td>'
          + '<td>' + esc(doc.uploader_name) + '<div class="ib-sub">' + esc(fmtDate(doc.updated_at, true)) + '</div></td>'
          + '<td style="white-space:nowrap"><button class="ib-btn ib-btn-sm" data-view="' + doc.id + '">閲覧</button> <button class="ib-btn ib-btn-sm" data-print="' + doc.id + '">印刷</button>'
          + (doc.is_mine && !ro ? '<div style="margin-top:6px"><button class="ib-btn ib-btn-sm" data-replace="' + doc.id + '">変更</button> <button class="ib-btn ib-btn-sm ib-btn-danger" data-del="' + doc.id + '">削除</button> <button class="ib-btn ib-btn-sm" data-share="' + doc.id + '">共有先</button></div>' : '')
          + '</td></tr>';
      });
      html += '</tbody></table></div></div>';

      if (f.can_upload && !ro) {
        html += '<div class="ib-card"><h3>書類を追加</h3><div class="ib-drop" id="ib-drop"><p style="margin:0 0 10px">ここにファイルをドラッグ＆ドロップ</p>'
          + '<button class="ib-btn" id="ib-pick">ファイルを選択</button><input type="file" id="ib-file" class="ib-hidden" multiple accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"></div>'
          + '<p class="ib-note">PDF・JPEG・PNG・Word・Excel ／ 1ファイル20MBまで</p><p class="ib-note">変更・削除・共有先の指定は、アップロードした本人だけが行えます。</p>'
          + '<p class="ib-note">ローン審査結果は登録しないでください。登記識別情報の秘密部分は共有せず、専門家の指定する方法で受け渡してください。</p></div>';
      }

      html += '<div class="ib-card"><h3>原本の受渡し管理</h3><p class="ib-note" style="margin-top:0">電子ファイルと原本は別に管理します。原本票だけではフォルダーは「アップロード済み」になりません。</p>';
      if (!d.originals.length) html += '<p class="ib-note">あなたが閲覧できる原本票はありません。</p>';
      else html += '<table class="ib-table"><tbody>' + d.originals.map(function (o) {
        return '<tr><td>' + esc(o.doc_name) + (o.target_name ? '<div class="ib-sub">対象者：' + esc(o.target_name) + '</div>' : '') + '</td><td>' + esc(o.creator_name) + '</td><td><span class="ib-badge is-gray">' + esc(originalLabel(o.status)) + '</span></td>'
          + '<td style="text-align:right"><button class="ib-btn ib-btn-sm" data-orig="' + o.id + '">開く</button></td></tr>';
      }).join('') + '</tbody></table>';
      if (d.can_create_original && !ro) html += '<div class="ib-actions is-left"><button class="ib-btn ib-btn-sm" id="ib-new-orig">＋ 原本票を作成</button></div>';
      html += '</div>';

      html += '<div class="ib-actions">';
      if (!ro && f.can_manage) html += '<button class="ib-btn" id="ib-settings">閲覧者・書類担当者を設定</button>';
      if (!ro && (f.can_manage || f.can_request_unneeded)) html += '<button class="ib-btn" id="ib-unneeded">不要・書類なし</button>';
      if (!ro && f.can_delete) html += '<button class="ib-btn" id="ib-rename">名称変更</button><button class="ib-btn ib-btn-danger" id="ib-del-folder">フォルダー削除</button>';
      html += '<button class="ib-btn" onclick="location.hash=\'folders\'">一覧に戻る</button></div>';
      app.innerHTML = html;

      var docsById = {};
      d.documents.forEach(function (doc) { docsById[doc.id] = doc; });
      app.querySelectorAll('[data-view]').forEach(function (b) { b.onclick = function () { openViewer(docsById[b.getAttribute('data-view')], false); }; });
      app.querySelectorAll('[data-print]').forEach(function (b) { b.onclick = function () { openViewer(docsById[b.getAttribute('data-print')], true); }; });
      app.querySelectorAll('[data-share]').forEach(function (b) {
        b.onclick = function () {
          var doc = docsById[b.getAttribute('data-share')];
          openShareModal(d, doc.name, doc.shares || [], function (ids, done) {
            post('documents.php', { action: 'shares', box_id: BOX_ID, document_id: doc.id, participant_ids: ids }).then(function (res) {
              done(res.success);
              toast(res.message, !res.success);
              if (res.success) renderFolder(folderId);
            });
          }, '保存');
        };
      });
      app.querySelectorAll('[data-del]').forEach(function (b) {
        b.onclick = function () {
          var doc = docsById[b.getAttribute('data-del')];
          confirmBox('書類を削除しますか？', '「' + doc.name + '」を削除します。共有先の方も閲覧できなくなります。', '削除', function () {
            post('documents.php', { action: 'delete', box_id: BOX_ID, document_id: doc.id }).then(function (res) { toast(res.message, !res.success); renderFolder(folderId); });
          }, true);
        };
      });
      app.querySelectorAll('[data-replace]').forEach(function (b) {
        b.onclick = function () {
          var doc = docsById[b.getAttribute('data-replace')];
          var input = document.createElement('input');
          input.type = 'file';
          input.accept = '.pdf,.jpg,.jpeg,.png,.docx,.xlsx';
          input.onchange = function () {
            if (!input.files[0]) return;
            var fd = new FormData();
            fd.append('action', 'replace');
            fd.append('box_id', BOX_ID);
            fd.append('document_id', doc.id);
            fd.append('op_key', opKey());
            fd.append('file', input.files[0]);
            toast('差し替えています…');
            api('documents.php', null, { form: fd }).then(function (res) { toast(res.message, !res.success); if (res.success) renderFolder(folderId); });
          };
          input.click();
        };
      });

      var pick = document.getElementById('ib-pick');
      if (pick) {
        var fileInput = document.getElementById('ib-file');
        var drop = document.getElementById('ib-drop');
        pick.onclick = function () { fileInput.click(); };
        fileInput.onchange = function () { queueUploads(d, Array.prototype.slice.call(fileInput.files), folderId); fileInput.value = ''; };
        drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        drop.addEventListener('dragleave', function () { drop.classList.remove('is-over'); });
        drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('is-over'); queueUploads(d, Array.prototype.slice.call(e.dataTransfer.files), folderId); });
      }
      var st = document.getElementById('ib-settings');
      if (st) st.onclick = function () { openFolderSettings(d, folderId); };
      var un = document.getElementById('ib-unneeded');
      if (un) un.onclick = function () { openUnneeded(d, folderId); };
      var rn = document.getElementById('ib-rename');
      if (rn) rn.onclick = function () {
        modal('フォルダー名の変更', '', '<div class="ib-form">' + field('name', 'フォルダー名', f.name, { required: true }) + field('target_name', '対象者', f.target_name) + '</div>'
          + '<div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-save>保存</button></div>', function (m, close) {
          m.querySelector('[data-x]').onclick = close;
          m.querySelector('[data-save]').onclick = function () {
            var v = formValues(m);
            post('folders.php', { action: 'rename', box_id: BOX_ID, folder_id: folderId, name: v.name, target_name: v.target_name }).then(function (res) {
              toast(res.message, !res.success);
              if (res.success) { close(); renderFolder(folderId); }
            });
          };
        });
      };
      var df = document.getElementById('ib-del-folder');
      if (df) df.onclick = function () {
        confirmBox('フォルダーを削除しますか？', '書類・原本票が残っている場合は削除できません。', '削除', function () {
          post('folders.php', { action: 'delete', box_id: BOX_ID, folder_id: folderId }).then(function (res) { toast(res.message, !res.success); if (res.success) go('folders'); });
        }, true);
      };
      app.querySelectorAll('[data-orig]').forEach(function (b) { b.onclick = function () { openOriginal(d, +b.getAttribute('data-orig'), folderId); }; });
      var no = document.getElementById('ib-new-orig');
      if (no) no.onclick = function () { openOriginal(d, 0, folderId); };
    });
  }

  /* 画面19 書類を公開する前の共有先確認（1ファイルずつ） */
  function queueUploads(detail, files, folderId) {
    if (!files.length) return;
    var file = files.shift();
    if (file.size > 20 * 1024 * 1024) {
      toast('「' + file.name + '」は20MBを超えるため登録できません。', true);
      queueUploads(detail, files, folderId);
      return;
    }
    var key = opKey();
    openShareModal(detail, file.name, [], function (ids, done) {
      var fd = new FormData();
      fd.append('action', 'upload');
      fd.append('box_id', BOX_ID);
      fd.append('folder_id', folderId);
      fd.append('op_key', key);
      fd.append('shares', JSON.stringify(ids));
      fd.append('file', file);
      api('documents.php', null, { form: fd }).then(function (res) {
        done(res.success);
        if (!res.success) { toast(res.message, true); return; }
        toast(res.message + '（閲覧・印刷できる人：' + (res.data.viewers || '') + '）');
        if (files.length) queueUploads(detail, files, folderId); else renderFolder(folderId);
      });
    }, '登録して共有');
  }

  function openShareModal(detail, fileName, current, onSubmit, submitLabel) {
    var me = S.viewer;
    var content = '<div class="ib-field"><label>登録する書類</label><input type="text" value="' + esc(fileName) + '" readonly></div>'
      + '<p>登録者：' + esc(me.name) + '（' + esc(me.role_label) + '）</p>'
      + '<div class="ib-table-wrap"><table class="ib-table"><thead><tr><th>関係者</th><th>役割</th><th style="text-align:center">閲覧・印刷</th></tr></thead><tbody>'
      + (detail.share_candidates.length ? detail.share_candidates.map(function (c) {
        return '<tr><td>' + esc(c.name) + '</td><td>' + esc(c.role_label) + '</td><td style="text-align:center"><input type="checkbox" class="ib-check" data-pid="' + c.id + '" data-name="' + esc(c.name) + '"'
          + (current.indexOf(c.id) !== -1 ? ' checked' : '') + ' style="width:20px;height:20px"></td></tr>';
      }).join('') : '<tr><td colspan="3" class="ib-empty">共有できる関係者がまだいません。</td></tr>')
      + '</tbody></table></div>'
      + '<div class="ib-notice"><strong id="ib-share-who"></strong>' + esc(S.notices.document) + '</div>'
      + '<div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-ok>' + esc(submitLabel) + '</button></div>';
    modal('書類の共有先を確認', '公開前に、閲覧・印刷できる相手を確認してください。', content, function (m, close) {
      var who = m.querySelector('#ib-share-who');
      function update() {
        var names = Array.prototype.map.call(m.querySelectorAll('[data-pid]:checked'), function (i) { return i.getAttribute('data-name'); });
        who.textContent = '閲覧・印刷できる人：' + (names.length ? 'あなた（' + me.name + '）と ' + names.join('・') : 'あなたのみ');
      }
      m.querySelectorAll('[data-pid]').forEach(function (i) { i.onchange = update; });
      update();
      m.querySelector('[data-x]').onclick = close;
      m.querySelector('[data-ok]').onclick = function () {
        var btn = this;
        var ids = Array.prototype.map.call(m.querySelectorAll('[data-pid]:checked'), function (i) { return +i.getAttribute('data-pid'); });
        busy(btn, true);
        btn.textContent = '登録中…';
        onSubmit(ids, function (ok) {
          busy(btn, false);
          btn.textContent = submitLabel;
          if (ok) close();
        });
      };
    }, true);
  }

  /* 画面13 書類の閲覧と印刷（毎回サーバーで権限を確認して配信） */
  var PDFJS_BASE = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/';
  /** PDF.js の読み込み設定。取引台帳PDFは日本語の標準フォント（非埋め込み）を使うため、文字コード表（CMap）も渡す。 */
  function pdfParams(p) {
    p.cMapUrl = PDFJS_BASE.replace(/build\/$/, 'cmaps/');
    p.cMapPacked = true;
    p.standardFontDataUrl = PDFJS_BASE.replace(/build\/$/, 'standard_fonts/');
    return p;
  }
  var pdfjsPromise = null;
  function loadPdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfjsPromise) return pdfjsPromise;
    pdfjsPromise = new Promise(function (resolve, reject) {
      var sc = document.createElement('script');
      sc.src = PDFJS_BASE + 'pdf.min.js';
      sc.onload = function () {
        if (!window.pdfjsLib) { reject(new Error('pdfjs')); return; }
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      sc.onerror = function () { pdfjsPromise = null; reject(new Error('pdfjs')); };
      document.head.appendChild(sc);
    });
    return pdfjsPromise;
  }

  /** 画像（dataURL / 同一オリジンURL）を、非表示の枠で印刷する（元ファイルのダウンロードボタンは出さない）。 */
  function printImages(title, srcs) {
    var frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);
    var d = frame.contentWindow.document;
    d.open();
    d.write('<html><head><title>' + esc(title) + '</title><style>@page{margin:8mm}body{margin:0}img{display:block;width:100%;page-break-after:always}img:last-child{page-break-after:auto}</style></head><body>'
      + srcs.map(function (src) { return '<img src="' + esc(src) + '">'; }).join('') + '</body></html>');
    d.close();
    var imgs = d.images, left = imgs.length;
    function go() {
      frame.contentWindow.focus();
      frame.contentWindow.print();
      setTimeout(function () { if (frame.parentNode) frame.parentNode.removeChild(frame); }, 60000);
    }
    if (!left) { go(); return; }
    Array.prototype.forEach.call(imgs, function (im) {
      if (im.complete) { if (--left === 0) go(); return; }
      im.onload = im.onerror = function () { if (--left === 0) go(); };
    });
  }

  /**
   * PDF（URL または Uint8Array）を全ページ縦に並べて描画する（取引台帳のプレビュー・印刷用）。
   * 表示ライブラリを読めない場合は、ブラウザの表示機能で開く。
   * @return Promise<canvas[]>
   */
  function renderPdfPages(areaEl, source) {
    areaEl.innerHTML = '<div class="ib-empty">読み込み中…</div>';
    return loadPdfJs().then(function (lib) {
      return lib.getDocument(pdfParams(typeof source === 'string' ? { url: source, withCredentials: true } : { data: source })).promise;
    }).then(function (pdf) {
      areaEl.innerHTML = '';
      var canvases = [];
      var chain = Promise.resolve();
      var width = areaEl.clientWidth - 44;
      for (var i = 1; i <= pdf.numPages; i++) {
        (function (n) {
          chain = chain.then(function () { return pdf.getPage(n); }).then(function (pg) {
            var base = pg.getViewport({ scale: 1 });
            var vp = pg.getViewport({ scale: Math.max(2, (width / base.width) * (window.devicePixelRatio || 1)) });
            var c = document.createElement('canvas');
            c.width = vp.width; c.height = vp.height;
            c.style.width = width + 'px';
            c.style.marginBottom = '12px';
            areaEl.appendChild(c);
            canvases.push(c);
            return pg.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
          });
        })(i);
      }
      return chain.then(function () { return canvases; });
    }, function () {
      var src = typeof source === 'string' ? source : URL.createObjectURL(new Blob([source], { type: 'application/pdf' }));
      areaEl.innerHTML = '<iframe class="ib-viewer-frame" src="' + esc(src) + '" title="PDF"></iframe>';
      return [];
    });
  }

  function openViewer(doc, printNow) {
    var url = API + 'file.php?kind=doc&box_id=' + BOX_ID + '&id=' + doc.id + '&t=' + Date.now();
    var isImage = (doc.ext === 'JPG' || doc.ext === 'PNG');
    var toolbar = '<div class="ib-pdf-toolbar">'
      + (isImage ? '' : '<button class="ib-btn ib-btn-sm" data-prev>前へ</button><span data-pageinfo>－ / －</span><button class="ib-btn ib-btn-sm" data-next>次へ</button><span class="ib-pdf-sep"></span>')
      + '<button class="ib-btn ib-btn-sm" data-zoomout aria-label="縮小">－</button><span data-zoom>100%</span><button class="ib-btn ib-btn-sm" data-zoomin aria-label="拡大">＋</button></div>';
    var area = '<div class="ib-pdf-area" data-area>' + (isImage ? '<img class="ib-viewer-img" data-img src="' + esc(url) + '" alt="">' : '<canvas data-canvas></canvas><div class="ib-empty" data-loading>読み込み中…</div>') + '</div>';
    modal(doc.name, '第' + doc.version + '版', toolbar + area + '<div class="ib-actions"><button class="ib-btn" data-x>閉じる</button><button class="ib-btn ib-btn-primary" data-print>印刷</button></div>'
      + '<p class="ib-note">画面表示・印刷後の複製を完全に防ぐものではありません。取り扱いにご注意ください。</p>', function (m, close) {
      m.querySelector('[data-x]').onclick = close;
      var zoom = 1, pdf = null, page = 1, rendering = false;
      var zoomLabel = m.querySelector('[data-zoom]');

      if (isImage) {
        var img = m.querySelector('[data-img]');
        var applyImg = function () { img.style.width = Math.round(zoom * 100) + '%'; img.style.maxWidth = 'none'; zoomLabel.textContent = Math.round(zoom * 100) + '%'; };
        m.querySelector('[data-zoomin]').onclick = function () { zoom = Math.min(4, zoom + 0.25); applyImg(); };
        m.querySelector('[data-zoomout]').onclick = function () { zoom = Math.max(0.25, zoom - 0.25); applyImg(); };
        applyImg();
        var printImg = function () { printImages(doc.name, [url]); };
        m.querySelector('[data-print]').onclick = printImg;
        if (printNow) img.addEventListener('load', function () { setTimeout(printImg, 300); });
        return;
      }

      var canvas = m.querySelector('[data-canvas]');
      var info = m.querySelector('[data-pageinfo]');
      function render() {
        if (!pdf || rendering) return;
        rendering = true;
        pdf.getPage(page).then(function (pg) {
          var ratio = window.devicePixelRatio || 1;
          var areaW = m.querySelector('[data-area]').clientWidth - 44;
          var base = pg.getViewport({ scale: 1 });
          var fit = areaW / base.width;
          var vp = pg.getViewport({ scale: fit * zoom * ratio });
          canvas.width = vp.width;
          canvas.height = vp.height;
          canvas.style.width = (vp.width / ratio) + 'px';
          return pg.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        }).then(function () {
          rendering = false;
          info.textContent = page + ' / ' + pdf.numPages;
          zoomLabel.textContent = Math.round(zoom * 100) + '%';
        }, function () { rendering = false; });
      }
      function fallback() {
        // 表示ライブラリを読めない環境では、ブラウザの表示機能で開く
        m.querySelector('[data-area]').innerHTML = '<iframe class="ib-viewer-frame" src="' + esc(url) + '#toolbar=0" title="書類の閲覧"></iframe>';
        m.querySelector('.ib-pdf-toolbar').style.display = 'none';
        m.querySelector('[data-print]').onclick = function () {
          var frame = m.querySelector('iframe');
          try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { toast('印刷できませんでした。', true); }
        };
      }
      function printPdf() {
        if (!pdf) return;
        var btn = m.querySelector('[data-print]');
        busy(btn, true);
        btn.textContent = '印刷の準備中…';
        var srcs = [];
        var chain = Promise.resolve();
        for (var i = 1; i <= pdf.numPages; i++) {
          (function (n) {
            chain = chain.then(function () { return pdf.getPage(n); }).then(function (pg) {
              var vp = pg.getViewport({ scale: 2 });
              var c = document.createElement('canvas');
              c.width = vp.width; c.height = vp.height;
              return pg.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise.then(function () { srcs.push(c.toDataURL('image/jpeg', 0.92)); });
            });
          })(i);
        }
        chain.then(function () {
          busy(btn, false);
          btn.textContent = '印刷';
          printImages(doc.name, srcs);
        }, function () { busy(btn, false); btn.textContent = '印刷'; toast('印刷の準備に失敗しました。', true); });
      }
      m.querySelector('[data-prev]').onclick = function () { if (pdf && page > 1) { page--; render(); } };
      m.querySelector('[data-next]').onclick = function () { if (pdf && page < pdf.numPages) { page++; render(); } };
      m.querySelector('[data-zoomin]').onclick = function () { zoom = Math.min(4, zoom + 0.25); render(); };
      m.querySelector('[data-zoomout]').onclick = function () { zoom = Math.max(0.25, zoom - 0.25); render(); };
      m.querySelector('[data-print]').onclick = printPdf;

      loadPdfJs().then(function (lib) {
        // 毎回サーバーで参加資格と共有先を確認して配信される（共有解除・期限切れ後は取得できない）
        return lib.getDocument(pdfParams({ url: url, withCredentials: true })).promise;
      }).then(function (loaded) {
        pdf = loaded;
        var ld = m.querySelector('[data-loading]');
        if (ld) ld.parentNode.removeChild(ld);
        render();
        if (printNow) printPdf();
      }, function (err) {
        if (err && err.name === 'MissingPDFException' || (err && /404|403|Unexpected server response/.test(String(err.message)))) {
          m.querySelector('[data-area]').innerHTML = '<div class="ib-error">この書類は表示できません（共有が解除されたか、削除・期限切れの可能性があります）。</div>';
          return;
        }
        fallback();
      });
    }, true);
  }

  /* 画面14 フォルダーの公開先と登録担当の設定 */
  function openFolderSettings(detail, folderId) {
    var f = detail.folder;
    var list = f.list_principals || [], upload = f.upload_principals || [];
    var content = '<div class="ib-field"><label>対象フォルダー</label><input type="text" readonly value="' + esc(f.name) + '"></div>'
      + '<div class="ib-table-wrap"><table class="ib-table"><thead><tr><th>役割</th><th>登録済みの方</th><th style="text-align:center">一覧に表示</th><th style="text-align:center">書類担当（登録）</th></tr></thead><tbody>'
      + detail.principal_rows.map(function (row) {
        return '<tr><td>' + esc(row.label) + '</td><td>' + esc(row.names.join('・') || '未登録') + '</td>'
          + '<td style="text-align:center"><input type="checkbox" data-list="' + esc(row.key) + '"' + (list.indexOf(row.key) !== -1 ? ' checked' : '') + '></td>'
          + '<td style="text-align:center"><input type="checkbox" data-upload="' + esc(row.key) + '"' + (upload.indexOf(row.key) !== -1 ? ' checked' : '') + '></td></tr>';
      }).join('') + '</tbody></table></div>'
      + '<div class="ib-notice">「一覧に表示」と「書類担当」は、書類の中身を見る権限ではありません。書類の閲覧・印刷は、書類ごとに登録者本人が指定します。'
      + 'この画面で、既に登録された書類の共有先や変更・削除の権限は変わりません。</div>'
      + '<div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-save>保存</button></div>';
    modal('フォルダーの公開先と書類担当者の設定', '', content, function (m, close) {
      m.querySelector('[data-x]').onclick = close;
      m.querySelector('[data-save]').onclick = function () {
        var l = Array.prototype.map.call(m.querySelectorAll('[data-list]:checked'), function (i) { return i.getAttribute('data-list'); });
        var u = Array.prototype.map.call(m.querySelectorAll('[data-upload]:checked'), function (i) { return i.getAttribute('data-upload'); });
        post('folders.php', { action: 'settings', box_id: BOX_ID, folder_id: folderId, list_principals: l, upload_principals: u }).then(function (res) {
          toast(res.message, !res.success);
          if (res.success) { close(); renderFolder(folderId); }
        });
      };
    }, true);
  }

  /* 画面15 不要または書類なしの確認 */
  function openUnneeded(detail, folderId) {
    var f = detail.folder;
    var canManage = f.can_manage;
    var content = '<div class="ib-field"><label>対象フォルダー</label><input type="text" readonly value="' + esc(f.name) + '"></div>'
      + (f.unneeded_state === 'requested' ? '<div class="ib-warn">申告内容：' + esc(f.unneeded_reason) + '</div>' : '')
      + '<div class="ib-form">' + field('reason', '理由', f.unneeded_reason || '', { type: 'textarea', required: true, placeholder: '例：原本のみで受け渡すため電子ファイル不要／該当する書類が存在しない など' }) + '</div>'
      + '<div class="ib-actions is-left" style="margin-top:6px"><button class="ib-btn ib-btn-sm" data-preset="電子ファイル不要（原本のみで受け渡すため）">電子ファイル不要（原本のみ）</button>'
      + '<button class="ib-btn ib-btn-sm" data-preset="該当する書類が存在しないため">書類が存在しない</button></div>'
      + (canManage ? '<p><label class="ib-check"><input type="checkbox" id="ib-un-confirm"> 内容を確認し、不要・書類なしとして確定します</label></p>' : '')
      + '<p class="ib-note">確定しても、あなたが閲覧できる書類が登録・共有されると表示は「アップロード済み」に戻ります。原本だけを扱う場合は「電子ファイル不要」と記録してください。</p>'
      + '<div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button>'
      + (f.unneeded_state !== 'none' && (canManage || f.unneeded_state === 'requested') ? '<button class="ib-btn" data-reset>取り消す</button>' : '')
      + (canManage ? '<button class="ib-btn ib-btn-primary" data-confirm>確定する</button>' : '<button class="ib-btn ib-btn-primary" data-request>申告する</button>') + '</div>';
    modal('不要または書類なしの確認', canManage ? '確定できるのは、初期フォルダーは名刺所有者、追加フォルダーは作成者です。' : '担当者は不要・書類なしを申告できます。確定は名刺所有者が行います。', content, function (m, close) {
      m.querySelector('[data-x]').onclick = close;
      m.querySelectorAll('[data-preset]').forEach(function (b) { b.onclick = function () { m.querySelector('[name=reason]').value = b.getAttribute('data-preset'); }; });
      function send(op) {
        post('folders.php', { action: 'unneeded', op: op, box_id: BOX_ID, folder_id: folderId, reason: m.querySelector('[name=reason]').value, confirmed: (m.querySelector('#ib-un-confirm') || {}).checked ? 1 : 0 })
          .then(function (res) { toast(res.message, !res.success); if (res.success) { close(); renderFolder(folderId); } });
      }
      var b;
      if ((b = m.querySelector('[data-confirm]'))) b.onclick = function () { send('confirm'); };
      if ((b = m.querySelector('[data-request]'))) b.onclick = function () { send('request'); };
      if ((b = m.querySelector('[data-reset]'))) b.onclick = function () { send('reset'); };
    });
  }

  /* 画面18 原本の受渡し管理 */
  var ORIGINAL_LABELS = { unconfirmed: '未確認', requesting: '取得依頼中', obtained: '取得済', submitted: '提出済', returned: '返却済', not_needed: '不要' };
  function originalLabel(s) { return ORIGINAL_LABELS[s] || s; }

  function openOriginal(detail, id, folderId) {
    var load = id ? api('originals.php', { action: 'get', box_id: BOX_ID, id: id }) : Promise.resolve({ success: true, data: { original: null } });
    load.then(function (r) {
      if (!r.success) { toast(r.message, true); return; }
      var o = r.data.original;
      var editable = !o || o.is_mine;
      var v = o || { doc_name: '', target_name: '', required_state: 'unknown', copies: '', submit_to: '', deadline: '', issued_date: '', deadline_condition: '', keeper: '', remarks: '', status: 'unconfirmed', viewers: [], document_id: null, events: [] };
      var content = '';
      if (editable) {
        content += '<div class="ib-form is-2col" id="ib-orig-form">'
          + field('target_name', '対象者', v.target_name) + field('doc_name', '書類名', v.doc_name, { required: true })
          + field('required_state', '原本要否', v.required_state, { type: 'select', options: [['unknown', '未確認'], ['required', '必要'], ['not_required', '不要']] })
          + field('copies', '必要通数', v.copies === null ? '' : v.copies, { type: 'number' })
          + field('submit_to', '提出先', v.submit_to) + field('deadline', '提出期限', v.deadline || '', { type: 'date' })
          + field('keeper', '原本保管者', v.keeper) + field('issued_date', '発行日', v.issued_date || '', { type: 'date' })
          + field('status', '状態', v.status === 'submitted' ? 'submitted' : v.status, { type: 'select', options: [['unconfirmed', '未確認'], ['requesting', '取得依頼中'], ['obtained', '取得済'], ['returned', '返却済'], ['not_needed', '不要']].concat(v.status === 'submitted' ? [['submitted', '提出済']] : []) })
          + field('document_id', '関連ファイル（任意）', v.document_id || '', { type: 'select', options: [['', 'なし']].concat(detail.documents.map(function (doc) { return [doc.id, doc.name]; })) })
          + field('deadline_condition', '期限条件・備考（提出先の指示を記録）', v.deadline_condition, { wide: true })
          + field('remarks', '備考', v.remarks, { type: 'textarea', wide: true })
          + '</div><h4 style="margin:16px 0 6px">この原本票を見られる人（あなた以外）</h4><div>'
          + detail.share_candidates.map(function (c) {
            return '<label class="ib-check" style="margin:0 14px 6px 0"><input type="checkbox" data-viewer="' + c.id + '"' + ((v.viewers || []).indexOf(c.id) !== -1 ? ' checked' : '') + '> ' + esc(c.name) + '（' + esc(c.role_label) + '）</label>';
          }).join('') + '</div><p class="ib-note">関連ファイルを選んだ場合、そのファイルを閲覧できる人の範囲内だけに公開されます。</p>';
      } else {
        var rowsT = [['対象者', v.target_name], ['書類名', v.doc_name], ['原本要否', { unknown: '未確認', required: '必要', not_required: '不要' }[v.required_state]], ['必要通数', v.copies ? v.copies + '通' : '未入力'],
          ['提出先', v.submit_to || '未入力'], ['提出期限', v.deadline ? jpDate(v.deadline) : '未入力'], ['原本保管者', v.keeper || '未入力'], ['発行日', v.issued_date ? jpDate(v.issued_date) : '未入力'],
          ['状態', originalLabel(v.status)], ['作成者', v.creator_name], ['期限条件・備考', v.deadline_condition || '―'], ['備考', v.remarks || '―']];
        content += '<table class="ib-table"><tbody>' + rowsT.map(function (t) { return '<tr><th style="width:30%">' + esc(t[0]) + '</th><td>' + esc(t[1]) + '</td></tr>'; }).join('') + '</tbody></table>';
      }
      if (o && o.file_state) {
        content += '<div class="ib-notice"><strong>電子ファイルと原本は別に確認します</strong>フォルダー：' + esc(o.file_state.label) + '　ファイル' + o.file_state.count + '件<br>'
          + '原本：' + esc(originalLabel(o.status)) + (o.submitted ? '／提出日 ' + esc(jpDate(o.submitted.event_date)) + '・' + o.submitted.copies + '通・受領確認者 ' + esc(o.submitted.confirmed_by_name) : '／提出は未完了')
          + '<br><span class="ib-note">アップロードしても、原本は「提出済」になりません。</span></div>';
      }
      if (o) {
        content += '<h4 style="margin:16px 0 6px">受渡し履歴</h4>' + (o.events.length ? '<table class="ib-table"><thead><tr><th>日付</th><th>状態</th><th>通数</th><th>相手</th><th>受領確認者</th><th>記録者</th></tr></thead><tbody>'
          + o.events.map(function (e) { return '<tr><td>' + esc(jpDate(e.event_date) || '―') + '</td><td>' + esc(e.status_label) + '</td><td>' + esc(e.copies || '―') + '</td><td>' + esc(e.counterparty || '―') + '</td><td>' + esc(e.confirmed_by_name || '―') + '</td><td>' + esc(e.recorded_by) + '</td></tr>'; }).join('')
          + '</tbody></table>' : '<p class="ib-note">記録はまだありません。</p>');
        if (o.is_mine && !S.box.readonly) {
          content += '<div class="ib-card" style="margin-top:12px"><h3>受渡しを記録（履歴に追記されます）</h3><div class="ib-form is-2col" id="ib-ev-form">'
            + field('status', '状態', 'submitted', { type: 'select', options: [['requesting', '取得依頼中'], ['obtained', '取得済'], ['submitted', '提出済'], ['returned', '返却済']] })
            + field('event_date', '日付', today(), { type: 'date' }) + field('copies', '通数', '', { type: 'number' })
            + field('counterparty', '相手（提出先など）', v.submit_to) + field('confirmed_by_name', '受領確認者', '') + field('note', 'メモ', '')
            + '</div><p class="ib-note">提出済には、提出日・提出通数・受領確認者が必要です。</p><div class="ib-actions is-left"><button class="ib-btn" data-event>記録する</button></div></div>';
        }
      }
      content += '<div class="ib-actions">' + (o && o.is_mine && !S.box.readonly ? '<button class="ib-btn ib-btn-danger" data-del>削除</button>' : '')
        + '<button class="ib-btn" data-printo>印刷</button><button class="ib-btn" data-x>閉じる</button>'
        + (editable && !S.box.readonly ? '<button class="ib-btn ib-btn-primary" data-save>保存</button>' : '') + '</div>';

      modal('原本の受渡し管理', (detail.folder.template_id ? detail.folder.template_id + ' ' : '') + detail.folder.name, content, function (m, close) {
        m.querySelector('[data-x]').onclick = close;
        m.querySelector('[data-printo]').onclick = function () { printModal(m); };
        var b;
        if ((b = m.querySelector('[data-save]'))) b.onclick = function () {
          var vals = formValues(m.querySelector('#ib-orig-form'));
          vals.action = 'save';
          vals.box_id = BOX_ID;
          vals.folder_id = folderId;
          if (o) vals.id = o.id;
          vals.viewers = Array.prototype.map.call(m.querySelectorAll('[data-viewer]:checked'), function (i) { return +i.getAttribute('data-viewer'); });
          post('originals.php', vals).then(function (res) {
            toast(res.message, !res.success);
            if (res.success) { close(); renderFolder(folderId); }
          });
        };
        if ((b = m.querySelector('[data-event]'))) b.onclick = function () {
          var vals = formValues(m.querySelector('#ib-ev-form'));
          vals.action = 'event';
          vals.box_id = BOX_ID;
          vals.id = o.id;
          post('originals.php', vals).then(function (res) {
            toast(res.message, !res.success);
            if (res.success) { close(); openOriginal(detail, o.id, folderId); }
          });
        };
        if ((b = m.querySelector('[data-del]'))) b.onclick = function () {
          confirmBox('原本票を削除しますか？', '受渡しの記録も表示されなくなります。', '削除', function () {
            post('originals.php', { action: 'delete', box_id: BOX_ID, id: o.id }).then(function (res) { toast(res.message, !res.success); if (res.success) { close(); renderFolder(folderId); } });
          }, true);
        };
      }, true);
    });
  }

  /* 画面16・16b チャット */
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;

  function renderChat() {
    S.chat = S.chat || { tab: 'all', convId: 0, partnerId: 0, lastId: 0, pending: [] };
    api('chat.php', { action: 'conversations', box_id: BOX_ID }).then(function (r) {
      if (!r.success) { if (!r.reason) toast(r.message, true); return; }
      var d = r.data;
      S.chat.allId = d.all.id;
      var directUnread = d.directs.reduce(function (n, c) { return n + c.unread; }, 0);
      var html = '<h1 class="ib-page-title">チャット</h1>' + readonlyBanner()
        + '<div class="ib-chat-tabs"><button class="ib-filter' + (S.chat.tab === 'all' ? ' is-active' : '') + '" data-ctab="all">全員' + (d.all.unread ? '（未読' + d.all.unread + '）' : '') + '</button>'
        + '<button class="ib-filter' + (S.chat.tab === 'direct' ? ' is-active' : '') + '" data-ctab="direct">個別' + (directUnread ? '（未読' + directUnread + '）' : '') + '</button></div>'
        + '<div id="ib-chat-area"></div><p class="ib-note">' + esc(d.notice) + '</p>';
      app.innerHTML = html;
      app.querySelectorAll('[data-ctab]').forEach(function (b) {
        b.onclick = function () { S.chat.tab = b.getAttribute('data-ctab'); S.chat.convId = 0; renderChat(); };
      });
      if (S.chat.tab === 'all') openConversation(d.all.id, 'all', '');
      else if (S.chat.convId) openConversation(S.chat.convId, 'direct', S.chat.partnerName || '');
      else renderDirectList(d);
    });
  }

  function renderDirectList(d) {
    var area = document.getElementById('ib-chat-area');
    var html = '<div class="ib-card"><h3>個別チャット</h3>'
      + (S.box.readonly ? '' : '<div class="ib-actions is-left" style="margin-top:0"><select id="ib-new-partner" class="ib-search" style="width:auto;margin:0"><option value="">相手を選択</option>'
        + d.candidates.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '（' + esc(c.role_label) + '）</option>'; }).join('')
        + '</select><button class="ib-btn ib-btn-primary" id="ib-new-direct">相手を選んで新規作成</button></div>')
      + '<div class="ib-conv-list" style="margin-top:12px">' + (d.directs.length ? d.directs.map(function (c) {
        return '<button class="ib-conv" data-conv="' + c.id + '" data-name="' + esc(c.partner_name) + '"><span><b>' + esc(c.partner_name) + '</b> <span class="ib-note">' + esc(c.partner_role) + '</span>'
          + '<div class="ib-note">' + esc(c.last_body) + '</div></span><span style="text-align:right">' + (c.unread ? '<span class="ib-badge is-red">未読' + c.unread + '</span>' : '')
          + '<div class="ib-note">' + esc(fmtDate(c.last_at, true)) + '</div></span></button>';
      }).join('') : '<div class="ib-empty">個別の会話はまだありません。</div>') + '</div></div>';
    area.innerHTML = html;
    area.querySelectorAll('[data-conv]').forEach(function (b) {
      b.onclick = function () {
        S.chat.convId = +b.getAttribute('data-conv');
        S.chat.partnerName = b.getAttribute('data-name');
        openConversation(S.chat.convId, 'direct', S.chat.partnerName);
      };
    });
    var nb = document.getElementById('ib-new-direct');
    if (nb) nb.onclick = function () {
      var sel = document.getElementById('ib-new-partner');
      if (!sel.value) { toast('相手を選択してください。', true); return; }
      var existing = d.directs.filter(function (c) { return String(c.partner_id) === sel.value; })[0];
      if (existing) { S.chat.convId = existing.id; S.chat.partnerName = existing.partner_name; openConversation(existing.id, 'direct', existing.partner_name); return; }
      S.chat.convId = 0;
      S.chat.partnerId = +sel.value;
      S.chat.partnerName = sel.options[sel.selectedIndex].text;
      openConversation(0, 'direct', S.chat.partnerName);
    };
  }

  function openConversation(convId, kind, partnerName) {
    var area = document.getElementById('ib-chat-area');
    S.chat.convId = convId;
    S.chat.lastId = 0;
    S.chat.kind = kind;
    area.innerHTML = (kind === 'all'
      ? '<div class="ib-chat-public">関係者全員に公開されています。宛先を書いても非公開にはなりません。個人間のご相談は「個別」をご利用ください。</div>'
      : '<div class="ib-chat-private">' + esc(partnerName) + ' さんとあなただけの会話です（名刺所有者・管理者でも第三者は閲覧できません）。'
        + ' <a href="#" id="ib-back-direct">会話一覧に戻る</a></div>')
      + '<div class="ib-chat-box"><div class="ib-chat-log" id="ib-chat-log"></div><div class="ib-attach-name ib-hidden" id="ib-attach-name"></div>'
      + (S.box.readonly ? '' : '<div class="ib-chat-input"><button class="ib-chat-tool" id="ib-voice" title="音声入力" aria-label="音声入力"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1"/><line x1="12" y1="18" x2="12" y2="22"/></svg></button>'
        + '<button class="ib-chat-tool" id="ib-attach" title="ファイルを添付" aria-label="ファイルを添付"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button><input type="file" id="ib-chat-file" class="ib-hidden" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">'
        + '<textarea id="ib-chat-text" placeholder="' + (kind === 'all' ? '関係者全員に公開されます' : 'メッセージを入力') + '"></textarea>'
        + '<button class="ib-btn ib-btn-primary" id="ib-chat-send">送信</button></div>') + '</div>';
    var back = document.getElementById('ib-back-direct');
    if (back) back.onclick = function (e) { e.preventDefault(); S.chat.convId = 0; renderChat(); };
    S.chat.messages = {};
    if (convId) fetchMessages(true);
    else document.getElementById('ib-chat-log').innerHTML = '<div class="ib-empty">最初のメッセージを送ると、会話が始まります。</div>';
    bindChatInput();
    stopPolling();
    S.pollTimer = setInterval(function () { if (S.chat.convId) fetchMessages(false); }, 8000);
  }

  function messageHtml(m) {
    var cls = 'ib-msg' + (m.is_mine ? ' is-mine' : '') + (m.failed ? ' is-failed' : '');
    var body = m.deleted ? '<span class="ib-note">（削除されました）</span>' : esc(m.body);
    var attach = m.attachment ? '<a class="ib-msg-attach" target="_blank" rel="noopener" href="' + esc(m.attachment.url) + '">添付：' + esc(m.attachment.name) + '</a>' : '';
    var tools = '';
    if (m.failed) tools = '<div class="ib-msg-tools" style="color:#cc3340">送信失敗 <button data-retry="' + esc(m.client_uid) + '">再送</button></div>';
    else if (m.is_mine && !m.deleted && !S.box.readonly && m.id) tools = '<div class="ib-msg-tools"><button data-edit="' + m.id + '">編集</button><button data-delete="' + m.id + '">削除</button></div>';
    return '<div class="' + cls + '" data-mid="' + (m.id || '') + '"><div class="ib-msg-head">' + (m.is_mine ? '' : esc(m.sender_name) + ' <span>' + esc(m.sender_role) + '</span>　') + esc(fmtDate(m.created_at, true))
      + (m.edited ? '（編集済み）' : '') + (m.pending ? '　送信中…' : '') + '</div><div class="ib-msg-bubble">' + body + attach + '</div>' + tools + '</div>';
  }

  function drawMessages() {
    var log = document.getElementById('ib-chat-log');
    if (!log) return;
    var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
    var ids = Object.keys(S.chat.messages).map(Number).sort(function (a, b) { return a - b; });
    var html = ids.map(function (id) { return messageHtml(S.chat.messages[id]); }).join('') + S.chat.pending.filter(function (p) { return p.convKey === (S.chat.convId || 'new'); }).map(messageHtml).join('');
    log.innerHTML = html || '<div class="ib-empty">まだ投稿はありません。</div>';
    if (atBottom || S.chat.scrollNext) { log.scrollTop = log.scrollHeight; S.chat.scrollNext = false; }
    log.querySelectorAll('[data-edit]').forEach(function (b) {
      b.onclick = function () {
        var msg = S.chat.messages[b.getAttribute('data-edit')];
        modal('投稿の編集', '', '<div class="ib-form">' + field('body', '本文', msg.body, { type: 'textarea' }) + '</div><div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-save>保存</button></div>', function (m, close) {
          m.querySelector('[data-x]').onclick = close;
          m.querySelector('[data-save]').onclick = function () {
            post('chat.php', { action: 'edit', box_id: BOX_ID, message_id: msg.id, body: m.querySelector('[name=body]').value }).then(function (res) {
              toast(res.message, !res.success);
              if (res.success) { close(); fetchMessages(true); }
            });
          };
        });
      };
    });
    log.querySelectorAll('[data-delete]').forEach(function (b) {
      b.onclick = function () {
        confirmBox('投稿を削除しますか？', '削除した投稿は元に戻せません。', '削除', function () {
          post('chat.php', { action: 'delete', box_id: BOX_ID, message_id: +b.getAttribute('data-delete') }).then(function (res) { toast(res.message, !res.success); fetchMessages(true); });
        }, true);
      };
    });
    log.querySelectorAll('[data-retry]').forEach(function (b) {
      b.onclick = function () {
        var p = S.chat.pending.filter(function (x) { return x.client_uid === b.getAttribute('data-retry'); })[0];
        if (p) { p.failed = false; p.pending = true; drawMessages(); sendPending(p); }
      };
    });
  }

  function fetchMessages(reset) {
    if (reset) { S.chat.lastId = 0; S.chat.messages = {}; }
    var convId = S.chat.convId;
    return api('chat.php', { action: 'messages', box_id: BOX_ID, conversation_id: convId, after_id: S.chat.lastId }).then(function (r) {
      if (!r.success || convId !== S.chat.convId) return;
      r.data.messages.concat(r.data.updates).forEach(function (m) {
        S.chat.messages[m.id] = m;
        if (m.id > S.chat.lastId) S.chat.lastId = m.id;
      });
      if (reset) S.chat.scrollNext = true;
      var sendBtn = document.getElementById('ib-chat-send');
      if (sendBtn && !r.data.can_send) {
        sendBtn.disabled = true;
        document.getElementById('ib-chat-text').placeholder = '相手の参加停止・利用期限切れのため、新しいメッセージは送信できません。';
      }
      drawMessages();
    });
  }

  function sendPending(p) {
    var fd = new FormData();
    fd.append('action', 'send');
    fd.append('box_id', BOX_ID);
    if (p.convId) fd.append('conversation_id', p.convId); else fd.append('partner_id', p.partnerId);
    fd.append('body', p.body);
    fd.append('client_uid', p.client_uid);
    if (p.file) fd.append('file', p.file);
    api('chat.php', null, { form: fd }).then(function (r) {
      if (r.success) {
        S.chat.pending = S.chat.pending.filter(function (x) { return x !== p; });
        if (!S.chat.convId) { S.chat.convId = r.data.conversation_id; p.convKey = r.data.conversation_id; }
        S.chat.scrollNext = true;
        fetchMessages(false);
      } else {
        p.pending = false;
        p.failed = !r.success && !!r._network;
        if (!p.failed) { S.chat.pending = S.chat.pending.filter(function (x) { return x !== p; }); toast(r.message, true); }
        drawMessages();
      }
    });
  }

  function bindChatInput() {
    var text = document.getElementById('ib-chat-text');
    if (!text) return;
    var sendBtn = document.getElementById('ib-chat-send');
    var fileInput = document.getElementById('ib-chat-file');
    var attachName = document.getElementById('ib-attach-name');
    var file = null;
    document.getElementById('ib-attach').onclick = function () { fileInput.click(); };
    fileInput.onchange = function () {
      file = fileInput.files[0] || null;
      attachName.classList.toggle('ib-hidden', !file);
      attachName.textContent = file ? '添付：' + file.name + '（送信するとこの会話の中に保管されます。書類フォルダーには保存されません）' : '';
    };
    function send() {
      var body = text.value.trim();
      if (!body && !file) return;
      if (/ローン.{0,6}(審査|承認|否決)/.test(body) && !window.confirm('ローン審査結果は投稿しないでください。このまま送信しますか？')) return;
      var p = {
        client_uid: opKey(), body: body, file: file, convId: S.chat.convId, partnerId: S.chat.partnerId, convKey: S.chat.convId || 'new',
        is_mine: true, pending: true, created_at: new Date().toISOString().replace('T', ' '), attachment: file ? { name: file.name, url: '#' } : null
      };
      S.chat.pending.push(p);
      text.value = '';
      file = null;
      fileInput.value = '';
      attachName.classList.add('ib-hidden');
      S.chat.scrollNext = true;
      drawMessages();
      sendPending(p);
    }
    sendBtn.onclick = send;
    text.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } });

    var voice = document.getElementById('ib-voice');
    if (!SpeechRecognition) { voice.title = 'このブラウザは音声入力に対応していません'; voice.disabled = true; return; }
    var rec = null;
    voice.onclick = function () {
      if (rec) { rec.stop(); return; }
      rec = new SpeechRecognition();
      rec.lang = 'ja-JP';
      rec.interimResults = false;
      rec.continuous = false;
      var base = text.value;
      voice.classList.add('is-listening');
      rec.onresult = function (e) {
        var t = '';
        for (var i = e.resultIndex; i < e.results.length; i++) t += e.results[i][0].transcript;
        text.value = (base ? base + ' ' : '') + t;
      };
      rec.onend = function () { voice.classList.remove('is-listening'); rec = null; };
      rec.onerror = function () { voice.classList.remove('is-listening'); rec = null; toast('音声を認識できませんでした。', true); };
      rec.start();
    };
  }

  /* 未読のお知らせ（タブの赤丸） */
  var unreadTimer = null;
  function startUnreadPolling() {
    if (MODE !== 'box' || unreadTimer) return;
    function check() {
      api('chat.php', { action: 'conversations', box_id: BOX_ID }).then(function (r) {
        if (!r.success) return;
        var n = r.data.all.unread + r.data.directs.reduce(function (s, c) { return s + c.unread; }, 0);
        var dot = document.getElementById('ib-chat-dot');
        if (dot) { dot.textContent = n; dot.classList.toggle('ib-hidden', !n); }
      });
    }
    check();
    unreadTimer = setInterval(check, 30000);
  }

  /* 画面20 取引終了と継続閲覧者の確認 */
  function renderEnd() {
    Promise.all([loadBox(), api('participants.php', { action: 'list', box_id: BOX_ID })]).then(function (res) {
      if (!res[0] || !res[1].success) return;
      if (!S.viewer.is_owner) { go('overview'); return; }
      var b = S.box, d = res[1].data;
      var ownSides = b.dual_agency ? ['buyer', 'seller'] : [b.owner_side];
      var people = [];
      d.slots.forEach(function (s) { if (s.participant && !s.participant.is_owner) people.push({ slot: s, p: s.participant }); });
      d.others.forEach(function (p) { people.push({ slot: { role: 'other', role_label: 'その他の関係者' }, p: p }); });
      var html = '<div class="ib-crumb"><a href="#overview">取引概要</a> ／ 取引終了</div><h1 class="ib-page-title">取引終了と継続閲覧者の確認</h1>'
        + '<p class="ib-page-lead">実際の取引終了日と、終了後も閲覧を続ける自社のお客様を確認して確定します。</p>'
        + '<div class="ib-card"><div class="ib-form is-2col" id="ib-end-form">'
        + field('end_date', '実際の取引終了日', b.end_date || today(), { type: 'date', max: today(), required: true, note: '未来の日付は指定できません。契約予定日からは自動で判定しません。' })
        + (b.status === 'ended' ? field('reason', '終了日を訂正する理由', '', { required: true }) : '')
        + '</div><div id="ib-end-until" class="ib-notice"></div>'
        + '<table class="ib-table"><thead><tr><th>関係者</th><th>役割</th><th style="text-align:center">継続閲覧</th><th>終了後の扱い</th></tr></thead><tbody>'
        + '<tr><td>' + esc(S.viewer.name) + '</td><td>' + esc(S.viewer.role_label) + '</td><td style="text-align:center">✓</td><td>継続閲覧（名刺所有者）</td></tr>'
        + people.map(function (x) {
          var side = x.slot.role.indexOf('buyer') === 0 ? 'buyer' : (x.slot.role.indexOf('seller') === 0 ? 'seller' : '');
          var eligible = /^(buyer|seller)[12]$/.test(x.slot.role) && ownSides.indexOf(side) !== -1;
          return '<tr><td>' + esc(x.p.display_name) + (x.p.status !== 'active' ? '<div class="ib-sub">参加停止中</div>' : '') + '</td><td>' + esc(x.slot.role_label) + '</td>'
            + '<td style="text-align:center">' + (eligible ? '<input type="checkbox" data-cont="' + x.p.id + '"' + (x.p.continue_access ? ' checked' : '') + ' style="width:20px;height:20px">' : '―') + '</td>'
            + '<td data-fate="' + x.p.id + '" data-eligible="' + (eligible ? 1 : 0) + '"></td></tr>';
        }).join('') + '</tbody></table>'
        + '<p class="ib-note">継続閲覧できるのは、自社が仲介した買主・売主です（両手仲介の場合は双方）。継続閲覧でも、見られる書類・会話はこれまでの共有範囲と同じです。'
        + 'その他の関係者は、終了日の翌月同日 23:59 まで利用でき、翌日0時に旧URL・書類・添付も含めて停止します。</p>'
        + '<div class="ib-actions">' + (b.status === 'ended' ? '<button class="ib-btn" id="ib-reopen">取引を再開</button>' : '') + '<button class="ib-btn" onclick="location.hash=\'overview\'">キャンセル</button><button class="ib-btn ib-btn-primary" id="ib-end-save">'
        + (b.status === 'ended' ? '訂正して確定' : '取引終了を確定') + '</button></div></div>';
      app.innerHTML = html;

      var form = document.getElementById('ib-end-form');
      function untilOf(dateStr) {
        var m = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return '';
        var y = +m[1], mo = +m[2] + 1, day = +m[3];
        if (mo > 12) { mo = 1; y++; }
        var last = new Date(y, mo, 0).getDate();
        return y + '年' + mo + '月' + Math.min(day, last) + '日 23:59';
      }
      function refresh() {
        var u = untilOf(form.querySelector('[name=end_date]').value);
        document.getElementById('ib-end-until').textContent = u ? 'その他の関係者の利用期限：' + u + '（日本時間）' : '';
        app.querySelectorAll('[data-fate]').forEach(function (td) {
          var box = app.querySelector('[data-cont="' + td.getAttribute('data-fate') + '"]');
          td.textContent = box && box.checked ? '継続閲覧' : (u ? u + ' まで' : '');
        });
      }
      form.querySelector('[name=end_date]').onchange = refresh;
      app.querySelectorAll('[data-cont]').forEach(function (c) { c.onchange = refresh; });
      refresh();
      document.getElementById('ib-end-save').onclick = function () {
        var btn = this;
        var v = formValues(form);
        confirmBox('取引終了を確定しますか？', 'その他の関係者は ' + untilOf(v.end_date) + ' で利用終了になります。', '確定', function () {
          busy(btn, true);
          post('box.php', { action: 'end', box_id: BOX_ID, end_date: v.end_date, reason: v.reason || '', continue_ids: Array.prototype.map.call(app.querySelectorAll('[data-cont]:checked'), function (i) { return +i.getAttribute('data-cont'); }) })
            .then(function (r) { busy(btn, false); toast(r.message, !r.success); if (r.success) go('overview'); });
        });
      };
      var ro = document.getElementById('ib-reopen');
      if (ro) ro.onclick = function () {
        modal('取引を再開', '期限切れで利用停止になった方は自動では戻りません。必要な相手だけ再度通知してください。', '<div class="ib-form">' + field('reason', '再開する理由', '', { type: 'textarea', required: true }) + '</div>'
          + '<div class="ib-actions"><button class="ib-btn" data-x>キャンセル</button><button class="ib-btn ib-btn-primary" data-ok>再開する</button></div>', function (m, close) {
          m.querySelector('[data-x]').onclick = close;
          m.querySelector('[data-ok]').onclick = function () {
            post('box.php', { action: 'reopen', box_id: BOX_ID, reason: m.querySelector('[name=reason]').value }).then(function (r) {
              toast(r.message, !r.success);
              if (r.success) { close(); go('participants'); }
            });
          };
        });
      };
    });
  }

  /* 画面21 取引台帳 */
  function renderLedger() {
    Promise.all([loadBox(), api('ledger.php', { action: 'state', box_id: BOX_ID })]).then(function (res) {
      if (!res[0]) return;
      var r = res[1];
      if (!r.success) { toast(r.message, true); go('overview'); return; }
      var d = r.data;
      var data = d.draft || {};
      var missing = d.missing || {};
      var b = S.box;
      var groups = [];
      d.fields.forEach(function (f) { if (groups.indexOf(f.group) === -1) groups.push(f.group); });

      var html = '<div class="ib-crumb"><a href="#overview">取引概要</a> ／ 取引台帳</div><h1 class="ib-page-title">取引台帳を作成する</h1>'
        + '<p class="ib-page-lead">売買契約書と重要事項説明書から入力内容を自動取得し、確認・補完して取引台帳をPDFで保存・印刷します。</p>';
      if (!d.has_sources.contract || !d.has_sources.explanation) {
        var lack = [];
        if (!d.has_sources.contract) lack.push('売買契約書');
        if (!d.has_sources.explanation) lack.push('重要事項説明書');
        html += '<div class="ib-error">' + esc(lack.join('と')) + 'が情報BOXに登録されていないため、取引台帳は作れません。書類フォルダーの「07 売買契約書」「09 重要事項説明書」に登録（または名刺所有者への共有）をしてください。</div>';
      }
      var closeForm = d.draft && !Object.keys(missing).length;
      html += '<div class="ib-card"><h3>' + esc(b.property_name) + '</h3>'
        + '<div class="ib-grid-2" id="ib-ledger-summary"></div>'
        + '<p style="margin:14px 0 6px">売主・買主／住所／物件表示／他の宅建業者／特約事項</p>'
        + '<div class="ib-actions is-left" style="margin-top:0"><button class="ib-btn" id="ib-ledger-toggle">' + (closeForm ? '台帳の入力内容を確認' : '入力内容を閉じる') + '</button>'
        + '<button class="ib-btn" id="ib-autofill"' + (d.has_sources.contract && d.has_sources.explanation ? '' : ' disabled') + '>契約書・重要事項説明書から自動取得</button>'
        + (d.draft ? '<span class="ib-note">下書き保存：' + esc(fmtDate(d.draft_updated_at, true)) + '</span>' : '') + '</div>'
        + '<div id="ib-ledger-msg"></div><div id="ib-ledger-form"' + (closeForm ? ' class="ib-hidden"' : '') + '>';
      groups.forEach(function (g) {
        html += '<div class="ib-ledger-group"><h4>' + esc(g) + '</h4><div class="ib-form is-2col">';
        d.fields.filter(function (f) { return f.group === g; }).forEach(function (f) {
          var val = data[f.key] || '';
          var isMissing = !!missing[f.key];
          if (f.type === 'check') {
            html += '<div class="ib-field"><label class="ib-check"><input type="checkbox" name="' + f.key + '"' + (val ? ' checked' : '') + '> ' + esc(f.label) + '</label></div>';
          } else {
            html += field(f.key, f.label, val, {
              type: f.type === 'date' ? 'date' : (f.type === 'textarea' ? 'textarea' : 'text'),
              wide: f.type === 'textarea' || /address|location|remarks/.test(f.key),
              required: f.required || isMissing, missing: isMissing,
              readonly: f.key === 'fee_total',
              note: f.key === 'fee_total' ? '買主側・売主側の仲介手数料（税込）から自動で計算します。' : (f.key === 'price_total' ? '契約書の売買代金を記載します（契約予定価格ではありません）。' : ''),
              placeholder: f.type === 'money' ? '数字のみ（該当しない場合は「該当なし」）' : (f.required ? '該当しない場合は「該当なし」' : '')
            });
          }
        });
        html += '</div></div>';
      });
      html += '</div>';
      if (d.versions.length) html += '<div class="ib-form" style="margin-top:14px">' + field('reason', '訂正の理由（新しい版として保存し、旧版も残します）', '', { type: 'textarea', required: true }) + '</div>';
      html += '<div class="ib-actions"><button class="ib-btn" id="ib-ledger-draft">下書き保存</button><button class="ib-btn ib-btn-primary" id="ib-ledger-pdf"'
        + (d.has_sources.contract && d.has_sources.explanation ? '' : ' disabled') + '>PDFを作成して保存（プレビュー）</button></div></div>';

      html += '<div class="ib-card"><h3>本取引履歴　所有者専用</h3><table class="ib-table is-stack"><thead><tr><th>保存ファイル</th><th>作成日</th><th>操作</th></tr></thead><tbody>'
        + (d.versions.length ? d.versions.map(function (v) {
          return '<tr><td>' + esc(v.file_name) + (v.reason ? '<div class="ib-sub">訂正理由：' + esc(v.reason) + '</div>' : '') + '</td><td>' + esc(fmtDate(v.created_at)) + '</td>'
            + '<td style="white-space:nowrap"><a class="ib-btn ib-btn-sm" target="_blank" rel="noopener" href="' + esc(v.url) + '">閲覧</a> <button class="ib-btn ib-btn-sm" data-lprint="' + esc(v.url) + '">印刷</button></td></tr>';
        }).join('') : '<tr><td colspan="3" class="ib-empty">まだ保存された台帳はありません。</td></tr>')
        + '</tbody></table><p class="ib-note">訂正は新しい版として保存します。顧客や相手仲介には公開されません。</p></div>';
      app.innerHTML = html;

      var formEl = document.getElementById('ib-ledger-form');
      var msgEl = document.getElementById('ib-ledger-msg');
      function collect() { return formValues(formEl); }
      function showMissing(list) {
        formEl.querySelectorAll('.ib-field').forEach(function (fl) { fl.classList.remove('is-missing'); });
        var keys = Object.keys(list || {});
        keys.forEach(function (k) { var i = formEl.querySelector('[name=' + k + ']'); if (i) i.closest('.ib-field').classList.add('is-missing'); });
        msgEl.innerHTML = keys.length ? '<div class="ib-error">未入力の必須項目：' + esc(keys.map(function (k) { return list[k]; }).join('、')) + '</div>' : '';
      }
      showMissing(missing);

      // 上部の要約（契約日・取引終了日・売買金額・自社報酬額）と、自社報酬額の合計の自動計算
      function num(v) { var h = String(v || '').replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); }); return /^[\d,\s円]+$/.test(h) && /\d/.test(h) ? parseInt(h.replace(/\D/g, ''), 10) : null; }
      function refreshSummary() {
        var v = collect();
        var a = num(v.fee_seller_total), c = num(v.fee_buyer_total);
        if (a !== null || c !== null) { v.fee_total = String((a || 0) + (c || 0)); formEl.querySelector('[name=fee_total]').value = v.fee_total; }
        var item = function (label, value) { return '<div class="ib-field"><label>' + esc(label) + '</label><input type="text" readonly value="' + esc(value) + '"></div>'; };
        document.getElementById('ib-ledger-summary').innerHTML = item('契約日', v.contract_date ? jpDate(v.contract_date) : '未入力')
          + item('取引終了日', b.end_date ? jpDate(b.end_date) : '未確定')
          + item('売買金額（円）', num(v.price_total) !== null ? num(v.price_total).toLocaleString('ja-JP') : (v.price_total || '未入力'))
          + item('自社報酬額（税込・円）', num(v.fee_total) !== null ? num(v.fee_total).toLocaleString('ja-JP') : (v.fee_total || '未入力'));
      }
      formEl.addEventListener('input', refreshSummary);
      refreshSummary();
      document.getElementById('ib-ledger-toggle').onclick = function () {
        var hidden = formEl.classList.toggle('ib-hidden');
        this.textContent = hidden ? '台帳の入力内容を確認' : '入力内容を閉じる';
      };

      document.getElementById('ib-autofill').onclick = function () {
        var btn = this;
        var run = function (overwrite) {
          busy(btn, true);
          btn.textContent = '読み取り中…（1分ほどかかる場合があります）';
          post('ledger.php', { action: 'autofill', box_id: BOX_ID, overwrite: overwrite ? 1 : 0 }).then(function (res) {
            busy(btn, false);
            btn.textContent = '契約書・重要事項説明書から自動取得';
            if (!res.success) { msgEl.innerHTML = '<div class="ib-error">' + esc(res.message) + '</div>'; return; }
            Object.keys(res.data.data).forEach(function (k) {
              var i = formEl.querySelector('[name=' + k + ']');
              if (!i) return;
              if (i.type === 'checkbox') i.checked = !!res.data.data[k]; else i.value = res.data.data[k];
            });
            showMissing(res.data.missing);
            refreshSummary();
            formEl.classList.remove('ib-hidden');
            msgEl.insertAdjacentHTML('afterbegin', '<div class="ib-notice">' + esc(res.message) + '<br><span class="ib-note">取得元：' + esc(res.data.sources.join('、')) + '</span>'
              + (res.data.notes.length ? '<br>' + esc(res.data.notes.join(' ')) : '') + '</div>');
          });
        };
        if (d.draft) {
          modal('自動取得', '', '<p>入力済みの内容があります。どのように取得しますか？</p><div class="ib-actions"><button class="ib-btn" data-a="0">空欄だけ埋める</button><button class="ib-btn ib-btn-primary" data-a="1">書類の内容で上書き</button></div>', function (m, close) {
            m.querySelectorAll('[data-a]').forEach(function (x) { x.onclick = function () { close(); run(x.getAttribute('data-a') === '1'); }; });
          });
        } else run(false);
      };
      document.getElementById('ib-ledger-draft').onclick = function () {
        post('ledger.php', { action: 'save_draft', box_id: BOX_ID, data: collect() }).then(function (res) {
          toast(res.message, !res.success);
          if (res.success) showMissing(res.data.missing);
        });
      };
      var genKey = opKey();
      function ledgerFailed(res) {
        if (res.missing) { showMissing(res.missing); formEl.classList.remove('ib-hidden'); }
        else msgEl.innerHTML = '<div class="ib-error">' + esc(res.message) + '</div>';
        toast(res.message, true);
      }
      // プレビューを確認してから確定保存する（仕様 第4章）
      document.getElementById('ib-ledger-pdf').onclick = function () {
        var btn = this;
        var reasonEl = app.querySelector('[name=reason]');
        if (reasonEl && !reasonEl.value.trim()) { toast('訂正の理由を入力してください。', true); reasonEl.focus(); return; }
        var data = collect();
        busy(btn, true);
        post('ledger.php', { action: 'preview', box_id: BOX_ID, data: data }).then(function (res) {
          busy(btn, false);
          if (!res.success) { ledgerFailed(res); return; }
          var bin = atob(res.data.pdf_base64), bytes = new Uint8Array(bin.length);
          for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
          modal('取引台帳のプレビュー', '内容を確認し、問題なければ確定保存してください。確定すると新しい版として保存され、変更できません（訂正は次の版になります）。',
            '<div class="ib-pdf-area" data-area></div>'
            + '<div class="ib-actions"><button class="ib-btn" data-x>修正に戻る</button><button class="ib-btn ib-btn-primary" data-ok>この内容で確定保存</button></div>', function (m, close) {
              renderPdfPages(m.querySelector('[data-area]'), bytes);
              var shut = close;
              m.querySelector('[data-x]').onclick = shut;
              m.querySelector('[data-ok]').onclick = function () {
                var ok = this;
                busy(ok, true);
                post('ledger.php', { action: 'generate', box_id: BOX_ID, data: data, reason: reasonEl ? reasonEl.value : '', op_key: genKey }).then(function (r2) {
                  busy(ok, false);
                  if (!r2.success) { shut(); ledgerFailed(r2); return; }
                  shut();
                  toast(r2.message);
                  renderLedger();
                });
              };
            }, true);
        });
      };
      app.querySelectorAll('[data-lprint]').forEach(function (b) {
        b.onclick = function () {
          var url = b.getAttribute('data-lprint');
          modal('取引台帳', '', '<div class="ib-pdf-area" data-area></div><div class="ib-actions"><button class="ib-btn" data-x>閉じる</button><button class="ib-btn ib-btn-primary" data-p>印刷</button></div>', function (m, close) {
            var pages = renderPdfPages(m.querySelector('[data-area]'), url);
            var doPrint = function () {
              pages.then(function (canvases) {
                if (canvases.length) { printImages('取引台帳', canvases.map(function (c) { return c.toDataURL('image/jpeg', 0.92); })); return; }
                var frame = m.querySelector('iframe');
                try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { window.open(url, '_blank'); }
              });
            };
            m.querySelector('[data-x]').onclick = close;
            m.querySelector('[data-p]').onclick = doPrint;
            doPrint();
          }, true);
        };
      });
    });
  }

  /* ───────── 起動 ───────── */
  if (MODE === 'list') {
    renderList();
  } else {
    document.querySelectorAll('.ib-tab').forEach(function (t) {
      t.onclick = function () { go(t.getAttribute('data-tab')); };
    });
    window.addEventListener('hashchange', route);
    loadBox().then(function (ok) { if (ok) route(); });
  }
})();

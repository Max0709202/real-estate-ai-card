/* 物件選定機能 共通UIコア（window.PropertyUI）。
 * エージェント管理画面（property-agent.js）と顧客チャット（chat-widget.js）の双方で利用する。
 * バッジ・アイコン・物件カード・詳細・ハザード・ギャラリー等の描画を担う。 */
(function (w) {
  'use strict';
  if (w.PropertyUI) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  /* ステータス定義（PHP propertyStatusDefs と一致） */
  var STATUS = {
    viewing_request:  { label: '内見希望',   role: 'customer', color: '#e8384f', icon: 'viewing' },
    considering:      { label: '検討中',     role: 'customer', color: '#2d6cdf', icon: 'considering' },
    passed:           { label: '見送り',     role: 'customer', color: '#8a8f98', icon: 'passed' },
    application:      { label: '申込検討',   role: 'customer', color: '#f08a24', icon: 'application' },
    brokerage_ok:     { label: '仲介可',     role: 'agent',    color: '#1f9d57', icon: 'brokerage' },
    not_introducible: { label: 'ご紹介不可', role: 'agent',    color: '#6b3fd1', icon: 'notintro' }
  };

  /* 基本情報フィールド定義（PHP propertyFieldDefs と一致）
     [key, label, group, types(空=全), agentOnly] */
  var FIELDS = [
    ['property_name', '物件名', 'basic', [], false],
    ['building_name', 'マンション名', 'basic', [], false],
    ['price_text', '価格', 'basic', [], false],
    ['address', '所在地', 'basic', [], false],
    ['transport', '交通', 'basic', [], false],
    ['exclusive_area', '専有面積', 'basic', ['mansion'], false],
    ['land_area', '土地面積', 'basic', ['house', 'land'], false],
    ['building_area', '建物面積', 'basic', ['house'], false],
    ['balcony_area', 'バルコニー面積', 'basic', ['mansion'], false],
    ['layout', '間取り', 'basic', [], false],
    ['built_year_month', '築年月', 'basic', [], false],
    ['floor', '所在階', 'basic', ['mansion'], false],
    ['room_number', '部屋番号', 'basic', ['mansion'], false],
    ['total_units', '総戸数', 'basic', ['mansion'], false],
    ['structure', '構造', 'basic', [], false],
    ['land_right', '土地権利', 'basic', [], false],
    ['management_form', '管理形態', 'basic', ['mansion'], false],
    ['management_company', '管理会社', 'basic', ['mansion'], false],
    ['management_fee', '管理費', 'basic', ['mansion'], false],
    ['repair_reserve', '修繕積立金', 'basic', ['mansion'], false],
    ['other_fees', 'その他費用', 'basic', [], false],
    ['current_status', '現況', 'basic', [], false],
    ['delivery', '引渡', 'basic', [], false],
    ['transaction_type', '取引態様', 'basic', [], false],
    ['rent', '賃料', 'basic', [], false],
    ['yield_rate', '利回り', 'basic', [], false],
    ['remarks', '備考', 'basic', [], false],
    ['seller_company', '販売会社名', 'seller', [], true],
    ['seller_branch', '支店名', 'seller', [], true],
    ['seller_person', '担当者名', 'seller', [], true],
    ['seller_email', 'メールアドレス', 'seller', [], true],
    ['seller_phone', '販売会社電話番号', 'seller', [], true],
    ['seller_remarks', '備考', 'seller', [], true]
  ];

  var TYPES = { mansion: 'マンション', house: '一戸建て', land: '土地' };

  /* ===== インラインSVGアイコン（currentColor） ===== */
  var ICONS = {
    viewing: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="M9 15l2 2 4-4"/></svg>',
    considering: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
    passed: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
    application: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13l2 2 4-4"/></svg>',
    brokerage: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 11l2.5 2.5a2 2 0 0 0 2.8 0L20 7"/><path d="M2 9l4-4 5 4M22 9l-4-4-4 3"/><path d="M11 13l2 2 2-2 2 2"/></svg>',
    notintro: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>',
    agent: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.3 0-8 1.7-8 5v1h16v-1c0-3.3-4.7-5-8-5z"/></svg>',
    customer: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3zM8 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm0 2c-2.7 0-6 1.3-6 4v2h8v-2c0-1 .4-1.9 1.1-2.6A9.4 9.4 0 0 0 8 13zm8 0c-.5 0-1 0-1.5.1A4.3 4.3 0 0 1 16 17v2h6v-2c0-2.7-3.3-4-6-4z"/></svg>',
    upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><path d="M12 15V3M7 8l5-5 5 5"/></svg>',
    camera: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L19 6h0a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="4"/></svg>',
    manual: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
    url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/></svg>',
    heart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1L12 21l8.8-8.3a5 5 0 0 0 0-7.1z"/></svg>',
    chev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>',
    refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/></svg>',
    warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
    map: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2z"/><path d="M9 4v14M15 6v14"/></svg>',
    calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>'
  };

  function icon(name) { return ICONS[name] || ''; }

  /* ===== 提案元ラベル（§3） ===== */
  function sourceHtml(p) {
    var cust = p.source === 'customer';
    var cls = cust ? 'orange' : 'blue';
    var ic = icon(cust ? 'customer' : 'agent');
    var label = esc(p.source_label || (cust ? 'お客様から共有' : 'エージェント提案'));
    // お客様から共有 かつ URL取得元がある場合は別窓リンク（§3）
    if (cust && p.source_url) {
      return '<a class="prop-source prop-source--' + cls + '" href="' + esc(p.source_url) +
        '" target="_blank" rel="noopener noreferrer">' + ic + label + '</a>';
    }
    return '<span class="prop-source prop-source--' + cls + '">' + ic + label + '</span>';
  }

  /* ===== ステータスバッジ（§5） ===== */
  function statusBadgeHtml(p) {
    if (!p.status || !STATUS[p.status]) return '';
    var s = STATUS[p.status];
    var bg = hexToTint(s.color);
    return '<span class="prop-badge" style="background:' + bg + ';color:' + s.color + '">' +
      '<span class="prop-badge--icon" style="color:' + s.color + '">' + icon(s.icon) + '</span>' +
      esc(s.label) + '</span>';
  }

  function hexToTint(hex) {
    var h = hex.replace('#', '');
    var r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',0.12)';
  }

  /* ===== 物件カード（§1 / §4） ===== */
  function cardHtml(p, opts) {
    opts = opts || {};
    var name = esc(p.building_name || p.property_name || '（名称未取得）');
    var thumb = p.main_image_url
      ? '<div class="prop-card__thumb" style="background-image:url(' + esc(addAuth(p.main_image_url, opts)) + ')"></div>'
      : '<div class="prop-card__thumb prop-card__thumb--empty">No Image</div>';
    var meta = [];
    if (p.address) meta.push(esc(p.address));
    var line2 = [];
    if (p.layout) line2.push(esc(p.layout));
    if (p.exclusive_area) line2.push(esc(p.exclusive_area));
    else if (p.land_area) line2.push('土地' + esc(p.land_area));
    if (p.built_year_month) line2.push(esc(p.built_year_month));
    if (p.current_status) line2.push(esc(p.current_status));
    if (line2.length) meta.push(line2.join('｜'));
    var labels = sourceHtml(p);
    var badge = statusBadgeHtml(p);
    var fav = opts.fav ? '<span class="prop-card__fav">' + icon('heart') + '</span>' : '';
    return '<div class="prop-card" data-prop-id="' + p.id + '">' +
      thumb +
      '<div class="prop-card__body">' +
        '<div class="prop-card__labels">' + labels + (badge || '') + '</div>' +
        '<div class="prop-card__name">' + name + '</div>' +
        (p.price_text ? '<div class="prop-card__price">' + esc(p.price_text) + '</div>' : '') +
        '<div class="prop-card__meta">' + meta.join('<br>') + '</div>' +
      '</div>' + fav +
    '</div>';
  }

  /* 認証付き画像URLに session/visitor を付与（顧客側で必要） */
  function addAuth(url, opts) {
    if (!opts || (!opts.sessionId && !opts.visitorId)) return url;
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    var q = [];
    if (opts.sessionId) q.push('session_id=' + encodeURIComponent(opts.sessionId));
    if (opts.visitorId) q.push('visitor_id=' + encodeURIComponent(opts.visitorId));
    return url + sep + q.join('&');
  }

  /* ===== 詳細ヘッダ（§9） ===== */
  function detailHeaderHtml(p) {
    var name = esc(p.building_name || p.property_name || '（名称未取得）');
    var sub = [];
    if (p.layout) sub.push(esc(p.layout));
    if (p.exclusive_area) sub.push(esc(p.exclusive_area));
    else if (p.land_area) sub.push('土地' + esc(p.land_area));
    return '<div class="prop-detail__header">' +
      '<div class="prop-detail__title">' + name + '</div>' +
      (p.price_text ? '<div class="prop-detail__price">' + esc(p.price_text) + '</div>' : '') +
      (sub.length ? '<div class="prop-detail__sub">' + sub.join('｜') + '</div>' : '') +
      '<div class="prop-detail__badges">' + sourceHtml(p) + (statusBadgeHtml(p) || '') + '</div>' +
    '</div>';
  }

  /* ===== 基本情報テーブル（§11・空欄は詰めて非表示） ===== */
  function basicInfoHtml(p, isAgent) {
    var type = p.property_type || 'mansion';
    var rows = '';
    FIELDS.forEach(function (f) {
      var key = f[0], label = f[1], group = f[2], types = f[3], agentOnly = f[4];
      if (group !== 'basic') return;
      if (agentOnly && !isAgent) return;
      if (types.length && types.indexOf(type) < 0) return;
      var v = p[key];
      if (v == null || String(v).trim() === '') return; // §11 上下を詰める
      if (key === 'property_name' || key === 'building_name' || key === 'price_text') return; // ヘッダ表示済
      rows += '<tr><th>' + esc(label) + '</th><td>' + esc(v).replace(/\n/g, '<br>') + '</td></tr>';
    });
    var html = '<table class="prop-info"><tbody>' + (rows || '<tr><td>情報がありません。</td></tr>') + '</tbody></table>';

    // 売主仲介会社情報（担当のみ §11/§19）
    if (isAgent) {
      var s = '';
      FIELDS.forEach(function (f) {
        if (f[2] !== 'seller') return;
        var key = f[0], label = f[1], v = p[key];
        if (v == null || String(v).trim() === '') return;
        var td;
        if (key === 'seller_email') td = '<a href="mailto:' + esc(v) + '">' + esc(v) + '</a>';
        else if (key === 'seller_phone') td = '<a href="tel:' + esc(String(v).replace(/[^0-9+]/g, '')) + '">' + esc(v) + '</a>';
        else td = esc(v).replace(/\n/g, '<br>');
        s += '<tr><th>' + esc(label) + '</th><td>' + td + '</td></tr>';
      });
      if (s) {
        html += '<div class="prop-section-title">＜売主仲介会社情報＞</div>' +
          '<div class="prop-seller-note">※ この情報はエージェント画面にのみ表示されます</div>' +
          '<table class="prop-info"><tbody>' + s + '</tbody></table>';
      }
    }
    return html;
  }

  /* ===== ハザード（§12/§13） ===== */
  function hazardHtml(hazard, fetchedAt) {
    if (!hazard) return '<div class="prop-empty">ハザード情報は未取得です。</div>';
    var items = (hazard.items || []);
    var meta = '<div class="prop-hazard-meta"><span>取得件数: ' + (hazard.record_count || items.length || 0) + '件</span>' +
      (fetchedAt ? '<span>更新: ' + esc(String(fetchedAt).replace('T', ' ').substr(0, 16)) + '</span>' : '') + '</div>';
    if (!items.length) {
      return meta + '<div class="prop-empty">' + esc(hazard.message || '該当するハザードデータはありませんでした。') + '</div>';
    }
    var rows = items.map(function (it) {
      var label = esc(it.title || it.label || it.name || it.code || '項目');
      var val = it.count_note || it.summary || it.value || it.text || it.scope_note || '該当あり';
      if (Array.isArray(val)) val = val.join(' / ');
      else if (typeof val === 'object') val = JSON.stringify(val);
      return '<div class="prop-hazard-row"><span class="prop-hazard-row__icon">' + icon('warn') + '</span>' +
        '<span class="prop-hazard-row__label">' + label + '</span>' +
        '<span class="prop-hazard-row__val">' + esc(String(val)).substr(0, 80) + '</span></div>';
    }).join('');
    return meta + '<div class="prop-hazard-list">' + rows + '</div>';
  }

  /* ===== 画像ギャラリー（§14/§15） ===== */
  function galleryHtml(images, opts) {
    opts = opts || {};
    if (!images || !images.length) return '<div class="prop-empty">' + (opts.emptyText || '画像はまだありません。') + '</div>';
    return '<div class="prop-gallery">' + images.map(function (im) {
      var url = addAuth(im.url, opts);
      var isImg = !im.mime_type || im.mime_type.indexOf('image/') === 0;
      var inner = isImg
        ? '<img src="' + esc(url) + '" alt="" loading="lazy" data-full="' + esc(url) + '">'
        : '<div class="prop-thumb__pdf" data-full="' + esc(url) + '">PDF</div>';
      var del = opts.removable ? '<button type="button" class="prop-thumb__del" data-del-img="' + im.id + '">×</button>' : '';
      return '<div class="prop-thumb">' + inner + del + '</div>';
    }).join('') + '</div>';
  }

  function lightbox(url) {
    var ov = document.createElement('div');
    ov.className = 'prop-lightbox';
    ov.innerHTML = '<button class="prop-lightbox__close">×</button><img src="' + esc(url) + '" alt="">';
    ov.addEventListener('click', function () { document.body.removeChild(ov); });
    document.body.appendChild(ov);
  }

  /* クリックで拡大（イベント委譲のヘルパ） */
  function bindLightbox(container) {
    container.addEventListener('click', function (e) {
      var t = e.target.closest('[data-full]');
      if (t) lightbox(t.getAttribute('data-full'));
    });
  }

  /* ===== モーダル ===== */
  function modal(title, bodyHtml) {
    var ov = document.createElement('div');
    ov.className = 'prop-modal-overlay';
    ov.innerHTML = '<div class="prop-modal prop-wrap"><div class="prop-modal__head"><h3>' + esc(title) +
      '</h3><button type="button" class="prop-modal__close" aria-label="閉じる">×</button></div>' +
      '<div class="prop-modal__body"></div></div>';
    var body = ov.querySelector('.prop-modal__body');
    body.innerHTML = bodyHtml || '';
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    ov.querySelector('.prop-modal__close').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.body.appendChild(ov);
    return { overlay: ov, body: body, close: close };
  }

  w.PropertyUI = {
    esc: esc, icon: icon, STATUS: STATUS, FIELDS: FIELDS, TYPES: TYPES,
    sourceHtml: sourceHtml, statusBadgeHtml: statusBadgeHtml, cardHtml: cardHtml,
    detailHeaderHtml: detailHeaderHtml, basicInfoHtml: basicInfoHtml, hazardHtml: hazardHtml,
    galleryHtml: galleryHtml, lightbox: lightbox, bindLightbox: bindLightbox, modal: modal,
    addAuth: addAuth, hexToTint: hexToTint
  };
})(window);

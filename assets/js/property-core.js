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

  /* 登録日（時間は表示しない）。created_at（例:"2026-06-24 12:34:56" / ISO）→ "2026/06/24"。 */
  function formatDate(ts) {
    if (!ts) return '';
    var m = String(ts).match(/(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
    if (!m) return '';
    return m[1] + '/' + ('0' + m[2]).slice(-2) + '/' + ('0' + m[3]).slice(-2);
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

  /* 見送り(passed)理由の選択肢（PHP propertyPassReasonDefs と一致） */
  var PASS_REASONS = {
    price:      '価格・予算が合わない',
    location:   '立地・周辺環境が希望と合わない',
    layout:     '間取り・広さ・使い勝手が合わない',
    condition:  '建物・土地の状態に不安がある',
    renovation: 'リフォーム・修繕に費用がかかりそう',
    other:      'その他'
  };
  function passReasonLabel(code) { return PASS_REASONS[code] || ''; }

  /* 基本情報フィールド定義（PHP propertyFieldDefs と一致）
     [key, label, group, types(空=全), agentOnly] */
  var FIELDS = [
    ['building_name', '物件名', 'basic', [], false],
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
    ['rent', '賃料', 'basic', [], false],
    ['yield_rate', '利回り', 'basic', [], false],
    ['remarks', '備考', 'basic', [], false],
    ['seller_company', '販売会社名', 'seller', [], true],
    ['seller_branch', '支店名', 'seller', [], true],
    ['seller_person', '担当者名', 'seller', [], true],
    ['seller_email', 'メールアドレス', 'seller', [], true],
    ['seller_phone', '販売会社電話番号', 'seller', [], true],
    ['transaction_type', '取引態様', 'seller', [], true],
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

  /* ===== ステータスバッジ（§5） =====
     interactive=true かつ 見送り理由がある場合、バッジをタップで理由を確認できるボタンにする（詳細画面のみ）。 */
  function statusBadgeHtml(p, interactive) {
    if (!p.status || !STATUS[p.status]) return '';
    var s = STATUS[p.status];
    var bg = hexToTint(s.color);
    var clickable = !!interactive && p.status === 'passed' && !!p.pass_reason;
    var attrs = 'class="prop-badge' + (clickable ? ' prop-badge--reason' : '') +
      '" style="background:' + bg + ';color:' + s.color + '"';
    if (clickable) attrs += ' data-pass-reason="1" role="button" tabindex="0" title="見送り理由を見る"';
    return '<span ' + attrs + '>' +
      '<span class="prop-badge--icon" style="color:' + s.color + '">' + icon(s.icon) + '</span>' +
      esc(s.label) + (clickable ? '<span class="prop-badge__more">理由 ›</span>' : '') + '</span>';
  }

  function hexToTint(hex) {
    var h = hex.replace('#', '');
    var r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',0.12)';
  }

  /* 住所から戸建/土地の物件名を推定（例: 埼玉県川口市弥平2丁目 → 川口市弥平戸建て）。building_name 未取得時のフォールバック。 */
  function deriveNameFromAddress(p) {
    var a = (p.address || '').trim();
    if (!a) return '';
    a = a.replace(/^(北海道|東京都|京都府|大阪府|.{2,3}県)/, '');     // 都道府県を除去
    a = a.replace(/[0-9０-９一二三四五六七八九十]+\s*(丁目|番地|番|号).*$/, ''); // 丁目・番地以降を除去
    a = a.replace(/[0-9０-９][\-－0-9０-９\s].*$/, '');                // 末尾の番地数字を除去
    a = a.replace(/[\s　]+$/, '').trim();
    if (!a) return '';
    return a + (p.property_type === 'land' ? '土地' : '戸建て');
  }

  /* 物件名: building_name（マンション=名称）優先。戸建/土地で未取得なら住所から推定。 */
  function displayName(p) {
    if (p.building_name && String(p.building_name).trim() !== '') return String(p.building_name);
    if (p.property_type === 'house' || p.property_type === 'land') {
      var d = deriveNameFromAddress(p);
      if (d) return d;
    }
    if (p.property_name && String(p.property_name).trim() !== '') return String(p.property_name); // 旧データ互換
    return '（名称未取得）';
  }

  /* ===== 物件カード（§1 / §4） ===== */
  function cardHtml(p, opts) {
    opts = opts || {};
    var name = esc(displayName(p));
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
    var regDate = formatDate(p.created_at);
    var dateHtml = regDate ? '<div class="prop-card__date">登録日 ' + esc(regDate) + '</div>' : '';
    return '<div class="prop-card" data-prop-id="' + p.id + '">' +
      thumb +
      '<div class="prop-card__body">' +
        '<div class="prop-card__labels">' + labels + (badge || '') + '</div>' +
        '<div class="prop-card__name">' + name + '</div>' +
        (p.price_text ? '<div class="prop-card__price">' + esc(p.price_text) + '</div>' : '') +
        '<div class="prop-card__meta">' + meta.join('<br>') + '</div>' +
        dateHtml +
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
    var name = esc(displayName(p));
    var sub = [];
    if (p.layout) sub.push(esc(p.layout));
    if (p.exclusive_area) sub.push(esc(p.exclusive_area));
    else if (p.land_area) sub.push('土地' + esc(p.land_area));
    var regDate = formatDate(p.created_at);
    return '<div class="prop-detail__header">' +
      '<div class="prop-detail__title">' + name + '</div>' +
      (p.price_text ? '<div class="prop-detail__price">' + esc(p.price_text) + '</div>' : '') +
      (sub.length ? '<div class="prop-detail__sub">' + sub.join('｜') + '</div>' : '') +
      '<div class="prop-detail__badges">' + sourceHtml(p) + (statusBadgeHtml(p, true) || '') + '</div>' +
      (regDate ? '<div class="prop-detail__date">登録日 ' + esc(regDate) + '</div>' : '') +
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
      // GPTが生成した自然な説明文（summary）を最優先で表示。無い場合は従来の説明文へフォールバック。
      var val = it.summary || it.count_note || it.value || it.text || it.scope_note || '該当あり';
      if (Array.isArray(val)) val = val.join(' / ');
      else if (typeof val === 'object') val = JSON.stringify(val);
      return '<div class="prop-hazard-row"><span class="prop-hazard-row__icon">' + icon('warn') + '</span>' +
        '<span class="prop-hazard-row__label">' + label + '</span>' +
        '<span class="prop-hazard-row__val">' + esc(String(val)).substr(0, 300) + '</span></div>';
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
      var inner;
      if (isImg) {
        inner = '<img src="' + esc(url) + '" alt="" loading="lazy" data-full="' + esc(url) + '">';
      } else if (im.thumb_url) {
        // 販売図面PDF: マスク済みの縮小画像をサムネイル表示。タップでビューア（戻るボタン付き）。
        var t = addAuth(im.thumb_url, opts);
        inner = '<img src="' + esc(t) + '" alt="販売図面" loading="lazy" data-pdf="' + esc(url) + '" data-img="' + esc(t) + '"><span class="prop-thumb__badge">販売図面</span>';
      } else {
        inner = '<a class="prop-thumb__pdf" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">PDF</a>';
      }
      var del = opts.removable ? '<button type="button" class="prop-thumb__del" data-del-img="' + im.id + '">×</button>' : '';
      return '<div class="prop-thumb">' + inner + del + '</div>';
    }).join('') + '</div>';
  }

  /* 画像の拡大ビューア。＋/−（およびタップ）でズーム、スクロールで移動。PC・スマホ対応。 */
  function lightbox(url) {
    var scale = 1;
    var ov = document.createElement('div');
    ov.className = 'prop-lightbox';
    ov.innerHTML =
      '<div class="prop-lightbox__bar">' +
        '<button type="button" class="prop-lb-btn" data-lb="out" aria-label="縮小">−</button>' +
        '<button type="button" class="prop-lb-btn" data-lb="in" aria-label="拡大">＋</button>' +
        '<button type="button" class="prop-lb-btn prop-lightbox__close" aria-label="閉じる">×</button>' +
      '</div>' +
      '<div class="prop-lightbox__body"><img src="' + esc(url) + '" alt=""></div>';
    var img = ov.querySelector('img');
    var body = ov.querySelector('.prop-lightbox__body');
    function apply() { img.style.width = (scale * 100) + '%'; }
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    ov.querySelector('.prop-lightbox__close').addEventListener('click', close);
    ov.querySelector('[data-lb="in"]').addEventListener('click', function (e) { e.stopPropagation(); scale = Math.min(6, scale + 0.5); apply(); });
    ov.querySelector('[data-lb="out"]').addEventListener('click', function (e) { e.stopPropagation(); scale = Math.max(1, scale - 0.5); apply(); });
    img.addEventListener('click', function (e) { e.stopPropagation(); scale = scale > 1 ? 1 : 2; apply(); });
    body.addEventListener('click', function (e) { if (e.target === body) close(); });
    document.body.appendChild(ov);
  }

  /* 販売図面ビューア（戻る／×ボタン付き）。マスク済み画像を表示し、PDFを開くリンクも提供。 */
  function pdfViewer(pdfUrl, imgUrl) {
    var ov = document.createElement('div');
    ov.className = 'prop-pdfviewer';
    ov.innerHTML =
      '<div class="prop-pdfviewer__bar">' +
        '<button type="button" class="prop-pdfviewer__back" aria-label="戻る">← 戻る</button>' +
        '<span class="prop-pdfviewer__title">販売図面</span>' +
        (pdfUrl ? '<a class="prop-pdfviewer__open" href="' + esc(pdfUrl) + '" target="_blank" rel="noopener noreferrer">PDFを開く</a>' : '<span></span>') +
        '<button type="button" class="prop-pdfviewer__close" aria-label="閉じる">×</button>' +
      '</div>' +
      '<div class="prop-pdfviewer__body">' + (imgUrl ? '<img src="' + esc(imgUrl) + '" alt="販売図面">' : '') + '</div>';
    function close() { if (ov.parentNode) ov.parentNode.removeChild(ov); }
    ov.querySelector('.prop-pdfviewer__back').addEventListener('click', close);
    ov.querySelector('.prop-pdfviewer__close').addEventListener('click', close);
    ov.querySelector('.prop-pdfviewer__body').addEventListener('click', function (e) { if (e.target === this) close(); });
    document.body.appendChild(ov);
  }

  /* クリックで拡大／ビューア表示（イベント委譲のヘルパ） */
  function bindLightbox(container) {
    container.addEventListener('click', function (e) {
      var pdf = e.target.closest('[data-pdf]');
      if (pdf) { pdfViewer(pdf.getAttribute('data-pdf'), pdf.getAttribute('data-img')); return; }
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

  /* ===== 見送り理由: 選択モーダル（顧客が「見送り」を選んだとき） =====
     opts: { current: {reason, text}, onConfirm: fn({reason, text}) } */
  function passReasonPicker(opts) {
    opts = opts || {};
    var cur = opts.current || {};
    var rows = Object.keys(PASS_REASONS).map(function (code) {
      var on = cur.reason === code ? ' checked' : '';
      return '<label class="prop-reason-opt">' +
        '<input type="radio" name="prop-pass-reason" value="' + code + '"' + on + '>' +
        '<span class="prop-reason-opt__text">' + esc(PASS_REASONS[code]) + '</span></label>';
    }).join('');
    var showOther = cur.reason === 'other';
    var otherVal = showOther ? esc(cur.text || '') : '';
    var html =
      '<div class="prop-reason-note">見送りの理由をお選びください。担当者の物件提案の参考になります。</div>' +
      '<div class="prop-reason-list">' + rows + '</div>' +
      '<textarea class="prop-reason-other" id="prop-reason-other" rows="2" maxlength="500" ' +
        'placeholder="差し支えなければ理由をご記入ください（任意）"' +
        (showOther ? '' : ' style="display:none"') + '>' + otherVal + '</textarea>' +
      '<div class="prop-form-actions">' +
        '<button type="button" class="prop-btn prop-btn--ghost" data-reason-cancel>キャンセル</button>' +
        '<button type="button" class="prop-btn prop-btn--primary" data-reason-ok' + (cur.reason ? '' : ' disabled') + '>見送りを登録</button>' +
      '</div>';
    var m = modal('見送りの理由', html);
    var other = m.body.querySelector('#prop-reason-other');
    var okBtn = m.body.querySelector('[data-reason-ok]');
    m.body.querySelectorAll('input[name="prop-pass-reason"]').forEach(function (r) {
      r.addEventListener('change', function () {
        okBtn.disabled = false;
        var isOther = r.value === 'other';
        other.style.display = isOther ? '' : 'none';
        if (isOther) other.focus();
      });
    });
    m.body.querySelector('[data-reason-cancel]').addEventListener('click', m.close);
    okBtn.addEventListener('click', function () {
      var sel = m.body.querySelector('input[name="prop-pass-reason"]:checked');
      if (!sel) return;
      m.close();
      if (typeof opts.onConfirm === 'function') {
        opts.onConfirm({ reason: sel.value, text: sel.value === 'other' ? (other.value || '').trim() : '' });
      }
    });
    return m;
  }

  /* ===== 見送り理由: 表示モーダル（管理画面で「見送り」を押したとき） ===== */
  function showPassReason(p) {
    var label = passReasonLabel(p && p.pass_reason) || (p && p.pass_reason_label) || '（理由未選択）';
    var text = (p && p.pass_reason_text) ? '<div class="prop-reason-view__text">' + esc(p.pass_reason_text) + '</div>' : '';
    return modal('見送りの理由',
      '<div class="prop-reason-view"><div class="prop-reason-view__label">' + esc(label) + '</div>' + text + '</div>');
  }

  w.PropertyUI = {
    esc: esc, icon: icon, formatDate: formatDate, STATUS: STATUS, FIELDS: FIELDS, TYPES: TYPES,
    PASS_REASONS: PASS_REASONS, passReasonLabel: passReasonLabel,
    passReasonPicker: passReasonPicker, showPassReason: showPassReason,
    sourceHtml: sourceHtml, statusBadgeHtml: statusBadgeHtml, cardHtml: cardHtml,
    detailHeaderHtml: detailHeaderHtml, basicInfoHtml: basicInfoHtml, hazardHtml: hazardHtml,
    galleryHtml: galleryHtml, lightbox: lightbox, pdfViewer: pdfViewer, bindLightbox: bindLightbox, modal: modal,
    addAuth: addAuth, hexToTint: hexToTint
  };
})(window);

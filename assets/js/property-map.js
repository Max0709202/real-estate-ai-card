/* 物件詳細「マップ・周辺情報」（window.PropertyMap）。
 * マップ情報表示依頼 2026.9.3 の実装。
 * 担当のマイページ（property-agent.js）と顧客のAIエージェント（chat-widget.js）の双方から使う。
 *
 * 画面の考え方
 *  - 開いた時点では「現在の物件」「検討中物件」「Googleマップ」だけを表示し、
 *    Places API は一切呼ばない（§11）。
 *  - 周辺情報の10ボタンは、押したときに初めて必要なデータを取得する（§4・§11）。
 *  - 一度取得したカテゴリーは同じ画面内で保持し、OFF→ONで再取得しない（§10）。
 *  - 施設に表示するのは「施設名」と「Googleマップで見る」だけ。
 *    徒歩時間・距離・公式サイトURLは表示しない（§5・§12）。
 *
 * 依存: Google Maps JavaScript API（キーは map.php がサーバーから渡す）
 */
(function (w) {
  'use strict';
  if (w.PropertyMap) return;

  var UI = w.PropertyUI;

  /* ===== Google Maps JavaScript API のローダー（1ページで1回だけ読み込む） ===== */
  var mapsPromise = null;
  function loadMaps(apiKey) {
    if (mapsPromise) return mapsPromise;
    if (w.google && w.google.maps && w.google.maps.Map) {
      mapsPromise = Promise.resolve(w.google.maps);
      return mapsPromise;
    }
    mapsPromise = new Promise(function (resolve, reject) {
      if (!apiKey) { reject(new Error('no-key')); return; }
      var cbName = '__propertyMapReady';
      w[cbName] = function () { resolve(w.google.maps); };
      var s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey) +
        '&language=ja&region=JP&loading=async&callback=' + cbName;
      s.async = true;
      s.defer = true;
      s.onerror = function () { reject(new Error('load-failed')); };
      document.head.appendChild(s);
    });
    return mapsPromise;
  }

  /* ===== ピンの見た目 ===== */
  // 物件ピン（雫型）。現在の物件は赤・大きめ、検討中物件は青・通常サイズ（§2・§3）。
  var PIN_PATH = 'M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7z';
  // 指定緊急避難場所は通常の周辺施設と区別できる形にする（§8⑨）。
  var SHELTER_PATH = 'M12 3l9 17H3z';

  /* カテゴリーごとのピンの色（凡例にも使う） */
  var CAT_COLOR = {
    hazard: '#8a94a6',
    station: '#2d6cdf',
    store: '#f08a24',
    hospital: '#d93f8c',
    school: '#0f9b8e',
    school_district: '#1f9d57',
    cram: '#7b4bd1',
    restaurant: '#c2571a',
    shelter: '#1f9d57',
    caution: '#5b6675'
  };

  function propertyIcon(maps, color, scale) {
    return {
      path: PIN_PATH,
      fillColor: color,
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
      scale: scale,
      anchor: new maps.Point(12, 22)
    };
  }
  function facilityIcon(maps, color) {
    return {
      path: maps.SymbolPath.CIRCLE,
      fillColor: color,
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
      scale: 7
    };
  }
  function shelterIcon(maps, color) {
    return {
      path: SHELTER_PATH,
      fillColor: color,
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
      scale: 1.3,
      anchor: new maps.Point(12, 17)
    };
  }

  function esc(s) { return UI ? UI.esc(s) : String(s == null ? '' : s); }

  /* ===== 本体 ===== */
  /* opts:
   *   propertyId     物件ID
   *   apiBase        物件APIのベースURL（.../backend/api/property）
   *   authQS         認証用クエリ（顧客: session_id&visitor_id / 閲覧トークン / 担当: 空文字）
   *   credentials    fetch の credentials（担当は 'include'）
   *   onOpenProperty 検討中物件の「物件詳細を見る」を押したときのコールバック（id）
   */
  function mount(container, opts) {
    opts = opts || {};
    var apiBase = opts.apiBase || (w.location.origin + '/backend/api/property');
    var authQS = opts.authQS || '';
    var credentials = opts.credentials || 'same-origin';
    var propertyId = opts.propertyId;

    // 取得済みの周辺情報（同じ画面内では再取得しない・§10）
    var CACHE = {};
    // 現在ONになっているカテゴリー（複数同時ON可・§4）
    var ACTIVE = {};
    // カテゴリーごとに地図へ置いたオブジェクト（OFFで消せるように保持）
    var OVERLAYS = {};
    var CATEGORIES = [];
    var maps = null, map = null, infoWindow = null;
    var currentMarker = null;
    var propertyMarkers = [];
    var bootstrap = null;

    container.innerHTML = '<div class="prop-map"><div class="prop-map__loading"><span class="prop-spinner"></span> マップを読み込んでいます…</div></div>';
    var root = container.querySelector('.prop-map');

    function api(path) {
      var url = apiBase + path + (authQS ? (path.indexOf('?') >= 0 ? '&' : '?') + authQS : '');
      return fetch(url, { credentials: credentials }).then(function (r) { return r.json(); });
    }

    function fail(message) {
      root.innerHTML = '<div class="prop-empty">' + esc(message) + '</div>';
    }

    api('/map.php?id=' + encodeURIComponent(propertyId))
      .then(function (res) {
        if (!res || !res.success || !res.data) { fail((res && res.message) || 'マップ情報を取得できませんでした。'); return; }
        bootstrap = res.data;
        CATEGORIES = bootstrap.categories || [];
        if (!bootstrap.property) { fail(bootstrap.message || 'この物件の位置を特定できませんでした。'); return; }
        if (!bootstrap.maps_api_key) { fail('地図の表示設定が未完了です（Google Maps APIキーが設定されていません）。'); return; }
        return loadMaps(bootstrap.maps_api_key).then(initMap).catch(function () {
          fail('Googleマップを読み込めませんでした。通信環境をご確認ください。');
        });
      })
      .catch(function () { fail('マップ情報の取得に失敗しました。'); });

    /* ===== 初期描画（§1・§2・§3） ===== */
    function initMap(gmaps) {
      maps = gmaps;
      root.innerHTML =
        '<div class="prop-map__bar" role="group" aria-label="周辺情報の表示切り替え">' + buttonsHtml() + '</div>' +
        '<div class="prop-map__canvas"></div>' +
        '<div class="prop-map__legend" hidden></div>' +
        '<div class="prop-map__notes" hidden></div>';

      var canvas = root.querySelector('.prop-map__canvas');
      var center = { lat: bootstrap.property.lat, lng: bootstrap.property.lng };
      // 操作感はできるだけ通常のGoogleマップと同じにする（PC・スマートフォン両対応・§1）。
      map = new maps.Map(canvas, {
        center: center,
        zoom: 16,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: true,
        clickableIcons: false,
        gestureHandling: 'greedy',
        zoomControl: true
      });
      infoWindow = new maps.InfoWindow();
      maps.event.addListener(infoWindow, 'domready', bindInfoWindowLinks);

      // 現在見ている物件: 赤色の目立つピン（軽く上下に跳ねる・§2）
      currentMarker = new maps.Marker({
        map: map,
        position: center,
        icon: propertyIcon(maps, '#e8384f', 2.0),
        zIndex: 1000,
        animation: maps.Animation.BOUNCE,
        title: bootstrap.property.name
      });
      currentMarker.addListener('click', function () {
        openInfo(currentMarker, propertyInfoHtml(bootstrap.property));
      });

      // 検討中の物件: 通常の物件ピン（§3）
      (bootstrap.considering || []).forEach(function (p) {
        var m = new maps.Marker({
          map: map,
          position: { lat: p.lat, lng: p.lng },
          icon: propertyIcon(maps, '#2d6cdf', 1.5),
          zIndex: 900,
          title: p.name
        });
        m.addListener('click', function () { openInfo(m, propertyInfoHtml(p)); });
        propertyMarkers.push(m);
      });

      bindButtons();
      renderNotes();
    }

    /* ===== 周辺情報ボタン（§4） ===== */
    function buttonsHtml() {
      var html = CATEGORIES.map(function (c) {
        return '<button type="button" class="prop-map__cat" data-map-cat="' + esc(c.key) + '"' +
          ' style="--cat-color:' + esc(CAT_COLOR[c.key] || '#2d6cdf') + '"' +
          ' aria-pressed="false">' + esc(c.label) + '</button>';
      }).join('');
      // 検討中物件がある場合だけ「全体表示」を出す。初期表示は必ず現在の物件が中心（§1）。
      if ((bootstrap.considering || []).length) {
        html += '<button type="button" class="prop-map__fit" data-map-fit="1">検討中物件も含めて表示</button>';
      }
      return html;
    }

    function bindButtons() {
      root.querySelectorAll('[data-map-cat]').forEach(function (b) {
        b.addEventListener('click', function () { toggleCategory(b.getAttribute('data-map-cat'), b); });
      });
      var fit = root.querySelector('[data-map-fit]');
      if (fit) fit.addEventListener('click', fitProperties);
    }

    function fitProperties() {
      var bounds = new maps.LatLngBounds();
      bounds.extend(currentMarker.getPosition());
      propertyMarkers.forEach(function (m) { bounds.extend(m.getPosition()); });
      map.fitBounds(bounds, 48);
    }

    /* 同じボタンをもう一度押すと非表示（§4）。複数カテゴリーの同時ONに対応する。 */
    function toggleCategory(key, btn) {
      if (ACTIVE[key]) {
        ACTIVE[key] = false;
        btn.classList.remove('is-active');
        btn.setAttribute('aria-pressed', 'false');
        clearOverlays(key);
        renderLegend();
        renderNotes();
        return;
      }
      ACTIVE[key] = true;
      btn.classList.add('is-active');
      btn.setAttribute('aria-pressed', 'true');

      // 取得済みで内容も十分なら、APIを呼ばずに再表示する（§10）。
      if (CACHE[key] && CACHE[key].sufficient) { showCategory(key); return; }

      btn.classList.add('is-loading');
      api('/map-facilities.php?id=' + encodeURIComponent(propertyId) + '&category=' + encodeURIComponent(key))
        .then(function (res) {
          btn.classList.remove('is-loading');
          if (!res || !res.success || !res.data) {
            ACTIVE[key] = false;
            btn.classList.remove('is-active');
            btn.setAttribute('aria-pressed', 'false');
            notify((res && res.message) || '周辺情報を取得できませんでした。');
            return;
          }
          // まとめて取得した同じグループの結果もそのまま保持する（§9・§10）。
          var results = res.data.results || {};
          Object.keys(results).forEach(function (k) {
            var r = results[k];
            CACHE[k] = {
              render: r.render,
              items: r.items || [],
              layers: r.layers || [],
              notice: r.notice || '',
              sufficient: !!r.sufficient
            };
          });
          if (!ACTIVE[key]) return;   // 取得中にOFFにされていたら描画しない
          showCategory(key);
        })
        .catch(function () {
          btn.classList.remove('is-loading', 'is-active');
          btn.setAttribute('aria-pressed', 'false');
          ACTIVE[key] = false;
          notify('周辺情報の取得に失敗しました。');
        });
    }

    function showCategory(key) {
      var data = CACHE[key];
      if (!data) return;
      clearOverlays(key);
      var objs = [];
      if (data.render === 'polygon') {
        (data.layers || []).forEach(function (layer) {
          (layer.polygons || []).forEach(function (poly) {
            var p = new maps.Polygon({
              map: map,
              paths: poly.ring,
              strokeColor: layer.color,
              strokeOpacity: 0.85,
              strokeWeight: 1.5,
              fillColor: layer.color,
              fillOpacity: 0.28,
              clickable: true,
              zIndex: 10
            });
            p.addListener('click', function (e) {
              infoWindow.setContent(areaInfoHtml(layer.label, poly.note));
              infoWindow.setPosition(e.latLng);
              infoWindow.open(map);
            });
            objs.push(p);
          });
        });
      } else {
        var color = CAT_COLOR[key] || '#2d6cdf';
        (data.items || []).forEach(function (f) {
          var m = new maps.Marker({
            map: map,
            position: { lat: f.lat, lng: f.lng },
            icon: key === 'shelter' ? shelterIcon(maps, color) : facilityIcon(maps, color),
            zIndex: 100,
            title: f.name
          });
          m.addListener('click', function () { openInfo(m, facilityInfoHtml(f)); });
          objs.push(m);
        });
      }
      OVERLAYS[key] = objs;
      renderLegend();
      renderNotes();
      // 該当が1件も無いときは、押しても何も起きないように見えないよう一言出す。
      if (!objs.length) {
        notify(data.render === 'polygon'
          ? 'この周辺で該当するエリアは見つかりませんでした。'
          : 'この周辺で該当する施設は見つかりませんでした。');
      }
    }

    function clearOverlays(key) {
      (OVERLAYS[key] || []).forEach(function (o) { o.setMap(null); });
      OVERLAYS[key] = [];
      if (infoWindow) infoWindow.close();
    }

    /* ===== 吹き出し（§2・§3・§7） ===== */
    function openInfo(marker, html) {
      infoWindow.setContent(html);
      infoWindow.open({ anchor: marker, map: map });
    }

    /* 物件の吹き出し: 物件名・価格・間取り・面積。
       現在見ている物件には「物件詳細を見る」を付けない（§2）。 */
    function propertyInfoHtml(p) {
      var rows = '';
      if (p.price) rows += '<div class="prop-map__iw-price">' + esc(p.price) + '</div>';
      var meta = [];
      if (p.layout) meta.push(esc(p.layout));
      if (p.area) meta.push(esc(p.area));
      if (meta.length) rows += '<div class="prop-map__iw-meta">' + meta.join('｜') + '</div>';
      var link = '';
      if (!p.is_current) {
        link = '<a class="prop-map__iw-link" href="#" data-map-open="' + esc(p.id) + '">物件詳細を見る</a>';
      }
      return '<div class="prop-map__iw">' +
        '<div class="prop-map__iw-title">' + esc(p.name) + (p.is_current ? '<span class="prop-map__iw-tag">この物件</span>' : '') + '</div>' +
        rows + link + '</div>';
    }

    /* 周辺施設の吹き出し: 実際の施設名と「Googleマップで見る」だけ（§5・§7）。 */
    function facilityInfoHtml(f) {
      return '<div class="prop-map__iw">' +
        '<div class="prop-map__iw-title">' + esc(f.name) + '</div>' +
        '<a class="prop-map__iw-link" href="' + esc(f.map_url) + '" target="_blank" rel="noopener noreferrer">Googleマップで見る</a>' +
        '</div>';
    }

    function areaInfoHtml(label, note) {
      return '<div class="prop-map__iw">' +
        '<div class="prop-map__iw-title">' + esc(label) + '</div>' +
        (note ? '<div class="prop-map__iw-meta">' + esc(note) + '</div>' : '') + '</div>';
    }

    /* 吹き出し内の「物件詳細を見る」を押したときに、呼び出し元の詳細画面へ切り替える。 */
    function bindInfoWindowLinks() {
      var link = root.querySelector('[data-map-open]');
      if (!link || link.getAttribute('data-map-bound') === '1') return;
      link.setAttribute('data-map-bound', '1');
      link.addEventListener('click', function (e) {
        e.preventDefault();
        infoWindow.close();
        if (typeof opts.onOpenProperty === 'function') {
          opts.onOpenProperty(parseInt(link.getAttribute('data-map-open'), 10));
        }
      });
    }

    /* ===== 凡例・注意書き ===== */
    function renderLegend() {
      var box = root.querySelector('.prop-map__legend');
      if (!box) return;
      var rows = [];
      Object.keys(ACTIVE).forEach(function (key) {
        if (!ACTIVE[key] || !CACHE[key]) return;
        if (CACHE[key].render !== 'polygon') return;
        (CACHE[key].layers || []).forEach(function (layer) {
          if (!(layer.polygons || []).length) return;
          var label = layer.label + (layer.name ? '（' + layer.name + '）' : '');
          rows.push('<span class="prop-map__legend-item"><i style="background:' + esc(layer.color) + '"></i>' + esc(label) + '</span>');
        });
      });
      box.innerHTML = rows.join('');
      box.hidden = !rows.length;
    }

    function renderNotes() {
      var box = root.querySelector('.prop-map__notes');
      if (!box) return;
      var notes = [];
      Object.keys(ACTIVE).forEach(function (key) {
        if (!ACTIVE[key] || !CACHE[key] || !CACHE[key].notice) return;
        notes.push('<div class="prop-map__note">' + esc(CACHE[key].notice) + '</div>');
      });
      box.innerHTML = notes.join('');
      box.hidden = !notes.length;
    }

    function notify(message) {
      var box = root.querySelector('.prop-map__notes');
      if (!box) return;
      var el = document.createElement('div');
      el.className = 'prop-map__note prop-map__note--flash';
      el.textContent = message;
      box.hidden = false;
      box.appendChild(el);
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
        renderNotes();
      }, 4000);
    }
  }

  w.PropertyMap = { mount: mount };
})(window);

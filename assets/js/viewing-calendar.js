/* 内見カレンダー（window.ViewingCalendar）。
 * 買主の希望日時入力（chat-widget.js）と、エージェントの打診画面（property-viewing-agent.js）で
 * 同じ部品を使う。1週間単位で表示し、土曜・日曜・祝日を区別する。
 *
 * 表示・選択のルール（仕様 §4）:
 *   ・表示時間は 10:00〜17:00、開始時刻は30分刻み、1枠1時間（最終枠 16:00〜17:00）
 *   ・1マス＝1枠なので、長くドラッグしても1件の候補が2時間以上にはならない
 *   ・予約できる日は当日から30日先まで。過去の時刻は選べない
 *   ・同じ枠の重複は数えず、再クリック（再タップ）で解除できる
 *   ・色だけで判断させないよう、「希望」「担当者対応可」「選択中／確定」の文字を併記する
 *
 * mode:
 *   'buyer'    買主が希望枠を選ぶ（ドラッグ／タップで赤にする）
 *   'agent'    エージェントが買主の赤い候補から対応できるものを選ぶ（黄にする）
 *   'readonly' 表示のみ
 */
(function (w) {
  'use strict';

  var STATE_LABEL = { buyer: '希望', agent: '担当者対応可', seller: '確定', blocked: '選択不可' };
  var WDAY = ['日', '月', '火', '水', '木', '金', '土'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function key(dateStr, hhmm) { return dateStr + ' ' + hhmm + ':00'; }
  function parseDate(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ===== 日本の祝日（土日と区別して表示するためのもの） =====
   * 計算で求められる範囲のみを扱う。判定できない年は祝日なしとして表示する
   * （祝日かどうかは色分けだけに使い、選択の可否には影響しない）。 */
  function nthMonday(y, m, nth) {
    var d = new Date(y, m - 1, 1);
    var shift = (8 - d.getDay()) % 7; // 最初の月曜まで
    return 1 + shift + (nth - 1) * 7;
  }
  function holidayName(d) {
    var y = d.getFullYear(), m = d.getMonth() + 1, day = d.getDate();
    var fixed = {
      '1-1': '元日', '2-11': '建国記念の日', '2-23': '天皇誕生日', '4-29': '昭和の日',
      '5-3': '憲法記念日', '5-4': 'みどりの日', '5-5': 'こどもの日', '8-11': '山の日',
      '11-3': '文化の日', '11-23': '勤労感謝の日'
    };
    if (fixed[m + '-' + day]) return fixed[m + '-' + day];
    if (m === 1 && day === nthMonday(y, 1, 2)) return '成人の日';
    if (m === 7 && day === nthMonday(y, 7, 3)) return '海の日';
    if (m === 9 && day === nthMonday(y, 9, 3)) return '敬老の日';
    if (m === 10 && day === nthMonday(y, 10, 2)) return 'スポーツの日';
    // 春分・秋分（1980〜2099年の近似式）
    if (m === 3 && day === Math.floor(20.8431 + 0.242194 * (y - 1980) - Math.floor((y - 1980) / 4))) return '春分の日';
    if (m === 9 && day === Math.floor(23.2488 + 0.242194 * (y - 1980) - Math.floor((y - 1980) / 4))) return '秋分の日';
    return '';
  }
  function isHoliday(d) {
    if (holidayName(d)) return true;
    // 振替休日（日曜が祝日の場合の翌月曜）
    if (d.getDay() === 1) {
      var prev = new Date(d.getFullYear(), d.getMonth(), d.getDate() - 1);
      if (prev.getDay() === 0 && holidayName(prev)) return true;
    }
    return false;
  }

  /**
   * カレンダーを描画する。
   * @param {HTMLElement} host   描画先
   * @param {Object} opts
   *   mode        'buyer' | 'agent' | 'readonly'
   *   rules       { hour_start, hour_end, step, slot, days_ahead, min_slots, starts, today, limit_date }
   *   slots       [{ id, start_at, end_at, state }]  サーバーに保存済みの候補
   *   blocked     ["Y-m-d H:i:s", ...]               担当者の予定と重なり選択不可の開始時刻
   *   selected    ["Y-m-d H:i:s", ...]               初期選択（買主モード）
   *   onChange    function(selectedArray)            選択が変わったとき
   * @return {{ getSelected: function, refresh: function }}
   */
  function render(host, opts) {
    opts = opts || {};
    var mode = opts.mode || 'readonly';
    var rules = opts.rules || {};
    var starts = rules.starts || [];
    var slotMinutes = rules.slot || 60;

    // 既存の候補を「開始日時 → 状態」に展開する。
    var slotState = {};
    var slotId = {};
    (opts.slots || []).forEach(function (s) {
      var k = String(s.start_at).slice(0, 16) + ':00';
      slotState[k] = s.state;
      slotId[k] = s.id;
    });
    var blocked = {};
    (opts.blocked || []).forEach(function (b) { blocked[String(b).slice(0, 16) + ':00'] = true; });

    // 現在の選択。買主モードは希望枠、エージェントモードは黄にする枠。
    var selected = {};
    if (mode === 'buyer') {
      (opts.selected || []).forEach(function (s) { selected[String(s).slice(0, 16) + ':00'] = true; });
      Object.keys(slotState).forEach(function (k) { if (slotState[k] === 'buyer') selected[k] = true; });
    } else if (mode === 'agent') {
      Object.keys(slotState).forEach(function (k) {
        if (slotState[k] === 'agent' || slotState[k] === 'seller') selected[k] = true;
      });
    }

    var today = parseDate(rules.today) || new Date();
    today.setHours(0, 0, 0, 0);
    var limit = parseDate(rules.limit_date) || new Date(today.getTime() + 30 * 86400000);
    // 週の先頭（日曜）から表示する。
    var weekStart = new Date(today.getTime());
    weekStart.setDate(weekStart.getDate() - weekStart.getDay());

    var root = document.createElement('div');
    root.className = 'vcal';
    host.innerHTML = '';
    host.appendChild(root);

    function selectedList() {
      return Object.keys(selected).filter(function (k) { return selected[k]; }).sort();
    }
    function emitChange() {
      if (typeof opts.onChange === 'function') opts.onChange(selectedList());
    }

    /* 1マスの状態。選択可否と表示色を決める。 */
    function cellInfo(dateStr, hhmm, cellDate) {
      var k = key(dateStr, hhmm);
      var info = { key: k, state: '', label: '', selectable: false, past: false };

      var startAt = new Date(cellDate.getTime());
      startAt.setHours(parseInt(hhmm.slice(0, 2), 10), parseInt(hhmm.slice(3, 5), 10), 0, 0);
      if (startAt <= new Date()) info.past = true;
      if (cellDate < today || cellDate > limit) info.past = true;

      if (blocked[k]) { info.state = 'blocked'; info.label = STATE_LABEL.blocked; return info; }
      if (info.past) return info;

      if (mode === 'buyer') {
        // 買主は自分の希望枠だけを操作する。確定済みの枠は操作させない。
        if (slotState[k] === 'seller') { info.state = 'seller'; info.label = STATE_LABEL.seller; return info; }
        info.selectable = true;
        if (selected[k]) { info.state = 'buyer'; info.label = STATE_LABEL.buyer; }
      } else if (mode === 'agent') {
        // エージェントは買主が選んでいない日時を追加できない。
        if (!slotState[k]) return info;
        info.selectable = (slotState[k] !== 'seller');
        if (slotState[k] === 'seller') { info.state = 'seller'; info.label = STATE_LABEL.seller; }
        else if (selected[k]) { info.state = 'agent'; info.label = STATE_LABEL.agent; }
        else { info.state = 'buyer'; info.label = STATE_LABEL.buyer; }
      } else {
        if (slotState[k]) { info.state = slotState[k]; info.label = STATE_LABEL[slotState[k]] || ''; }
      }
      return info;
    }

    function draw() {
      var days = [];
      for (var i = 0; i < 7; i++) {
        var d = new Date(weekStart.getTime());
        d.setDate(d.getDate() + i);
        days.push(d);
      }

      var canPrev = weekStart > today;
      var lastDay = days[6];
      var canNext = lastDay < limit;

      var html = '<div class="vcal-bar">' +
        '<button type="button" class="vcal-nav" data-nav="prev"' + (canPrev ? '' : ' disabled') + '>← 前の週</button>' +
        '<span class="vcal-range">' + (days[0].getMonth() + 1) + '月' + days[0].getDate() + '日 〜 ' +
          (lastDay.getMonth() + 1) + '月' + lastDay.getDate() + '日</span>' +
        '<button type="button" class="vcal-nav" data-nav="next"' + (canNext ? '' : ' disabled') + '>次の週 →</button>' +
        '</div>';

      html += '<div class="vcal-scroll"><table class="vcal-table"><thead><tr><th class="vcal-time"></th>';
      days.forEach(function (d) {
        var cls = 'vcal-day';
        if (isHoliday(d)) cls += ' is-holiday';
        else if (d.getDay() === 0) cls += ' is-sun';
        else if (d.getDay() === 6) cls += ' is-sat';
        if (d < today || d > limit) cls += ' is-out';
        html += '<th class="' + cls + '"><span class="vcal-day__d">' + (d.getMonth() + 1) + '/' + d.getDate() + '</span>' +
          '<span class="vcal-day__w">' + WDAY[d.getDay()] + '</span></th>';
      });
      html += '</tr></thead><tbody>';

      starts.forEach(function (hhmm) {
        var endMin = parseInt(hhmm.slice(0, 2), 10) * 60 + parseInt(hhmm.slice(3, 5), 10) + slotMinutes;
        var endText = pad(Math.floor(endMin / 60)) + ':' + pad(endMin % 60);
        html += '<tr><th class="vcal-time">' + hhmm + '<span>〜' + endText + '</span></th>';
        days.forEach(function (d) {
          var info = cellInfo(ymd(d), hhmm, d);
          var cls = 'vcal-cell';
          if (info.state) cls += ' is-' + info.state;
          if (info.past) cls += ' is-past';
          if (info.selectable) cls += ' is-selectable';
          html += '<td class="' + cls + '" data-key="' + esc(info.key) + '"' +
            (info.selectable ? ' tabindex="0" role="button"' : '') +
            ' aria-label="' + esc(hhmm + '〜' + endText + ' ' + (info.label || '')) + '">' +
            (info.label ? '<span class="vcal-cell__tag">' + esc(info.label) + '</span>' : '') + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';

      html += '<div class="vcal-legend">' +
        '<span class="vcal-lg is-buyer">希望（買主）</span>' +
        '<span class="vcal-lg is-agent">担当者対応可</span>' +
        '<span class="vcal-lg is-seller">選択中／確定</span>' +
        '<span class="vcal-lg is-blocked">選択不可</span>' +
        '</div>';

      root.innerHTML = html;
      bind();
    }

    /* 選択の切り替え。再クリック（再タップ）で解除する。 */
    function toggle(k, force) {
      if (force === true) selected[k] = true;
      else if (force === false) delete selected[k];
      else if (selected[k]) delete selected[k];
      else selected[k] = true;
    }

    function bind() {
      root.querySelectorAll('[data-nav]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (b.disabled) return;
          weekStart.setDate(weekStart.getDate() + (b.getAttribute('data-nav') === 'next' ? 7 : -7));
          draw();
        });
      });

      var cells = root.querySelectorAll('.vcal-cell.is-selectable');
      if (!cells.length) return;

      // ドラッグ選択。押した枠の状態にそろえる（押した枠が未選択なら、なぞった枠を選択する）。
      var dragging = false;
      var dragTo = true;

      function applyCell(td) {
        var k = td.getAttribute('data-key');
        toggle(k, dragTo);
        draw();
        emitChange();
      }

      cells.forEach(function (td) {
        td.addEventListener('mousedown', function (e) {
          e.preventDefault();
          dragging = true;
          dragTo = !selected[td.getAttribute('data-key')];
          applyCell(td);
        });
        td.addEventListener('mouseenter', function () {
          if (!dragging) return;
          applyCell(td);
        });
        // スマートフォンはタップで選択・解除する。
        td.addEventListener('click', function () {
          if (dragging) return;
          toggle(td.getAttribute('data-key'));
          draw();
          emitChange();
        });
        td.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter' && e.key !== ' ') return;
          e.preventDefault();
          toggle(td.getAttribute('data-key'));
          draw();
          emitChange();
        });
      });

      document.addEventListener('mouseup', function () { dragging = false; }, { once: true });
    }

    draw();
    emitChange();

    return {
      getSelected: selectedList,
      getSlotIds: function () {
        // エージェントモードで、選択した枠のサーバー側IDを返す。
        return selectedList().map(function (k) { return slotId[k]; }).filter(function (v) { return !!v; });
      },
      refresh: draw
    };
  }

  /** 「10月5日（月）10:00〜11:00」。選択一覧の表示に使う。 */
  function formatRange(startAt, slotMinutes) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(startAt || ''));
    if (!m) return String(startAt || '');
    var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
    var end = new Date(d.getTime() + (slotMinutes || 60) * 60000);
    return (d.getMonth() + 1) + '月' + d.getDate() + '日（' + WDAY[d.getDay()] + '）' +
      pad(d.getHours()) + ':' + pad(d.getMinutes()) + '〜' + pad(end.getHours()) + ':' + pad(end.getMinutes());
  }

  w.ViewingCalendar = { render: render, formatRange: formatRange };
})(window);

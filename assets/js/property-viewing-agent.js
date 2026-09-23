/* 内見日程調整（エージェント側 / window.PropertyViewingAgent）。
 * property-agent.js の物件詳細に「内見日程」タブとして描画する。
 * 依存: property-core.js（window.PropertyUI）、viewing-calendar.js（window.ViewingCalendar）
 *
 * 画面の流れ（仕様 §5・§6・§7・§8）:
 *   1. 買主の希望日時（赤）から対応できる日時を選ぶ → 黄
 *   2. 売主仲介会社情報を確認・修正し、「売主（仲介）会社へ内見を打診する」
 *      ★このとき「購入検討者属性」を自由入力できる（売主側の回答画面に表示される）
 *   3. 売主側の承諾後、鍵の受け渡し情報を確認し、買主向けの待ち合わせ案内を入力して送信
 *   4. キャンセル時は「売主（仲介）会社へキャンセルを通知」で先方へ連絡する
 *
 * 内見依頼がない物件には内見カレンダーを表示しない。
 */
(function (w) {
  'use strict';

  var UI = w.PropertyUI;
  var API = w.location.origin + '/backend/api/property';

  function esc(s) { return UI ? UI.esc(s) : String(s == null ? '' : s); }
  function notify(type, msg) {
    if (type === 'error' && typeof w.showError === 'function') return w.showError(msg);
    if (type !== 'error' && typeof w.showSuccess === 'function') return w.showSuccess(msg, { autoClose: 2500 });
    if (type === 'error') alert(msg); else console.log(msg);
  }
  function api(path, body) {
    var opts = { credentials: 'include' };
    if (body) {
      opts.method = 'POST';
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(body);
    }
    return fetch(API + path, opts).then(function (r) { return r.json(); });
  }

  /** メール送信の履歴。取れていない情報を「確認済み」と出さないため、状況をそのまま並べる。 */
  function eventsHtml(ev) {
    if (!ev) return '';
    var rows = '';
    if (ev.seller_opened_at) rows += '<dt>売主側のメール開封</dt><dd>' + esc(ev.seller_opened_at) + '</dd>';
    if (ev.seller_page_opened_at) rows += '<dt>回答ページへのアクセス</dt><dd>' + esc(ev.seller_page_opened_at) + '</dd>';
    if (ev.seller_replied_at) rows += '<dt>回答完了</dt><dd>' + esc(ev.seller_replied_at) + '</dd>';
    var fails = (ev.failures || []).map(function (f) {
      return '<div class="vw-fail">' + esc(f.code) + '：' + esc(f.recipient || '宛先不明') + ' への送信に失敗しました（' + esc(f.at) + '）</div>';
    }).join('');
    if (!rows && !fails) return '';
    return '<div class="vw-box"><div class="vw-box__title">送信状況</div>' +
      (rows ? '<dl class="vw-dl">' + rows + '</dl>' : '') + fails +
      '<div class="vw-hint">開封・アクセス・回答完了は別々に記録しています。記録がない項目は「未確認」であり、未開封とは限りません。</div></div>';
  }

  /** 鍵の受け渡し情報（売主側が入力したもの）。買主には表示しない。 */
  function keyHtml(v) {
    if (!v.key_method) return '';
    var rows = Object.keys(v.key_data || {}).map(function (k) {
      return '<dd>' + esc(v.key_data[k]) + '</dd>';
    }).join('');
    return '<div class="vw-box"><div class="vw-box__title">鍵の受け渡し（' + esc(v.key_method_label) + '）</div>' +
      '<dl class="vw-dl">' + rows + '</dl>' +
      '<div class="vw-hint">この内容は買主には表示されません。買主向けの案内へ自動転記もされません。</div></div>';
  }

  function sellerFormHtml(s) {
    s = s || {};
    function field(key, label, type) {
      if (type === 'textarea') {
        return '<div class="vw-field"><label>' + esc(label) + '</label>' +
          '<textarea data-seller="' + key + '" rows="2">' + esc(s[key] || '') + '</textarea></div>';
      }
      return '<div class="vw-field"><label>' + esc(label) + '</label>' +
        '<input type="' + (type || 'text') + '" data-seller="' + key + '" value="' + esc(s[key] || '') + '"></div>';
    }
    // 読み取りミスを修正できるよう、全項目を編集可能にする（仕様 §5）。
    return '<div class="vw-box"><div class="vw-box__title">売主（仲介）会社情報</div>' +
      field('company', '販売会社名') +
      field('person', '担当者名') +
      field('email', 'メールアドレス', 'email') +
      field('phone', '販売会社電話番号') +
      field('transaction_type', '取引態様') +
      field('remarks', '備考', 'textarea') +
      '<div class="vw-hint">担当者名が未入力の場合、メールの宛名は「会社名 ご担当者様」になります。</div></div>';
  }

  /**
   * 内見日程タブを描画する。
   * @param {HTMLElement} pane 描画先
   * @param {Object} p 物件（property-agent.js の詳細で保持しているもの）
   */
  function render(pane, p) {
    pane.innerHTML = '<div class="prop-msg prop-msg--info">読み込み中...</div>';
    api('/viewing-get.php?property_id=' + encodeURIComponent(p.id))
      .then(function (res) {
        if (!res.success) { pane.innerHTML = '<div class="prop-msg prop-msg--error">' + esc(res.message || '読み込みに失敗しました') + '</div>'; return; }
        draw(pane, p, res.data);
      })
      .catch(function () { pane.innerHTML = '<div class="prop-msg prop-msg--error">通信に失敗しました</div>'; });
  }

  function draw(pane, p, data) {
    var v = data.viewing;

    // 内見依頼がない物件には内見カレンダーを表示しない（仕様 §5）。
    if (!v) {
      pane.innerHTML = '<div class="vw-panel"><div class="prop-msg prop-msg--info">' +
        'この物件への内見依頼はまだありません。お客様が物件詳細から「内見予約を依頼する」を押すと、ここに日程調整の画面が表示されます。' +
        '</div></div>';
      return;
    }

    var html = '<div class="vw-panel">';
    html += '<div class="vw-status">' + esc(v.status_label) + (v.is_rescheduling ? '／日時変更の調整中' : '') + '</div>';
    html += '<div class="vw-box"><div class="vw-box__title">対象物件</div><div>' + esc(data.property.label) + '</div></div>';

    if (v.confirmed_text) {
      html += '<div class="vw-box vw-box--accent"><div class="vw-box__title">確定した内見日時</div>' +
        '<div>' + esc(v.confirmed_text) + '</div>' +
        (v.prev_text ? '<div class="vw-hint">変更前：' + esc(v.prev_text) + '</div>' : '') + '</div>';
    }
    if (v.status === 'unavailable') {
      html += '<div class="vw-box vw-box--warn">売主（仲介）会社より「成約・申込済み」の回答がありました。' +
        'この案件のリマインドはすべて取り消しています。</div>' +
        '<div class="vw-box"><div class="vw-box__title">買主へのご案内</div>' +
        '<div class="vw-field"><label>お伝えする内容（任意）</label>' +
        '<textarea data-vw="unavailable-msg" rows="3" placeholder="例）引き続き、ご条件に近い物件をお探しいたします。"></textarea>' +
        '<div class="vw-hint">内見不可のご案内は自動送信されません。内容をご確認のうえ送信してください。</div></div>' +
        '<div class="vw-actions"><button type="button" class="prop-btn prop-btn--primary" data-vw="notify-unavailable">買主へ内見不可のご案内を送信</button></div></div>';
    } else if (v.unavailable_reason === 'no_slot') {
      html += '<div class="vw-box vw-box--warn">売主（仲介）会社より「候補日時では内見不可」の回答がありました。' +
        '買主へ別の候補日時を3つ以上お選びいただき、再調整してください。</div>';
    }
    if (v.is_rescheduling && v.prev_text) {
      html += '<div class="vw-box vw-box--warn">日時変更のご依頼を受けています。変更前の予約（' + esc(v.prev_text) + '）は解除済みです。' +
        '<strong>売主（仲介）会社へ旧日時の取消連絡が必要です。</strong></div>';
    }

    html += keyHtml(v);

    if (v.status === 'unavailable') {
      html += eventsHtml(v.events) + '</div>';
      pane.innerHTML = html;
      bind(pane, p, data);
      return;
    }

    /* ---- キャンセル済み ---- */
    if (v.status === 'cancelled') {
      html += '<div class="vw-box"><div class="vw-box__title">キャンセル内容</div>' +
        '<dl class="vw-dl"><dt>キャンセルした日時</dt><dd>' + esc(v.prev_text || v.confirmed_text || '—') + '</dd>' +
        '<dt>理由</dt><dd>' + esc(cancelLabel(v)) + '</dd></dl>' +
        '<div class="vw-hint">キャンセル理由は売主（仲介）会社へのメールには転記されません。</div></div>';
      html += '<div class="vw-box"><div class="vw-box__title">売主側への通知</div>' +
        (v.seller_cancel_notified_at
          ? '<div>キャンセル済み／売主側へ通知済み（' + esc(v.seller_cancel_notified_at) + '）</div>'
          : '<div>キャンセル済み／売主側への通知待ち</div>' +
            '<div class="vw-actions"><button type="button" class="prop-btn prop-btn--primary" data-vw="notify-seller">売主（仲介）会社へキャンセルを通知</button></div>') +
        '</div>';
      html += eventsHtml(v.events) + '</div>';
      pane.innerHTML = html;
      bind(pane, p, data);
      return;
    }

    /* ---- 確定後：買主への確定連絡 ---- */
    if (v.status === 'confirmed' || v.status === 'buyer_notified') {
      html += '<div class="vw-box"><div class="vw-box__title">買主への確定案内</div>' +
        '<div class="vw-field"><label>当日の待ち合わせ場所と時間（必須）</label>' +
        '<textarea data-vw="meeting" rows="3" placeholder="例）10時55分に、〇〇マンションのエントランスへお越しください。">' + esc(v.meeting_note || '') + '</textarea>' +
        '<div class="vw-hint">買主向けの自由入力です。鍵情報はここへ自動転記されません。</div></div>' +
        '<div class="vw-actions"><button type="button" class="prop-btn prop-btn--primary" data-vw="confirm">' +
        (v.status === 'buyer_notified' ? '確定案内を再送する' : '買主へ内見確定の案内を送信する') + '</button></div>' +
        (v.buyer_notified_at ? '<div class="vw-hint">送信済み：' + esc(v.buyer_notified_at) + '（前日18時・当日8時のリマインドを予約しています）</div>' : '') +
        '</div>';
      html += eventsHtml(v.events) + '</div>';
      pane.innerHTML = html;
      bind(pane, p, data);
      return;
    }

    /* ---- 調整中：候補の選択と打診 ---- */
    html += '<p class="vw-panel__guide">対応できる日時をお選びください。選択した日時が黄色に変わります。買主が選んでいない日時は追加できません。</p>';
    html += '<div class="vcal-picked" data-vw="picked"></div>';
    html += '<div data-vw="calendar"></div>';

    // ★購入検討者属性（自由入力）。売主（仲介）会社の回答画面に表示される。
    html += '<div class="vw-box vw-box--accent"><div class="vw-box__title">購入検討者属性</div>' +
      '<div class="vw-field">' +
      '<textarea data-vw="attrs" rows="4" placeholder="例）30代ご夫婦・お子様1名／ご自宅の住み替え（売却済み）／自己資金1,500万円・事前審査承認済み（〇〇銀行）／ご内見は2件目"></textarea>' +
      '<div class="vw-hint">ここに入力した内容は、売主（仲介）会社が回答URLを開いたときにカレンダーとあわせて表示されます。</div>' +
      '</div></div>';

    html += sellerFormHtml(v.seller);
    html += '<div class="vw-actions">' +
      '<button type="button" class="prop-btn prop-btn--primary" data-vw="propose">売主（仲介）会社へ内見を打診する</button>' +
      '<button type="button" class="prop-btn prop-btn--ghost" data-vw="reinput">買主に別の日時を依頼する</button>' +
      '</div>';
    html += '<div class="vw-hint">打診前に宛先（売主（仲介）会社のメールアドレス）をご確認ください。未入力・形式不正の場合は送信できません。</div>';
    html += eventsHtml(v.events) + '</div>';

    pane.innerHTML = html;

    // 購入検討者属性は draw のたびに値を入れ直す（HTMLに埋め込むと改行が崩れるため）。
    var attrs = pane.querySelector('[data-vw="attrs"]');
    if (attrs) attrs.value = v.buyer_attributes || '';

    bind(pane, p, data);
  }

  function cancelLabel(v) {
    var map = {
      no_interest: '内見予定の物件に興味がなくなった',
      other_agency: '他社で購入を決めた',
      stop_buying: '物件購入をやめた',
      other: 'その他'
    };
    var base = map[v.cancel_reason] || v.cancel_reason || '—';
    return v.cancel_reason_text ? base + '：' + v.cancel_reason_text : base;
  }

  function bind(pane, p, data) {
    var v = data.viewing;
    var cal = null;

    var calHost = pane.querySelector('[data-vw="calendar"]');
    if (calHost && w.ViewingCalendar) {
      var picked = pane.querySelector('[data-vw="picked"]');
      cal = w.ViewingCalendar.render(calHost, {
        mode: 'agent',
        rules: data.rules,
        slots: data.slots,
        blocked: data.blocked,
        onChange: function (list) {
          if (!picked) return;
          picked.innerHTML = '<div class="vcal-picked__title">売主（仲介）会社へ打診する日時（' + list.length + '件）</div>' +
            (list.length
              ? '<div class="vcal-picked__list">' + list.map(function (s) {
                  return '<span class="vcal-picked__item">' + esc(w.ViewingCalendar.formatRange(s, data.rules.slot)) + '</span>';
                }).join('') + '</div>'
              : '<div class="vcal-picked__empty">対応できる日時をカレンダーからお選びください。</div>');
        }
      });
    }

    function sellerPayload() {
      var out = {};
      pane.querySelectorAll('[data-seller]').forEach(function (el) {
        out[el.getAttribute('data-seller')] = el.value.trim();
      });
      return out;
    }

    function reload() { render(pane, p); }

    function act(btn, body, okMsg) {
      btn.disabled = true;
      api('/viewing-propose.php', body)
        .then(function (res) {
          btn.disabled = false;
          if (!res.success) { notify('error', res.message || '送信に失敗しました'); return; }
          notify('ok', res.message || okMsg);
          reload();
        })
        .catch(function () { btn.disabled = false; notify('error', '通信に失敗しました'); });
    }

    var proposeBtn = pane.querySelector('[data-vw="propose"]');
    if (proposeBtn) {
      proposeBtn.addEventListener('click', function () {
        var ids = cal ? cal.getSlotIds() : [];
        if (!ids.length) { notify('error', '対応できる日時を1つ以上お選びください。'); return; }
        var seller = sellerPayload();
        if (!seller.email) { notify('error', '売主（仲介）会社のメールアドレスをご入力ください。'); return; }
        if (!w.confirm(seller.email + ' 宛に内見の打診メールを送信します。よろしいですか？')) return;
        act(proposeBtn, {
          property_id: p.id, action: 'propose', slot_ids: ids,
          buyer_attributes: (pane.querySelector('[data-vw="attrs"]') || {}).value || '',
          seller: seller
        }, '売主（仲介）会社へ打診しました。');
      });
    }

    var reinputBtn = pane.querySelector('[data-vw="reinput"]');
    if (reinputBtn) {
      reinputBtn.addEventListener('click', function () {
        if (!w.confirm('買主へ別の候補日時のご依頼（3枠以上）を送信します。よろしいですか？')) return;
        act(reinputBtn, {
          property_id: p.id, action: 'reinput',
          buyer_attributes: (pane.querySelector('[data-vw="attrs"]') || {}).value || '',
          seller: sellerPayload()
        }, '買主へ再入力のご依頼を送信しました。');
      });
    }

    var confirmBtn = pane.querySelector('[data-vw="confirm"]');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', function () {
        var note = (pane.querySelector('[data-vw="meeting"]') || {}).value || '';
        if (!note.trim()) { notify('error', '当日の待ち合わせ場所と時間をご入力ください。'); return; }
        confirmBtn.disabled = true;
        api('/viewing-confirm.php', { property_id: p.id, meeting_note: note })
          .then(function (res) {
            confirmBtn.disabled = false;
            if (!res.success) { notify('error', res.message || '送信に失敗しました'); return; }
            notify('ok', res.message);
            reload();
          })
          .catch(function () { confirmBtn.disabled = false; notify('error', '通信に失敗しました'); });
      });
    }

    var unavailBtn = pane.querySelector('[data-vw="notify-unavailable"]');
    if (unavailBtn) {
      unavailBtn.addEventListener('click', function () {
        if (!w.confirm('買主へ内見不可のご案内を送信します。よろしいですか？')) return;
        unavailBtn.disabled = true;
        api('/viewing-propose.php', {
          property_id: p.id, action: 'notify_unavailable',
          message: (pane.querySelector('[data-vw="unavailable-msg"]') || {}).value || ''
        }).then(function (res) {
          unavailBtn.disabled = false;
          if (!res.success) { notify('error', res.message || '送信に失敗しました'); return; }
          notify('ok', res.message);
          reload();
        }).catch(function () { unavailBtn.disabled = false; notify('error', '通信に失敗しました'); });
      });
    }

    var notifyBtn = pane.querySelector('[data-vw="notify-seller"]');
    if (notifyBtn) {
      notifyBtn.addEventListener('click', function () {
        if (!w.confirm('売主（仲介）会社へキャンセルをご連絡します。よろしいですか？')) return;
        notifyBtn.disabled = true;
        api('/viewing-cancel.php', { property_id: p.id, action: 'notify_seller' })
          .then(function (res) {
            notifyBtn.disabled = false;
            if (!res.success) { notify('error', res.message || '送信に失敗しました'); return; }
            notify('ok', res.message);
            reload();
          })
          .catch(function () { notifyBtn.disabled = false; notify('error', '通信に失敗しました'); });
      });
    }
  }

  w.PropertyViewingAgent = { render: render };
})(window);

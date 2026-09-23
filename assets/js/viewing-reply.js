/**
 * 内見日時のご回答（売主（仲介）会社向けページ / viewing-reply.php）。
 * ・候補日時は1枠だけ選べる（別の候補を選ぶと直前の選択は自動で外れる＝ラジオボタン）
 * ・鍵の受け渡しは方法を1つ選び、その方法の入力欄だけを表示する
 * ・必須項目がそろうまで「この内容で内見を承諾」は押せない
 * ・「成約・申込済み」「候補日時では内見不可」は鍵情報の入力なしで回答できる
 */
(function () {
  'use strict';

  var form = document.getElementById('vr-form');
  if (!form) return;

  var token = form.getAttribute('data-token');
  var apiBase = form.getAttribute('data-api');
  var msgBox = document.getElementById('vr-msg');
  var buttons = form.querySelectorAll('[data-action]');
  var methodInputs = form.querySelectorAll('input[name="key_method"]');
  var fieldsets = form.querySelectorAll('.vr-fields');

  function showMessage(text, kind) {
    msgBox.textContent = text;
    msgBox.className = 'vr-msg' + (kind ? ' vr-msg--' + kind : '');
    msgBox.hidden = false;
    msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function selectedMethod() {
    var checked = form.querySelector('input[name="key_method"]:checked');
    return checked ? checked.value : '';
  }

  /* 選んだ方法の入力欄だけを表示する。表示していない欄の値は送信しない。 */
  function syncFields() {
    var method = selectedMethod();
    Array.prototype.forEach.call(fieldsets, function (fs) {
      fs.hidden = (fs.getAttribute('data-method') !== method);
    });
  }

  Array.prototype.forEach.call(methodInputs, function (el) {
    el.addEventListener('change', syncFields);
  });
  syncFields();

  /* 承諾に必要な情報がそろっているか。足りない場合は理由を返す。 */
  function validateAccept() {
    var slot = form.querySelector('input[name="slot_id"]:checked');
    if (!slot) return '内見日時を1つお選びください。';

    var method = selectedMethod();
    if (!method) return '鍵の受け渡し方法をお選びください。';

    var fs = form.querySelector('.vr-fields[data-method="' + method + '"]');
    if (!fs) return '鍵の受け渡し方法をお選びください。';

    var missing = [];
    Array.prototype.forEach.call(fs.querySelectorAll('[data-key]'), function (el) {
      var label = el.parentNode.querySelector('label');
      var required = label && label.querySelector('.vr-req');
      if (required && el.value.trim() === '') {
        missing.push(label.childNodes[0].textContent.trim());
      }
    });
    if (missing.length) return '次の項目をご入力ください：' + missing.join('／');
    return '';
  }

  function collectKey() {
    var method = selectedMethod();
    var fs = form.querySelector('.vr-fields[data-method="' + method + '"]');
    var key = {};
    if (fs) {
      Array.prototype.forEach.call(fs.querySelectorAll('[data-key]'), function (el) {
        key[el.getAttribute('data-key')] = el.value.trim();
      });
    }
    return key;
  }

  function setBusy(busy) {
    Array.prototype.forEach.call(buttons, function (b) { b.disabled = busy; });
  }

  function post(payload) {
    setBusy(true);
    return fetch(apiBase + '/property/viewing-seller.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) {
          showMessage(res.message || 'ご回答を保存できませんでした。', 'error');
          setBusy(false);
          return;
        }
        // 回答後は入力欄を閉じ、確定内容を表示し直す（再承諾は受け付けない）。
        showMessage(res.message || 'ご回答ありがとうございました。', 'done');
        window.setTimeout(function () { window.location.reload(); }, 1200);
      })
      .catch(function () {
        showMessage('通信に失敗しました。時間をおいて再度お試しください。', 'error');
        setBusy(false);
      });
  }

  Array.prototype.forEach.call(buttons, function (btn) {
    btn.addEventListener('click', function () {
      var action = btn.getAttribute('data-action');

      if (action === 'accept') {
        var error = validateAccept();
        if (error) { showMessage(error, 'error'); return; }
        var slot = form.querySelector('input[name="slot_id"]:checked');
        post({
          t: token, action: 'accept',
          slot_id: parseInt(slot.value, 10),
          key_method: selectedMethod(),
          key: collectKey()
        });
        return;
      }

      // 内見不可の回答は鍵情報の入力を求めない。取り消せないため確認を挟む。
      var confirmText = (action === 'contracted')
        ? 'お申し込み・ご成約により内見できない旨を回答します。よろしいですか？'
        : '候補の日時ではすべて内見できない旨を回答します。よろしいですか？';
      if (!window.confirm(confirmText)) return;
      post({ t: token, action: action });
    });
  });

  /* 写真・資料の添付（選択した時点でアップロードする） */
  var fileInput = document.getElementById('vr-file');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append('t', token);
      fd.append('file', file);
      fileInput.disabled = true;
      fetch(apiBase + '/property/viewing-attachment.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          fileInput.disabled = false;
          fileInput.value = '';
          if (!res.success) { showMessage(res.message || '添付できませんでした。', 'error'); return; }
          var list = document.getElementById('vr-attach-list');
          if (list) {
            list.innerHTML = '';
            (res.data.attachments || []).forEach(function (a) {
              var li = document.createElement('li');
              var link = document.createElement('a');
              link.href = apiBase + '/property/viewing-attachment.php?id=' + a.id + '&t=' + encodeURIComponent(token);
              link.target = '_blank';
              link.rel = 'noopener';
              link.textContent = a.original_name;
              li.appendChild(link);
              list.appendChild(li);
            });
          }
          showMessage('添付しました。', 'done');
        })
        .catch(function () {
          fileInput.disabled = false;
          showMessage('添付に失敗しました。', 'error');
        });
    });
  }
})();

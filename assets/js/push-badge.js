/* ホーム画面アイコンのアプリバッジ（Web Push / PWA）クライアント。
 * - 前面表示中: chat-widget.js が未読数変化時に window.PushBadge.setBadge() を呼ぶ。
 * - バックグラウンド: Service Worker(push-sw.js) が Push 受信時に未読数を取得してバッジ更新。
 * 購読・通知許可は「ホーム画面に追加した(standalone)」状態＋ユーザー操作を起点に行う（iOS要件）。
 * 設定は card.php が window.__PUSH_CFG__ で渡す。 */
(function () {
  'use strict';

  var CFG = window.__PUSH_CFG__ || {};
  var VAPID = CFG.vapidPublicKey || '';
  var SW_URL = CFG.swUrl || '/push-sw.js';
  var SUBSCRIBE_URL = CFG.subscribeUrl || '/backend/api/push/subscribe.php';

  // 前面表示中のアプリバッジ更新（対応環境のみ。非対応・非PWAでは無害にスキップ）。
  window.PushBadge = window.PushBadge || {};
  window.PushBadge.setBadge = function (n) {
    try {
      if (!('setAppBadge' in navigator)) return;
      n = parseInt(n, 10) || 0;
      if (n > 0) navigator.setAppBadge(n);
      else if ('clearAppBadge' in navigator) navigator.clearAppBadge();
    } catch (e) {}
  };

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || window.navigator.standalone === true;
  }
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

  function urlB64ToUint8(base64) {
    var pad = '='.repeat((4 - base64.length % 4) % 4);
    var b64 = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b64);
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  var swReg = null;
  var subscribing = false;

  function getSession() {
    try {
      return (window.__aiFcardChat && window.__aiFcardChat.getSession) ? window.__aiFcardChat.getSession() : null;
    } catch (e) { return null; }
  }

  function sendConfigToSw(sess) {
    if (!sess) return;
    var target = (swReg && swReg.active) || navigator.serviceWorker.controller;
    if (!target) return;
    try {
      target.postMessage({ type: 'push-config', config: {
        apiBase: sess.apiBase, sessionId: sess.sessionId, visitorId: sess.visitorId, startUrl: location.href
      }});
    } catch (e) {}
  }

  function saveSubscription(sub, sess) {
    return fetch(SUBSCRIBE_URL, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
      body: JSON.stringify({ session_id: sess.sessionId, visitor_id: sess.visitorId || '', subscription: sub })
    }).catch(function () {});
  }

  function ensureSubscribed() {
    if (subscribing || !swReg || !VAPID || !isStandalone()) return;
    var sess = getSession();
    if (!sess || !sess.sessionId) return;
    if (Notification.permission === 'denied') return;
    subscribing = true;
    var doSub = function () {
      return swReg.pushManager.getSubscription().then(function (existing) {
        if (existing) return existing;
        return swReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(VAPID) });
      });
    };
    var flow = (Notification.permission === 'granted')
      ? doSub()
      : Notification.requestPermission().then(function (p) { return p === 'granted' ? doSub() : null; });
    flow.then(function (sub) {
      subscribing = false;
      if (!sub) return;
      var s = getSession() || sess;
      sendConfigToSw(s);
      return saveSubscription(sub.toJSON ? sub.toJSON() : sub, s);
    }).catch(function () { subscribing = false; });
  }

  navigator.serviceWorker.register(SW_URL).then(function (reg) {
    swReg = reg;
    // セッションが用意でき次第、SW へ設定を送る（＋許可済みなら購読）。
    var t = setInterval(function () {
      var sess = getSession();
      if (sess && sess.sessionId) {
        sendConfigToSw(sess);
        if (isStandalone() && Notification.permission === 'granted') ensureSubscribed();
        clearInterval(t);
      }
    }, 1500);
    setTimeout(function () { clearInterval(t); }, 120000);
  }).catch(function () {});

  // 通知許可は必ずユーザー操作を起点に（iOS要件）。standalone時のみ、最初の操作で購読を試みる。
  if (isStandalone()) {
    var onGesture = function () {
      ensureSubscribed();
      document.removeEventListener('pointerdown', onGesture);
      document.removeEventListener('click', onGesture);
    };
    document.addEventListener('pointerdown', onGesture);
    document.addEventListener('click', onGesture);
  }
})();

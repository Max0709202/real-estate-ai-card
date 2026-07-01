/* ホーム画面アイコンのアプリバッジ用 Service Worker（Web Push）。
 * 担当→顧客の新着で空Push(tickle)を受信し、customer/poll.php から未読数を取得して
 * setAppBadge() ＋ 通知表示する。設定(session情報)はページから postMessage で受け取り
 * IndexedDB に保存する。 */
'use strict';

var DB_NAME = 'ai-fcard-push';
var STORE = 'cfg';

function idbOpen() {
  return new Promise(function (resolve, reject) {
    var req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = function () {
      if (!req.result.objectStoreNames.contains(STORE)) req.result.createObjectStore(STORE);
    };
    req.onsuccess = function () { resolve(req.result); };
    req.onerror = function () { reject(req.error); };
  });
}
function idbGet(key) {
  return idbOpen().then(function (db) {
    return new Promise(function (resolve) {
      var g = db.transaction(STORE, 'readonly').objectStore(STORE).get(key);
      g.onsuccess = function () { resolve(g.result || null); };
      g.onerror = function () { resolve(null); };
    });
  }).catch(function () { return null; });
}
function idbSet(key, val) {
  return idbOpen().then(function (db) {
    return new Promise(function (resolve) {
      var tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).put(val, key);
      tx.oncomplete = function () { resolve(true); };
      tx.onerror = function () { resolve(false); };
    });
  }).catch(function () { return false; });
}

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

// ページから session 情報を受け取り保存する。
self.addEventListener('message', function (e) {
  var d = e.data || {};
  if (d.type === 'push-config' && d.config) {
    e.waitUntil(idbSet('sub', d.config));
  }
});

function fetchUnread(cfg) {
  if (!cfg || !cfg.apiBase || !cfg.sessionId) return Promise.resolve(null);
  var url = cfg.apiBase + '/customer/poll.php?session_id=' + encodeURIComponent(cfg.sessionId)
    + (cfg.visitorId ? '&visitor_id=' + encodeURIComponent(cfg.visitorId) : '');
  return fetch(url, { credentials: 'include' })
    .then(function (r) { return r.json(); })
    .then(function (j) { return (j && j.success && j.data) ? (parseInt(j.data.unread_count, 10) || 0) : null; })
    .catch(function () { return null; });
}

self.addEventListener('push', function (e) {
  e.waitUntil(idbGet('sub').then(function (cfg) {
    return fetchUnread(cfg).then(function (count) {
      if (count === null) {
        // Push本文があれば利用（将来拡張）。無ければ通知だけ出す。
        try { count = e.data ? (e.data.json().unread || 0) : 0; } catch (x) { count = 0; }
      }
      var tasks = [];
      if ('setAppBadge' in self.navigator) {
        try {
          if (count > 0) tasks.push(self.navigator.setAppBadge(count));
          else if ('clearAppBadge' in self.navigator) tasks.push(self.navigator.clearAppBadge());
        } catch (x) {}
      }
      var body = count > 0 ? ('未読メッセージが' + count + '件あります') : '担当者からメッセージが届きました';
      tasks.push(self.registration.showNotification('新しいメッセージ', {
        body: body,
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        tag: 'ai-fcard-message',
        renotify: true,
        data: { url: (cfg && cfg.startUrl) ? cfg.startUrl : '/' }
      }));
      return Promise.all(tasks);
    });
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) ? e.notification.data.url : '/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if ('focus' in list[i]) { try { return list[i].focus(); } catch (x) {} }
    }
    if (self.clients.openWindow) return self.clients.openWindow(url);
  }));
});

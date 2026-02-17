/* Minimal Service Worker for PWA installability */
self.addEventListener('install', (event) => {
  // Activate immediately after install
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Optional: keep it network-first (no offline caching yet)
self.addEventListener('fetch', () => {
  // Intentionally empty for now
});
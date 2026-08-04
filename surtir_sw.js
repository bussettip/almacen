self.addEventListener('install', e => {
  self.skipWaiting();
  caches.keys().then(names => names.forEach(n => caches.delete(n)));
});
self.addEventListener('activate', e => e.waitUntil(clients.claim()));
self.addEventListener('fetch', e => {
  e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});

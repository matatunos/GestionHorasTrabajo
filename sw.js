/**
 * Service Worker — Gestión de Horas
 * Cachea assets estáticos para funcionamiento offline básico
 */
const CACHE_VERSION = 'v1';
const CACHE_NAME = `gestion-horas-${CACHE_VERSION}`;

// Assets para pre-cachear en la instalación
const PRECACHE_ASSETS = [
  '/',
  '/styles.css',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_ASSETS.map(url => new Request(url, { cache: 'reload' }))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keyList) => Promise.all(
        keyList.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  // No cachear llamadas API, POST, ni rutas de auth
  if (
    event.request.method !== 'GET' ||
    event.request.url.includes('/api.php') ||
    event.request.url.includes('/login.php') ||
    event.request.url.includes('/logout.php') ||
    event.request.url.includes('/vacaciones.php')
  ) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const networkFetch = fetch(event.request).then((response) => {
        if (response.ok && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        }
        return response;
      });
      return cached || networkFetch;
    }).catch(() => caches.match('/'))
  );
});

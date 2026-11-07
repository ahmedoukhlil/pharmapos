/*
 * PharmaPOS - service worker.
 * Les pages et Livewire passent toujours par le réseau (données de caisse à jour, jamais servies en cache).
 * Seuls les fichiers compilés (/build/, noms versionnés) et les icônes sont gardés : démarrage rapide.
 * Hors connexion, une page explique qu'il faut retrouver le réseau.
 */
const CACHE = 'pharmapos-v1';
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll([OFFLINE, '/images/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Fichiers compilés et icônes : le cache d'abord (le nom change à chaque build)
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/')) {
        event.respondWith(
            caches.match(req).then((hit) => hit || fetch(req).then((res) => {
                if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
                return res;
            })),
        );
        return;
    }

    // Pages : toujours le réseau ; la page hors connexion seulement si le réseau manque
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
    }
});

/*
 * ARGO PWA — Service Worker ( recznie pisany, serwowany z root => scope '/').
 * Faza 1: instalowalnosc + obsluga Web Push (push + klik w powiadomienie).
 * Cache offline (precache shellu) mozemy dodac pozniej — na razie passthrough.
 */

const SW_VERSION = 'argo-pwa-v1';

self.addEventListener('install', () => {
    // nowy SW wchodzi od razu, bez czekania na zamkniecie kart
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Minimalny fetch handler (passthrough) — zwieksza zgodnosc kryteriow instalacji.
self.addEventListener('fetch', () => {
    // brak cache na razie; przegladarka obsluguje zadanie normalnie
});

// Web Push — pokaz powiadomienie systemowe.
self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { title: 'ARGO', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'ARGO';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/icons/argo-192.png',
        badge: payload.badge || '/icons/argo-192.png',
        tag: payload.tag || undefined,
        renotify: Boolean(payload.tag),
        data: { url: (payload.data && payload.data.url) || payload.url || '/admin/m' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Klik w powiadomienie — podnies istniejace okno aplikacji albo otworz nowe na danym URL.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = (event.notification.data && event.notification.data.url) || '/admin/m';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsArr) => {
            for (const client of clientsArr) {
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) {
                        try {
                            client.navigate(targetUrl);
                        } catch (e) {
                            /* niektore przegladarki blokuja navigate — ignorujemy */
                        }
                    }
                    return;
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});

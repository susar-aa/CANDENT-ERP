/**
 * Fintrix Rep PWA Service Worker
 * Required for the browser to recognize the app as installable (Add to Home Screen).
 */
const CACHE_NAME = 'candent-rep-cache-v1';

// Install Event
self.addEventListener('install', (e) => {
    // Skip waiting forces the waiting service worker to become the active service worker
    self.skipWaiting();
});

// Activate Event
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        })
    );
});

// Fetch Event (Required for PWA installation criteria)
self.addEventListener('fetch', (e) => {
    // For a dynamic POS, we bypass caching completely and just fetch from the network.
    // This simply fulfills the PWA requirement that a fetch handler must exist.
    e.respondWith(fetch(e.request));
});
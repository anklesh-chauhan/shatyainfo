importScripts('https://www.gstatic.com/firebasejs/11.10.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/11.10.0/firebase-messaging-compat.js');
firebase.initializeApp(@json(config('services.firebase.public')));
firebase.messaging();

self.addEventListener('notificationclick', event => {
    const url = event.notification.data?.url;
    if (!url || new URL(url).origin !== self.location.origin) return;
    event.notification.close();
    event.waitUntil(clients.openWindow(url));
});

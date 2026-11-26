importScripts('https://www.gstatic.com/firebasejs/13.3.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/13.3.0/firebase-messaging-compat.js');

const config = new URL(self.location.href).searchParams.get('config');
if (config) {
    firebase.initializeApp(JSON.parse(config));
    firebase.messaging();
}

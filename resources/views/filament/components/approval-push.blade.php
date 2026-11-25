@if (config('services.firebase.enabled'))
    @once
        <script>
            window.approvalPush = () => ({
                label: 'Enable approval alerts',
                busy: false,
                async init() {
                    if ('Notification' in window && Notification.permission === 'granted') await this.enable();
                },
                async enable() {
                    this.busy = true;
                    try {
                        const { initializeApp, getApps } = await import('https://www.gstatic.com/firebasejs/11.10.0/firebase-app.js');
                        const { getMessaging, getToken, deleteToken, onMessage, isSupported } = await import('https://www.gstatic.com/firebasejs/11.10.0/firebase-messaging.js');
                        const vapidKey = @js(config('services.firebase.vapid_key'));
                        if (!await isSupported() || !vapidKey) throw new Error('Push unavailable');
                        if (await Notification.requestPermission() !== 'granted') {
                            this.label = 'Alerts blocked in browser settings';
                            return;
                        }
                        const app = getApps()[0] || initializeApp(@js(config('services.firebase.public')));
                        const messaging = getMessaging(app);
                        const owner = @js((string) auth()->id());
                        const previous = localStorage.getItem('approval-push-owner');
                        if (previous && previous !== owner) await deleteToken(messaging);
                        localStorage.setItem('approval-push-owner', owner);
                        const worker = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
                        await navigator.serviceWorker.ready;
                        const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: worker });
                        const response = await fetch(@js(route('push-subscriptions.store')), { method: 'POST', headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()),
                        }, body: JSON.stringify({ token, platform: 'web' }) });
                        if (!response.ok) throw new Error('Registration failed');
                        this.label = 'Approval alerts enabled';
                        if (!window.approvalPushListening) {
                            window.approvalPushListening = true;
                            onMessage(messaging, payload => {
                                worker.showNotification(payload.notification.title, {
                                    body: payload.notification.body,
                                    tag: `approval-${payload.data?.approval_id || ''}`,
                                    data: { url: @js(route('filament.admin.pages.my-approvals')) },
                                });
                                window.dispatchEvent(new CustomEvent('database-notifications-sent'));
                            });
                        }
                    } catch (error) {
                        this.label = 'Retry approval alerts';
                    } finally {
                        this.busy = false;
                    }
                },
            });
        </script>
    @endonce
    <span x-data="approvalPush" hidden aria-hidden="true"></span>
@endif

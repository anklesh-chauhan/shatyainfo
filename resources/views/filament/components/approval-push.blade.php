@if (config('services.firebase.enabled'))
    @once
        <script>
            window.approvalPush = () => ({
                label: 'Enable approval alerts',
                busy: false,
                async init() {
                    if ('Notification' in window && Notification.permission === 'granted') await this.enable();
                },
                requestFromBell(event) {
                    if (!(event.target instanceof Element) || !event.target.closest('.fi-topbar-database-notifications-btn, .fi-sidebar-database-notifications-btn')) return;
                    if (this.label !== 'Approval alerts enabled') this.enable(true);
                },
                feedback(title, body, status = 'warning') {
                    if (typeof window.FilamentNotification === 'function') {
                        new window.FilamentNotification().title(title).body(body).status(status).send();
                    }
                },
                async enable(showFeedback = false) {
                    if (this.busy) return;
                    this.busy = true;
                    try {
                        if (!window.isSecureContext || !('Notification' in window) || !('serviceWorker' in navigator)) {
                            if (showFeedback) this.feedback('Browser notifications unavailable', 'Use a supported browser on HTTPS to receive approval alerts.');
                            return;
                        }
                        if (Notification.permission === 'denied') {
                            this.label = 'Alerts blocked in browser settings';
                            if (showFeedback) this.feedback('Notifications are blocked', 'Allow Notifications in this website’s browser permissions, then refresh the page.');
                            return;
                        }
                        const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
                        if (permission !== 'granted') {
                            this.label = 'Enable approval alerts';
                            if (showFeedback) this.feedback('Approval alerts are not enabled', 'Allow notifications to receive approval alerts. You can click the bell to try again.');
                            return;
                        }
                        const { initializeApp, getApps } = await import('https://www.gstatic.com/firebasejs/11.10.0/firebase-app.js');
                        const { getMessaging, getToken, deleteToken, onMessage, isSupported } = await import('https://www.gstatic.com/firebasejs/11.10.0/firebase-messaging.js');
                        const vapidKey = @js(config('services.firebase.vapid_key'));
                        if (!await isSupported() || !vapidKey) throw new Error('Push unavailable');
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
                        if (!response.ok) throw Object.assign(new Error('Registration failed'), { code: `registration-http-${response.status}` });
                        this.label = 'Approval alerts enabled';
                        if (showFeedback) this.feedback('Approval alerts enabled', 'This browser will receive approval push notifications.', 'success');
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
                        if (showFeedback) this.feedback('Could not enable approval alerts', `Refresh the page and try again. Error: ${error.code || error.name || 'registration-failed'}`);
                    } finally {
                        this.busy = false;
                    }
                },
            });
        </script>
    @endonce
    <span x-data="approvalPush" x-on:click.window="requestFromBell($event)" hidden aria-hidden="true"></span>
@endif

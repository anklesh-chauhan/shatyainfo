(() => {
    if (!document.querySelector('[data-registration-status]')) return;
    let polling = false;
    let timer;
    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            const messages = {
                awaiting_verification: 'Check your inbox and verify your email to continue.',
                pending: 'Email verified. Registration is queued…',
                provisioning: 'Creating your database and sign-in account…',
                failed: 'Registration could not finish. Refresh this page to retry.',
                ready: 'Your company is ready. Opening your workspace…',
            };
            document.getElementById('setup-progress').textContent = messages[data.status] || 'Preparing your workspace…';
            document.getElementById('company-login').hidden = data.status !== 'ready';
            if (data.redirect_url) {
                clearInterval(timer);
                window.location.replace(data.redirect_url);
            } else if (data.status === 'ready') {
                document.getElementById('setup-progress').textContent = 'Your company is ready. Sign in to continue onboarding.';
                clearInterval(timer);
            }
        } catch {
            document.getElementById('setup-progress').textContent = 'Reconnecting to check registration progress…';
        } finally {
            polling = false;
        }
    }
    timer = setInterval(poll, 3000);
    poll();
})();

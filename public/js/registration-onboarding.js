(() => {
    if (!document.querySelector('[data-registration-status]')) return;
    let polling = false;
    let timer;
    let leaving = false;
    let navigating = false;
    let fallback;
    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await response.json();
            const messages = {
                awaiting_verification: 'Enter the six-digit code from your email to continue.',
                pending: 'Email verified. Registration is queued…',
                provisioning: 'Creating your database and sign-in account…',
                failed: 'Registration could not finish. Refresh this page to retry.',
                ready: 'Your company is ready. Opening your workspace…',
            };
            const destination = data.redirect_url || (data.status === 'ready' ? data.login_url : null);
            const link = document.getElementById('company-login');
            document.getElementById('setup-progress').textContent = (data.status === 'provisioning' && data.step) || messages[data.status] || 'Preparing your workspace…';
            const progress = Math.max(0, Math.min(100, Number(data.progress) || 0));
            document.getElementById('registration-progress-bar').hidden = data.status === 'awaiting_verification';
            document.getElementById('registration-progress').value = progress;
            document.getElementById('registration-percentage').textContent = progress + '% complete';
            if (link) {
                if (destination) {
                    link.href = destination;
                }
                link.hidden = !destination;
            }
            if (destination && !navigating) {
                leave(destination);
            }
        } catch {
            document.getElementById('setup-progress').textContent = 'Reconnecting to check registration progress…';
        } finally {
            polling = false;
        }
    }
    const link = document.getElementById('company-login');
    link?.addEventListener('click', () => {
        navigating = true;
        clearInterval(timer);
        clearTimeout(fallback);
    });
    timer = setInterval(poll, 3000);
    poll();

    window.addEventListener('pagehide', () => {
        leaving = true;
    });

    function leave(url) {
        clearInterval(timer);
        window.location.assign(url);
        clearTimeout(fallback);
        fallback = window.setTimeout(() => {
            if (!leaving && !navigating) {
                window.location.reload();
            }
        }, 4000);
    }
})();

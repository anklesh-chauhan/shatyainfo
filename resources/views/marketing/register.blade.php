@extends('marketing.layout')

@section('title', 'Create a company · Shatya Info')

@if ($turnstileSiteKey)
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@section('content')
    <div class="narrow">
        <p class="kicker">Registration</p>
        <h1 style="font-size: 2.4rem;">Create your company</h1>
        <p class="lede">This creates a new workspace and makes you the owner. Verify your email, then we will prepare your workspace.</p>

        <form class="panel" method="post" action="{{ route('signup.store') }}">
            @csrf
            <label>
                Company name
                <input name="company" value="{{ old('company') }}" required maxlength="255" autocomplete="organization">
                @error('company') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Company address
                <span class="slug-row">
                    <input id="slug" name="slug" value="{{ old('slug') }}" required minlength="3" maxlength="32" autocapitalize="none" spellcheck="false" placeholder="acme" aria-describedby="slug-preview slug-status">
                    <span class="suffix">.{{ $baseDomain }}</span>
                </span>
                <span class="hint" id="slug-preview">Your team will sign in at this address.</span>
                <span class="hint" id="slug-status" role="status"></span>
                @error('slug') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Your name
                <input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name">
                @error('name') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Email
                <input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
                @error('email') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Password
                <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" aria-describedby="password-rules">
                <ul class="rules" id="password-rules">
                    <li data-rule="length">At least 12 characters</li>
                    <li data-rule="lower">One lowercase letter</li>
                    <li data-rule="upper">One uppercase letter</li>
                    <li data-rule="match">Passwords match</li>
                </ul>
                @error('password') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Confirm password
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
            </label>
            @if ($turnstileSiteKey)
                <div>
                    <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
                    @error('cf-turnstile-response') <span class="error">{{ $message }}</span> @enderror
                </div>
            @else
                <label>
                    Enter the characters shown
                    <img id="registration-captcha" src="{{ route('signup.captcha') }}" alt="CAPTCHA characters" width="220" height="70">
                    <button type="button" data-captcha-url="{{ route('signup.captcha') }}" onclick="document.getElementById('registration-captcha').src = this.dataset.captchaUrl + '?refresh=' + Date.now()">Refresh CAPTCHA</button>
                    <input name="captcha" required maxlength="6" autocomplete="off">
                    @error('captcha') <span class="error">{{ $message }}</span> @enderror
                </label>
            @endif
            <button class="btn btn-primary" type="submit">Create company</button>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    const slug = document.getElementById('slug');
    const preview = document.getElementById('slug-preview');
    const status = document.getElementById('slug-status');
    const password = document.getElementById('password');
    const confirmation = document.getElementById('password_confirmation');
    const submit = document.querySelector('button[type="submit"]');
    const base = @json($baseDomain);
    const availabilityUrl = @json(route('signup.address'));
    let availabilityTimer;
    const passwordTests = {
        length: (value) => value.length >= 12,
        lower: (value) => /[a-z]/.test(value),
        upper: (value) => /[A-Z]/.test(value),
    };

    const renderPassword = () => {
        const value = password.value;
        Object.entries(passwordTests).forEach(([name, test]) => {
            const item = document.querySelector('[data-rule="' + name + '"]');
            const passed = test(value);
            item.classList.toggle('met', passed);
            item.classList.toggle('unmet', value.length > 0 && !passed);
        });
        const match = document.querySelector('[data-rule="match"]');
        const matches = confirmation.value.length > 0 && password.value === confirmation.value;
        match.classList.toggle('met', matches);
        match.classList.toggle('unmet', confirmation.value.length > 0 && !matches);
        syncSubmit();
    };

    const passwordReady = () => Object.values(passwordTests).every((test) => test(password.value))
        && confirmation.value.length > 0
        && password.value === confirmation.value;

    const syncSubmit = () => {
        const state = status.dataset.state || '';
        const addressReady = state === 'available' || state === 'error';
        submit.disabled = !(passwordReady() && addressReady);
    };

    const renderSlug = () => {
        const value = (slug.value || 'your-company').toLowerCase().trim();
        preview.textContent = 'Sign-in address: ' + value + '.' + base;
    };

    const checkSlug = () => {
        clearTimeout(availabilityTimer);
        const value = slug.value.toLowerCase().trim();
        if (slug.value !== value) {
            slug.value = value;
        }
        renderSlug();
        if (value.length < 3) {
            status.dataset.state = 'invalid';
            status.className = value ? 'hint bad' : 'hint';
            status.textContent = value ? 'Use at least 3 characters.' : '';
            syncSubmit();
            return;
        }
        status.dataset.state = 'checking';
        status.className = 'hint';
        status.textContent = 'Checking availability…';
        syncSubmit();
        availabilityTimer = setTimeout(async () => {
            try {
                const response = await fetch(availabilityUrl + '?slug=' + encodeURIComponent(value), {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok) {
                    throw new Error('unavailable');
                }
                const data = await response.json();
                if (slug.value.toLowerCase().trim() !== value) {
                    return;
                }
                status.dataset.state = data.status;
                status.className = 'hint ' + (data.available ? 'ok' : 'bad');
                status.textContent = data.message;
            } catch {
                if (slug.value.toLowerCase().trim() !== value) {
                    return;
                }
                status.dataset.state = 'error';
                status.className = 'hint bad';
                status.textContent = 'Could not check that address. You can still try to create the company.';
            }
            syncSubmit();
        }, 300);
    };

    slug.addEventListener('input', checkSlug);
    password.addEventListener('input', renderPassword);
    confirmation.addEventListener('input', renderPassword);
    renderSlug();
    renderPassword();
    if (slug.value) {
        checkSlug();
    }
</script>
@endpush

@extends('marketing.layout')

@section('title', 'Create a company · Shatya Info')

@if ($turnstileSiteKey)
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif

@push('head')
    <style>
        .registration-page {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: clamp(24px, 4vw, 56px);
            align-items: start;
            margin: 12px 0;
        }
        .registration-copy, .registration-form { min-width: 0; }
        .registration-copy .lede { max-width: none; }
        .registration-offer p:last-child { margin-bottom: 0; }
        @media (max-width: 900px) {
            .registration-page { grid-template-columns: 1fr; gap: 24px; }
        }
    </style>
@endpush

@section('content')
    <div class="registration-page">
        <div class="registration-copy">
            <p class="kicker">Start your free trial</p>
            <h1 style="font-size: 2.4rem;">Set up your pharma field team</h1>
            <p class="lede">Plan visits, record calls, and review the day in one company workspace. Choose your company address and create an owner account. After you verify your email, we will prepare your workspace and show you what to set up first.</p>
            <div class="panel registration-offer">
                <strong>30 days free. Every module included. No card needed.</strong>
                <p>Your trial starts when the workspace is ready, not when you submit this form. After 30 days, the price is ₹59 per user license each month, with a 10-license minimum of ₹590 per month.</p>
                <p>The company owner pays each bill through Razorpay. There is no automatic card charge. Cancel from Billing before the trial ends and owe nothing for the trial.</p>
                <p><a href="{{ route('legal.show', 'trial-terms') }}">Read the trial terms</a> or <a href="{{ route('help.index') }}">see the setup steps</a>.</p>
            </div>
        </div>

        <form class="panel registration-form" method="post" action="{{ route('signup.store') }}">
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
                <span class="hint" id="slug-preview">Your team will use this address to sign in.</span>
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
                Mobile number
                <input name="mobile_number" type="tel" value="{{ old('mobile_number') }}" required maxlength="30" autocomplete="tel" placeholder="+91 9876543210">
                <span class="hint">Include your country code. Indian numbers can also use 10 digits.</span>
                @error('mobile_number') <span class="error">{{ $message }}</span> @enderror
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
            <div>
                <label style="display:flex;align-items:flex-start;gap:10px">
                    <input type="checkbox" name="legal_acceptance" value="1" required @checked(old('legal_acceptance')) style="width:auto;margin-top:5px">
                    <span>I agree to the <a href="{{ route('legal.show', 'service-agreement') }}" target="_blank" rel="noopener">Service Agreement</a> and <a href="{{ route('legal.show', 'trial-terms') }}" target="_blank" rel="noopener">Trial Terms</a>, and I have read the <a href="{{ route('legal.show', 'privacy') }}" target="_blank" rel="noopener">Privacy Notice</a> and <a href="{{ route('legal.show', 'data-processing-agreement') }}" target="_blank" rel="noopener">DPA</a>.</span>
                </label>
                @error('legal_acceptance') <span class="error">{{ $message }}</span> @enderror
            </div>
            <button class="btn btn-primary" type="submit">Create workspace</button>
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

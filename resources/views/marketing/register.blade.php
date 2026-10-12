@extends('marketing.layout')

@section('title', 'Create a company · Shatya Info')

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
                    <input id="slug" name="slug" value="{{ old('slug') }}" required minlength="3" maxlength="32" autocapitalize="none" spellcheck="false" placeholder="acme" aria-describedby="slug-preview">
                    <span class="suffix">.{{ $baseDomain }}</span>
                </span>
                <span class="hint" id="slug-preview">Your team will sign in at this address.</span>
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
                <input name="password" type="password" required minlength="12" autocomplete="new-password">
                <span class="hint">At least 12 characters.</span>
                @error('password') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Confirm password
                <input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
            </label>
            <label>
                Enter the characters shown
                <img id="registration-captcha" src="{{ route('signup.captcha') }}" alt="CAPTCHA characters" width="220" height="70">
                <button type="button" data-captcha-url="{{ route('signup.captcha') }}" onclick="document.getElementById('registration-captcha').src = this.dataset.captchaUrl + '?refresh=' + Date.now()">Refresh CAPTCHA</button>
                <input name="captcha" required maxlength="6" autocomplete="off">
                @error('captcha') <span class="error">{{ $message }}</span> @enderror
            </label>
            <button class="btn btn-primary" type="submit">Create company</button>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    const slug = document.getElementById('slug');
    const preview = document.getElementById('slug-preview');
    const base = @json($baseDomain);
    const render = () => {
        const value = (slug.value || 'your-company').toLowerCase().trim();
        preview.textContent = 'Sign-in address: ' + value + '.' + base;
    };
    slug.addEventListener('input', render);
    render();
</script>
@endpush

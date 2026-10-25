@extends('marketing.layout')

@section('title', 'Registration progress · Shatya Info')

@push('head')
    <style>
        [data-registration-status] [hidden] { display: none !important; }
        .verification-panel { margin: 24px 0; padding: 22px; background: var(--card); border: 1px solid var(--line); border-radius: 20px; box-shadow: 0 12px 30px rgba(16, 38, 75, .05); }
        .verification-form { gap: 16px; }
        .verification-form .btn-primary { width: 100%; min-height: 48px; border-radius: 12px; font-weight: 600; }
        .verification-resend { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--line); }
        .verification-resend p { margin: 0; color: var(--muted); font-size: .88rem; }
        .verification-resend form { display: block; }
        .verification-resend .btn-line { min-height: 42px; border-radius: 12px; padding: 9px 16px; color: var(--blue); font-weight: 600; white-space: nowrap; }
        .verification-resend .btn-line:hover { background: #edf4ff; border-color: #bfd3f5; }
        .verification-feedback { margin: 16px 0 0; color: var(--blue-dark); font-size: .88rem; }
        @media (max-width: 480px) { .verification-panel { padding: 18px; } .verification-resend { align-items: flex-start; } }
    </style>
    <script src="{{ asset('js/registration-onboarding.js') }}" defer></script>
@endpush

@section('content')
    <div class="narrow" data-registration-status>
        <p class="kicker">{{ $tenant->name }}</p>
        <h1 style="font-size: 2.4rem;">Registration progress</h1>
        <p class="lede">{{ $tenant->domain }}</p>
        @php($status = $tenant->provisioning_status)
        <p id="setup-progress" role="status">
            @if($status === 'awaiting_verification')
                Enter the six-digit code sent to {{ $tenant->provisioning_owner['email'] }}. It expires in 10 minutes.
            @elseif($status === 'ready')
                Your company is ready. Opening your workspace…
            @elseif($status === 'failed')
                Registration could not finish. Please retry.
            @else
                Email verified. Creating your database and sign-in account…
            @endif
        </p>
        <div @if($status === 'awaiting_verification') hidden @endif id="registration-progress-bar">
            <progress style="width: 100%;" id="registration-progress" max="100" value="{{ $tenant->provisioningProgress() }}" aria-label="Registration progress"></progress>
            <p id="registration-percentage">{{ $tenant->provisioningProgress() }}% complete</p>
        </div>
        @if($status === 'awaiting_verification')
            <div class="verification-panel">
                <form class="verification-form" method="post" action="{{ $verifyUrl }}">
                    @csrf
                    <label>
                        Email verification code
                        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                        @error('code') <span class="error">{{ $message }}</span> @enderror
                    </label>
                    <button class="btn btn-primary" type="submit">Verify email</button>
                </form>
                <div class="verification-resend">
                    <p>Didn't receive the code?</p>
                    <form method="post" action="{{ $resendUrl }}">
                        @csrf
                        <button class="btn btn-line" type="submit">Send a new code</button>
                    </form>
                </div>
                @if(session('status')) <p class="verification-feedback" role="status">{{ session('status') }}</p> @endif
            </div>
        @elseif($status === 'failed')
            <form method="post" action="{{ $retryUrl }}">
                @csrf
                <button class="btn btn-primary" type="submit">Try again</button>
            </form>
        @endif
        <p>Onboarding continues inside your workspace once registration is complete.</p>
        <p><a id="company-login" class="btn btn-primary" href="{{ $loginUrl }}" @if($status !== 'ready') hidden @endif>Sign in to your company</a></p>
    </div>
@endsection

@extends('marketing.layout')

@section('title', 'Registration progress · Shatya Info')

@push('head')
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
                Check your inbox and verify your email to continue.
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
            <form method="post" action="{{ $resendUrl }}">
                @csrf
                <button class="btn btn-primary" type="submit">Resend verification email</button>
            </form>
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

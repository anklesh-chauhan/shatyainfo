@extends('marketing.layout')

@section('title', 'Resume registration · Shatya Info')

@section('content')
    <div class="narrow">
        <p class="kicker">Continue where you left off</p>
        <h1 style="font-size: 2.4rem;">Resume registration</h1>
        <p class="lede">If registration was interrupted, enter your existing company address and registered email. We will email a fresh link to your progress page.</p>
        <p>Email verification is required before we create your database. Company setup becomes available once your workspace is ready.</p>

        @if(session('status')) <p role="status">{{ session('status') }}</p> @endif

        <form class="panel" method="post" action="{{ route('signup.resume.store') }}">
            @csrf
            <label>
                Company address
                <input name="company" value="{{ old('company') }}" required maxlength="255" autocapitalize="none" spellcheck="false" placeholder="acme.{{ $baseDomain }}" autocomplete="organization">
                <span class="hint">You can also enter the short name, such as <em>acme</em>.</span>
                @error('company') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label>
                Registered email
                <input type="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email">
                @error('email') <span class="error">{{ $message }}</span> @enderror
            </label>
            <button class="btn btn-primary" type="submit">Email my progress link</button>
        </form>
        <p>Workspace already ready? <a href="{{ route('company.login') }}">Sign in to your company</a>.</p>
    </div>
@endsection

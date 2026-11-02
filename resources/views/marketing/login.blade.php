@extends('marketing.layout')

@section('title', 'Sign in · Shatya Info')

@section('content')
    <div class="narrow">
        <p class="kicker">Sign in</p>
        <h1 style="font-size: 2.4rem;">Sign in to your company</h1>
        <p class="lede">Enter your company address to open its sign-in page.</p>

        <form class="panel" method="post" action="{{ route('company.login.store') }}">
            @csrf
            <label>
                Company address
                <input name="company" value="{{ old('company') }}" required maxlength="255" autocapitalize="none" spellcheck="false" placeholder="acme.{{ $baseDomain }}" autocomplete="organization">
                <span class="hint">You can also enter the short name, such as <em>acme</em>.</span>
                @error('company') <span class="error">{{ $message }}</span> @enderror
            </label>
            <button class="btn btn-primary" type="submit">Continue</button>
        </form>
    </div>
@endsection

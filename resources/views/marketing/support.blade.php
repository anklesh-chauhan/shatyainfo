@extends('marketing.layout')

@section('title', 'Contact support · Shatya Info')

@section('content')
<div class="narrow">
    <p class="kicker">SUPPORT</p>
    <h1 style="font-size:2.4rem">How can we help?</h1>
    <p class="lede">For setup and billing answers, <a href="{{ route('help.index') }}">check the short guides</a>. If you still need help, send a message below.</p>
    @if(session('support_status')) <p class="panel" role="status">{{ session('support_status') }}</p> @endif
    <form class="panel" method="post" action="{{ route('support.store') }}">
        @csrf
        <label>Your name<input name="name" value="{{ old('name') }}" required maxlength="120">@error('name')<span class="error">{{ $message }}</span>@enderror</label>
        <label>Email<input type="email" name="email" value="{{ old('email') }}" required maxlength="255">@error('email')<span class="error">{{ $message }}</span>@enderror</label>
        <label>Company address, if you have one<input name="company_address" value="{{ old('company_address') }}" maxlength="255"></label>
        <label>What do you need help with?<input name="topic" value="{{ old('topic') }}" required maxlength="120">@error('topic')<span class="error">{{ $message }}</span>@enderror</label>
        <label>Message<textarea name="message" required minlength="15" maxlength="5000" rows="6" style="width:100%;border:1px solid var(--line);border-radius:12px;padding:12px;font:inherit">{{ old('message') }}</textarea>@error('message')<span class="error">{{ $message }}</span>@enderror</label>
        <div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <button class="btn btn-primary" type="submit">Send request</button>
    </form>
    <p class="hint">You can also email <a href="mailto:support@shatyainfo.com">support@shatyainfo.com</a>.</p>
</div>
@endsection

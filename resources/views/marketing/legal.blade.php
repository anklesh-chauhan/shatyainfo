@extends('marketing.layout')

@section('title', $title.' · Shatya Info')

@push('head')
    <style>
        .legal-page { max-width: 820px; margin: 42px auto 90px; }
        .legal-page .legal-links { display: flex; flex-wrap: wrap; gap: 10px 18px; margin: 0 0 32px; font-size: .9rem; }
        .legal-page .legal-links a { color: var(--blue); }
        .legal-copy { color: #233858; font-size: 1rem; line-height: 1.75; overflow-wrap: anywhere; }
        .legal-copy h1, .legal-copy h2, .legal-copy h3 { color: #102e60; line-height: 1.22; }
        .legal-copy h1 { font-size: clamp(2rem, 4vw, 3rem); margin-bottom: 12px; }
        .legal-copy h2 { font-size: 1.4rem; margin: 42px 0 12px; }
        .legal-copy h3 { font-size: 1.12rem; margin: 28px 0 10px; }
        .legal-copy p, .legal-copy ul, .legal-copy ol { margin: 0 0 18px; }
        .legal-copy li { margin-bottom: 8px; }
        .legal-copy table { border-collapse: collapse; width: 100%; font-size: .9rem; }
        .legal-copy th, .legal-copy td { border: 1px solid var(--line); padding: 9px 12px; vertical-align: top; text-align: left; }
        .legal-copy table { display: block; overflow-x: auto; }
        .legal-copy a { color: var(--blue); }
        @media print { header.site, .site-footer, .legal-links { display: none !important; } .legal-page { margin: 0; max-width: none; } }
    </style>
@endpush

@section('content')
    <div class="legal-page">
        <nav class="legal-links" aria-label="Legal documents">
            <a href="{{ route('legal.show', 'service-agreement') }}">Service Agreement</a>
            <a href="{{ route('legal.show', 'privacy') }}">Privacy Notice</a>
            <a href="{{ route('legal.show', 'trial-terms') }}">Trial Terms</a>
            <a href="{{ route('legal.show', 'data-processing-agreement') }}">DPA</a>
            @if($document === 'data-processing-agreement')
                <a href="{{ route('legal.dpa.download') }}">Download DPA (PDF)</a>
            @endif
        </nav>
        <article class="legal-copy">{!! $content !!}</article>
    </div>
@endsection

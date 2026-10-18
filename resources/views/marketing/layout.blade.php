<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Shatya Info connects pharma field visits, doctor relationships, samples, sales, inventory, attendance, and approvals in one company workspace.">
    <meta name="theme-color" content="#175cdb">
    <title>@yield('title', 'Shatya Info')</title>
    @stack('head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    <style>
        :root {
            --paper: #f7faff;
            --ink: #10264b;
            --muted: #536580;
            --line: #dce6f5;
            --card: #ffffff;
            --blue: #175cdb;
            --blue-dark: #1246a6;
            --green: var(--blue);
            --green-dark: var(--blue-dark);
            --amber: #2368c4;
            --danger: #9f2d2d;
            --danger-bg: #fdecec;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            background:
                radial-gradient(900px 600px at 100% 0%, rgba(75, 153, 255, .14), transparent 65%),
                radial-gradient(700px 380px at -10% 0%, rgba(23, 92, 219, .06), transparent 55%),
                var(--paper);
            font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            line-height: 1.5;
        }
        a { color: inherit; }
        .wrap { width: min(1180px, calc(100% - 40px)); margin: 0 auto; }
        header.site {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 0; border-bottom: 1px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; font-weight: 700; letter-spacing: -.02em; }
        .mark {
            width: 34px; height: 34px; border-radius: 9px;
            background: var(--green); color: white;
            display: grid; place-items: center; font-weight: 700;
        }
        nav { display: flex; align-items: center; gap: 10px; }
        .btn, button.btn {
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 999px; padding: 10px 16px; font-weight: 600;
            text-decoration: none; border: 1px solid transparent; cursor: pointer;
            font: inherit;
        }
        .btn-ghost { background: transparent; border-color: transparent; }
        .btn-ghost:hover { background: rgba(23, 32, 51, .05); }
        .btn-primary { background: var(--blue); color: white; box-shadow: 0 5px 16px #175cdb20; }
        .btn-primary:hover { background: var(--green-dark); }
        .btn-line { background: var(--card); border-color: var(--line); }
        main { padding: 28px 0 64px; }
        .hero { display: grid; grid-template-columns: 1.15fr .85fr; gap: 36px; align-items: center; padding: 28px 0 12px; }
        h1 { font-size: clamp(2.4rem, 5vw, 4.2rem); line-height: .98; letter-spacing: -.045em; margin: 0 0 16px; }
        h2 { font-size: 1.7rem; letter-spacing: -.03em; margin: 0 0 8px; }
        .lede { font-size: 1.12rem; color: var(--muted); max-width: 38rem; margin: 0 0 22px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 22px;
            padding: 22px;
            box-shadow: 0 18px 40px rgba(23, 32, 51, .05);
        }
        .route-card { display: grid; gap: 12px; }
        .stop { display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: start; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
        .stop:last-child { border-bottom: 0; padding-bottom: 0; }
        .dot { width: 12px; height: 12px; margin-top: 6px; border-radius: 99px; background: var(--green); box-shadow: 0 0 0 4px rgba(13, 107, 76, .12); }
        .dot.amber { background: var(--amber); box-shadow: 0 0 0 4px rgba(183, 121, 31, .15); }
        .kicker { font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; color: var(--amber); font-weight: 700; margin: 0 0 8px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 42px; }
        .card h3 { margin: 0 0 6px; font-size: 1.05rem; }
        .card p { margin: 0; color: var(--muted); }
        .steps { margin-top: 48px; display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: start; }
        ol.clean { margin: 0; padding: 0; list-style: none; display: grid; gap: 14px; }
        ol.clean li { display: grid; grid-template-columns: auto 1fr; gap: 12px; }
        .num { width: 28px; height: 28px; border-radius: 99px; background: var(--ink); color: white; display: grid; place-items: center; font-size: .85rem; font-weight: 700; }
        form { display: grid; gap: 14px; }
        label { display: grid; gap: 6px; font-weight: 600; font-size: .92rem; }
        input {
            width: 100%; border: 1px solid var(--line); background: white; color: var(--ink);
            border-radius: 12px; padding: 12px 12px; font: inherit;
        }
        input:focus { outline: 2px solid #175cdb55; border-color: var(--blue); }
        .hint, .error { font-weight: 500; font-size: .82rem; }
        .hint { color: var(--muted); }
        .hint.ok { color: var(--green); }
        .hint.bad { color: var(--danger); }
        .error { color: var(--danger); }
        .rules { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
        .rules li { font-weight: 500; font-size: .82rem; color: var(--muted); }
        .rules li::before { content: "○ "; }
        .rules li.met { color: var(--green); }
        .rules li.met::before { content: "✓ "; }
        .rules li.unmet { color: var(--danger); }
        .rules li.unmet::before { content: "✕ "; }
        .btn-primary:disabled { opacity: .55; cursor: not-allowed; }
        .alert { background: var(--danger-bg); color: var(--danger); border-radius: 12px; padding: 10px 12px; }
        .slug-row { display: flex; align-items: center; gap: 0; }
        .slug-row input { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .suffix {
            border: 1px solid var(--line); border-left: 0; background: #edf4ff;
            padding: 12px 12px; border-top-right-radius: 12px; border-bottom-right-radius: 12px;
            color: var(--muted); white-space: nowrap;
        }
        .narrow { width: min(520px, 100%); margin: 12px auto; }
        .status-pill {
            display: inline-flex; align-items: center; gap: 8px;
            border-radius: 999px; padding: 6px 10px; background: #f3e7c8; color: #7a5410; font-weight: 700; font-size: .85rem;
        }
        .status-pill.ready { background: #e5f6ee; color: var(--green-dark); }
        .status-pill.failed { background: var(--danger-bg); color: var(--danger); }
        footer { color: var(--muted); font-size: .85rem; padding: 10px 0 28px; }
        @media (max-width: 800px) {
            .hero, .grid, .steps { grid-template-columns: 1fr; }
            header.site { gap: 12px; }
        }
        html { scroll-behavior: smooth; scroll-padding-top: 24px; }
        :focus-visible { outline: 3px solid #4485ed; outline-offset: 4px; }
        .skip-link { position: absolute; left: 20px; top: -100px; background: white; padding: 12px; z-index: 10; }
        .skip-link:focus { top: 12px; }
        .nav-link { font-size: .9rem; text-decoration: none; padding: 8px; }
        .nav-link:hover { color: var(--blue); }
        .hero { padding: 62px 0 56px; gap: 56px; }
        .hero h1 { line-height: 1.06; font-size: clamp(2.6rem, 5vw, 4.4rem); }
        .hero h1 span { color: var(--blue); }
        .hero .kicker { display: inline-block; border: 1px solid #cbdfff; background: #eaf2ff; border-radius: 99px; padding: 7px 12px; margin-bottom: 22px; }
        .hero-note { margin-top: 20px; font-size: .85rem; color: var(--muted); }
        .preview { background: #fff; border: 1px solid #cdddf4; border-radius: 20px; overflow: hidden; box-shadow: 0 24px 70px #163e7b18; transform: rotate(1deg); }
        .preview-head { display: flex; justify-content: space-between; align-items: center; padding: 17px 22px; background: #102e60; color: white; font-size: .85rem; }
        .preview-head small { color: #c3d8fa; }
        .preview-body { padding: 24px; }
        .preview-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; }
        .preview-title h2 { font-size: 1.35rem; }
        .pill { display: inline-block; background: #eaf2ff; color: #174fa5; padding: 5px 9px; border-radius: 7px; font-size: .72rem; font-weight: 600; }
        .preview-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 24px; }
        .preview-stats div { background: #f3f7ff; padding: 13px; border-radius: 10px; }
        .preview-stats strong { display: block; font-size: 1.5rem; }
        .preview-stats span { font-size: .72rem; color: var(--muted); }
        .tour-row { display: grid; grid-template-columns: 43px 1fr auto; align-items: center; gap: 10px; padding: 15px 0; border-top: 1px solid var(--line); font-size: .85rem; }
        .tour-row time { font-size: .75rem; color: var(--muted); }
        .tour-row small { display: block; color: var(--muted); margin-top: 3px; }
        .preview-foot { border-radius: 9px; background: #edf4ff; padding: 12px; font-size: .8rem; margin-top: 10px; color: #174fa5; }
        .module-strip { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 23px 0; color: var(--muted); font-size: .85rem; font-weight: 600; }
        .section { padding-top: 82px; }
        .section-heading { max-width: 700px; margin-bottom: 28px; }
        .section-heading h2, .steps h2 { font-size: clamp(1.9rem, 3vw, 2.6rem); line-height: 1.15; margin-bottom: 14px; }
        .section-heading p { color: var(--muted); }
        .feature-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        .feature-card { padding: 26px; border: 1px solid var(--line); border-radius: 16px; background: white; }
        .feature-number { display: inline-grid; place-items: center; width: 38px; height: 38px; background: #edf4ff; color: var(--blue); border-radius: 11px; font-weight: 700; font-size: .8rem; margin-bottom: 18px; }
        .feature-card h3 { font-size: 1.15rem; margin: 0 0 10px; letter-spacing: -.02em; }
        .feature-card p { color: var(--muted); font-size: .92rem; margin: 0 0 16px; }
        .feature-card ul, .checklist { padding: 0; list-style: none; margin: 0; display: grid; gap: 9px; }
        .feature-card li { font-size: .84rem; color: #405675; padding-left: 17px; position: relative; }
        .feature-card li::before { content: '·'; position: absolute; left: 0; color: var(--blue); font-weight: 700; }
        .workflow { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-top: 30px; }
        .workflow article { border-top: 2px solid #86b2f7; padding-top: 20px; }
        .workflow h3 { margin: 16px 0 8px; }
        .workflow p { color: var(--muted); font-size: .92rem; }
        .field-section { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; padding: 42px; margin-top: 80px; border-radius: 24px; background: #eaf2ff; border: 1px solid #d4e4fc; }
        .field-section h2 { font-size: 2.2rem; line-height: 1.15; }
        .field-section p { color: var(--muted); }
        .checklist li { padding: 13px 0; border-bottom: 1px solid #cbdcf5; }
        .checklist li::before { content: '✓'; color: var(--blue); margin-right: 12px; }
        .steps { margin-top: 80px; }
        .faq { max-width: 840px; margin: 0 auto; }
        details { border-bottom: 1px solid var(--line); padding: 20px 0; }
        summary { cursor: pointer; font-weight: 600; }
        details p { color: var(--muted); margin-bottom: 0; }
        .final-cta { margin-top: 72px; padding: 42px; border-radius: 20px; background: #102e60; color: white; display: flex; align-items: center; justify-content: space-between; gap: 28px; }
        .final-cta h2 { font-size: 2rem; }
        .final-cta p { color: #c3d8fa; margin-bottom: 0; }
        .final-cta .btn { background: white; color: #174fa5; flex-shrink: 0; }
        .site-footer { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px; border-top: 1px solid var(--line); padding-top: 24px; }
        .site-footer a { margin-left: 18px; }
        @media (max-width: 1000px) { .hero { gap: 28px; } .nav-link { display: none; } .feature-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 800px) { .hero { padding-top: 35px; } .preview { transform: none; } .workflow { grid-template-columns: repeat(2, 1fr); } .field-section { grid-template-columns: 1fr; gap: 20px; padding: 28px; } .final-cta { flex-direction: column; align-items: flex-start; } }
        @media (max-width: 520px) { .wrap { width: calc(100% - 32px); } header.site { flex-wrap: wrap; } header.site nav { width: 100%; justify-content: space-between; } .feature-grid, .workflow { grid-template-columns: 1fr; } .section { padding-top: 56px; } .hero h1 { font-size: 2.7rem; } .preview-body { padding: 16px; } .tour-row { grid-template-columns: 36px 1fr; } .tour-row > .pill { grid-column: 2; justify-self: start; } .field-section, .final-cta { padding: 24px; } .suffix { max-width: 52%; overflow-wrap: anywhere; white-space: normal; } }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="wrap">
        <header class="site">
            <a class="brand" href="{{ route('home') }}"><span class="mark">S</span> Shatya Info</a>
            <nav aria-label="Main navigation">
                <a class="nav-link" href="{{ route('home') }}#features">Features</a>
                <a class="nav-link" href="{{ route('home') }}#workflow">How it works</a>
                <a class="nav-link" href="{{ route('home') }}#field-app">Field app</a>
                <a class="btn btn-ghost" href="{{ route('company.login') }}">Sign in</a>
                <a class="btn btn-primary" href="{{ route('signup.create') }}">Start a company</a>
            </nav>
        </header>
        <main id="main-content">
            @yield('content')
        </main>
        <footer class="site-footer"><span>Shatya Info · Connected teams. Better field operations.</span><span><a href="{{ route('home') }}#features">All features</a><a href="{{ route('company.login') }}">Company sign in</a></span></footer>
    </div>
    @stack('scripts')
</body>
</html>

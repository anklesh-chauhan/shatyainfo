<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Shatya Info')</title>
    @stack('head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    <style>
        :root {
            --paper: #f4f0e6;
            --ink: #172033;
            --muted: #5d675f;
            --line: #e3d9c6;
            --card: #fffdf8;
            --green: #0d6b4c;
            --green-dark: #084833;
            --amber: #b7791f;
            --danger: #9f2d2d;
            --danger-bg: #fdecec;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            background:
                radial-gradient(900px 420px at 100% -10%, rgba(183, 121, 31, .16), transparent 60%),
                radial-gradient(700px 380px at -10% 0%, rgba(13, 107, 76, .12), transparent 55%),
                var(--paper);
            font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            line-height: 1.5;
        }
        a { color: inherit; }
        .wrap { width: min(1080px, calc(100% - 32px)); margin: 0 auto; }
        header.site {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 0 8px;
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
        .btn-primary { background: var(--green); color: white; }
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
        input:focus { outline: 2px solid rgba(13, 107, 76, .35); border-color: var(--green); }
        .hint, .error { font-weight: 500; font-size: .82rem; }
        .hint { color: var(--muted); }
        .error { color: var(--danger); }
        .alert { background: var(--danger-bg); color: var(--danger); border-radius: 12px; padding: 10px 12px; }
        .slug-row { display: flex; align-items: center; gap: 0; }
        .slug-row input { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .suffix {
            border: 1px solid var(--line); border-left: 0; background: #f7f3ea;
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
    </style>
</head>
<body>
    <div class="wrap">
        <header class="site">
            <a class="brand" href="{{ route('home') }}"><span class="mark">S</span> Shatya Info</a>
            <nav>
                <a class="btn btn-ghost" href="{{ route('company.login') }}">Sign in</a>
                <a class="btn btn-primary" href="{{ route('signup.create') }}">Start a company</a>
            </nav>
        </header>
        <main>
            @yield('content')
        </main>
        <footer>Shatya Info · each company keeps its own database</footer>
    </div>
    @stack('scripts')
</body>
</html>

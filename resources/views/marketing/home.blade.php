@extends('marketing.layout')

@section('title', 'Shatya Info')

@section('content')
    <section class="hero">
        <div>
            <p class="kicker">Pharma field force</p>
            <h1>Your company. Its own database.</h1>
            <p class="lede">Shatya Info runs visits, samples, orders, attendance, and approvals. Register a company and we create a private workspace at your own address.</p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('signup.create') }}">Create your company</a>
                <a class="btn btn-line" href="{{ route('company.login') }}">Open an existing company</a>
            </div>
        </div>
        <aside class="panel route-card" aria-label="A day in the field">
            <p class="kicker">Today’s tour</p>
            <div class="stop">
                <span class="dot"></span>
                <div><strong>Clinic visit</strong><div class="hint">Doctor call, samples issued, next follow-up</div></div>
            </div>
            <div class="stop">
                <span class="dot amber"></span>
                <div><strong>Order booked</strong><div class="hint">Stockist order waiting on approval</div></div>
            </div>
            <div class="stop">
                <span class="dot"></span>
                <div><strong>Day closed</strong><div class="hint">Attendance and expense submitted</div></div>
            </div>
        </aside>
    </section>

    <section class="grid">
        <article class="panel card">
            <h3>Field visits</h3>
            <p>Plan the day’s tour, record calls, and keep the pre-call notes with the visit.</p>
        </article>
        <article class="panel card">
            <h3>Samples and orders</h3>
            <p>Track doctor samples and sales documents without mixing them with another company.</p>
        </article>
        <article class="panel card">
            <h3>People and approvals</h3>
            <p>Punch in, leave, and approvals stay inside the company that owns them.</p>
        </article>
    </section>

    <section class="steps">
        <div>
            <h2>Set up in one form</h2>
            <p class="lede">You get the owner login. The database is created after you submit, so the page does not sit and wait.</p>
        </div>
        <ol class="clean">
            <li><span class="num">1</span><div><strong>Name the company</strong><div class="hint">Choose an address like <em>acme.{{ $baseDomain }}</em></div></div></li>
            <li><span class="num">2</span><div><strong>We provision it</strong><div class="hint">A separate database, a database user, and your owner account.</div></div></li>
            <li><span class="num">3</span><div><strong>Sign in there</strong><div class="hint">The field app uses that same company address.</div></div></li>
        </ol>
    </section>
@endsection

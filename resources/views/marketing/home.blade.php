@extends('marketing.layout')

@section('title', 'Shatya Info · Pharma field operations, thoughtfully connected')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/marketing-home.css') }}">
@endpush

@section('content')
<div class="home-page">
    <section class="home-hero" aria-label="Introducing Shatya Info">
        <div class="hero-slider" data-hero-slider aria-roledescription="carousel" aria-label="What Shatya Info helps you do">
            <div class="hero-slides" aria-live="off">
                <article class="hero-slide is-active" data-slide aria-roledescription="slide" aria-label="1 of 3">
                    <div class="hero-copy">
                        <p class="eyebrow">Pharma field operations</p>
                        <h1>Start your company. Get to work.</h1>
                        <p>Create your workspace yourself, then bring field visits, sales, stock, and people into one place. With your data ready, you can often get set up in about 30 minutes. Try every feature free for 30 days from the time your workspace is ready.</p>
                        <div class="hero-actions"><a class="btn btn-primary" href="{{ route('signup.create') }}">Start your free trial <span aria-hidden="true">↗</span></a><a class="text-link" href="#how-it-works">See how it works <span aria-hidden="true">→</span></a></div>
                    </div>
                    <div class="hero-art" aria-label="Illustration of a representative's daily tour">
                        <div class="art-caption">01 / THE FIELD DAY</div>
                        <div class="product-window">
                            <div class="window-top"><span class="window-brand">S <b>Shatya Info</b></span><span>Today’s tour ⌄</span></div>
                            <div class="window-content"><span class="ui-label">MONDAY · FIELD PLAN</span><h2>Good morning, Priya.</h2><p>Here’s what your day looks like.</p>
                                <div class="day-progress"><span>Today’s calls</span><strong>04 planned</strong><i></i></div>
                                <div class="visit-line"><span class="visit-time">09:30</span><span><b>Dr. A. Mehta</b><small>Clinic visit · Discussion & samples</small></span><span class="visit-status">Next</span></div>
                                <div class="visit-line"><span class="visit-time">11:45</span><span><b>Dr. N. Shah</b><small>Follow-up · Product update</small></span><span class="visit-arrow">↗</span></div>
                                <div class="visit-line"><span class="visit-time">15:00</span><span><b>City Medical</b><small>Customer visit · Order follow-up</small></span><span class="visit-arrow">↗</span></div>
                            </div>
                        </div>
                        <p class="art-disclaimer">Illustrative interface · No live company data</p>
                    </div>
                </article>
                <article class="hero-slide" data-slide aria-roledescription="slide" aria-label="2 of 3" aria-hidden="true" inert>
                    <div class="hero-copy">
                        <p class="eyebrow">Doctor relationships</p>
                        <h1>Keep the conversation moving.</h1>
                        <p>Give each visit its context. Record discussion notes, samples, and follow-ups so the next call starts where the last one ended.</p>
                        <div class="hero-actions"><a class="btn btn-primary" href="{{ route('signup.create') }}">Create your company <span aria-hidden="true">↗</span></a><a class="text-link" href="#field-work">Explore field work <span aria-hidden="true">→</span></a></div>
                    </div>
                    <div class="hero-art" aria-label="Illustration of a doctor visit record">
                        <div class="art-caption">02 / THE RELATIONSHIP</div>
                        <div class="product-window">
                            <div class="window-top"><span class="window-brand">S <b>Shatya Info</b></span><span>Visit record ⌄</span></div>
                            <div class="window-content"><span class="ui-label">ACCOUNT / DOCTOR</span><h2>Dr. A. Mehta</h2><p>Clinic visit · Today at 09:30</p>
                                <div class="record-grid"><div><small>VISIT PURPOSE</small><b>Product discussion</b></div><div><small>STATUS</small><b>Completed</b></div></div>
                                <div class="record-note"><small>CONVERSATION</small><p>Discussed the current range and noted a request for a follow-up visit.</p></div>
                                <div class="record-next"><span>↗</span><div><small>NEXT STEP</small><b>Schedule a follow-up</b></div></div>
                            </div>
                        </div>
                        <p class="art-disclaimer">Illustrative interface · No live company data</p>
                    </div>
                </article>
                <article class="hero-slide" data-slide aria-roledescription="slide" aria-label="3 of 3" aria-hidden="true" inert>
                    <div class="hero-copy">
                        <p class="eyebrow">Management visibility</p>
                        <h1>See the work behind the numbers.</h1>
                        <p>Bring field activity, daily reports, approvals, and sales into the same workspace. Your team can act on the details while they are still useful.</p>
                        <div class="hero-actions"><a class="btn btn-primary" href="{{ route('signup.create') }}">Create your company <span aria-hidden="true">↗</span></a><a class="text-link" href="#operations">Explore operations <span aria-hidden="true">→</span></a></div>
                    </div>
                    <div class="hero-art" aria-label="Illustration of a management review">
                        <div class="art-caption">03 / THE BIGGER PICTURE</div>
                        <div class="product-window">
                            <div class="window-top"><span class="window-brand">S <b>Shatya Info</b></span><span>Team overview ⌄</span></div>
                            <div class="window-content"><span class="ui-label">FIELD OPERATIONS</span><h2>Review the day.</h2><p>Follow the activity that needs your attention.</p>
                                <div class="report-list"><div><span class="report-icon">✓</span><span><b>Daily call reports</b><small>Visit activity, in one place</small></span><span>View ↗</span></div><div><span class="report-icon">↗</span><span><b>Approvals</b><small>Keep decisions moving</small></span><span>View ↗</span></div><div><span class="report-icon">▤</span><span><b>Sales documents</b><small>From quotation to invoice</small></span><span>View ↗</span></div></div>
                            </div>
                        </div>
                        <p class="art-disclaimer">Illustrative interface · No live company data</p>
                    </div>
                </article>
            </div>
            <div class="slider-controls"><div class="slide-picker" aria-label="Choose a slide"><button type="button" class="slide-dot is-active" data-slide-to="0" aria-label="Show field day slide" aria-current="true"><span>01</span><i></i></button><button type="button" class="slide-dot" data-slide-to="1" aria-label="Show doctor relationships slide"><span>02</span><i></i></button><button type="button" class="slide-dot" data-slide-to="2" aria-label="Show management visibility slide"><span>03</span><i></i></button></div><div class="slide-arrows"><button type="button" data-slide-prev aria-label="Previous slide">←</button><button type="button" data-slide-next aria-label="Next slide">→</button></div></div>
        </div>
    </section>

    <div class="home-index" aria-label="Product areas"><span>Made for the way pharma teams work</span><a href="#field-work">Field work <sup>01</sup></a><a href="#operations">Business operations <sup>02</sup></a><a href="#platform">Your workspace <sup>03</sup></a></div>

    <section class="home-section field-story" id="field-work" aria-labelledby="field-work-title">
        <div class="section-intro"><p class="eyebrow">01 / FIELD WORK</p><h2 id="field-work-title">From the first call to the daily report.</h2><p>Field work is full of small decisions. Give your representatives a useful plan, a place to record each visit, and a clear way to finish the day.</p></div>
        <div class="story-list"><article><span>01</span><div><h3>Plan the route</h3><p>Monthly tour plans, daily priorities, territories, and pre-call context.</p></div></article><article><span>02</span><div><h3>Make the visit count</h3><p>Doctor and customer records, check-in, visit notes, photos, and samples.</p></div></article><article><span>03</span><div><h3>Close the day properly</h3><p>Daily call reports, travel, expenses, and follow-up actions.</p></div></article></div>
    </section>

    <section class="home-section operations-story" id="operations" aria-labelledby="operations-title"><div class="operations-lead"><p class="eyebrow">02 / BUSINESS OPERATIONS</p><h2 id="operations-title">Field activity is only part of the picture.</h2><p>Keep the commercial and people side of the business close to the work happening outside the office.</p></div><div class="operations-grid"><article><span class="operation-glyph" aria-hidden="true">↗</span><h3>Sales & CRM</h3><p>Follow leads and deals through quotations, orders, and invoices.</p></article><article><span class="operation-glyph" aria-hidden="true">▤</span><h3>Samples & stock</h3><p>Track requests, issues, inventory movements, and purchasing.</p></article><article><span class="operation-glyph" aria-hidden="true">◎</span><h3>People & approvals</h3><p>Manage attendance, leave, team access, and decisions that need review.</p></article></div></section>

    <section class="home-section platform-story" id="platform" aria-labelledby="platform-title"><div class="platform-copy"><p class="eyebrow">03 / YOUR WORKSPACE</p><h2 id="platform-title">One company address. A shared view of the work.</h2><p>Set up a dedicated company workspace and bring your team into it. Managers use the web workspace; representatives can use the field app with the same company address.</p><a class="text-link" href="{{ route('company.login') }}">Open an existing company <span aria-hidden="true">→</span></a></div><div class="platform-detail"><div><span>01</span><p>Company workspace with its own address and separate database</p></div><div><span>02</span><p>Roles and permissions for the people in your team</p></div><div><span>03</span><p>Reports and exports across field, sales, samples, and attendance</p></div></div></section>

    <section class="home-section toolkit" id="features" aria-labelledby="toolkit-title">
        <div class="toolkit-intro">
            <div><p class="eyebrow">THE COMPLETE TOOLKIT</p><h2 id="toolkit-title">The details, when you need them.</h2></div>
            <p>Explore the tools behind the field day, the commercial process, and the people who keep everything moving. Select a module to see what it includes.</p>
        </div>
        @foreach ([
            ['Field & relationships', 'Plan calls, build context, and keep daily field records in order.', [
                ['Tour planning & field visits', 'Give every field day a clear direction, with context before each call.', ['Monthly tour plans and today’s tour', 'Doctor calls, visit purposes, and pre-call briefings', 'Visit check-in, check-out, photos, and location', 'Territories, regions, patches, and visit preferences']],
                ['Doctors & customer relationships', 'Keep the people you visit and their conversations connected.', ['Doctor and customer account records', 'Contacts and multiple addresses', 'Follow-up priorities, statuses, and outcomes', 'Visit history and doctor sample ledger']],
                ['Daily call reports & travel', 'Turn field activity into a record managers can review.', ['Daily call reports (DCRs) and visit records', 'Travel segments and transport modes', 'Expense entries, types, and configurations', 'Standard fare charts and expense summaries']],
                ['Samples & SGIP', 'Track sample requests, distribution, and the limits that guide your team.', ['Sample requests and sample issues', 'SGIP distributions and configured limits', 'Doctor-wise sample tracking', 'SGIP compliance reporting']],
            ]],
            ['Sales & stock', 'Follow opportunities, commercial documents, and product movement.', [
                ['Sales CRM & campaigns', 'Follow an opportunity through the sales pipeline.', ['Leads, activities, sources, and custom fields', 'Deals and pipeline visibility', 'Follow-up tracking and overdue reports', 'Marketing campaigns and promotional schemes']],
                ['Sales documents', 'Keep commercial documents and customer terms together.', ['Quotations, sales orders, and invoices', 'Delivery challans and document PDF downloads', 'Customer prices, taxes, and payment terms', 'Shipping methods and document preferences']],
                ['Purchasing & inventory', 'Connect incoming goods, stock changes, and sample movements.', ['Purchase orders and goods receipt notes', 'Stock balances and inventory movements', 'Inventory adjustments and stock transfers', 'Items, brands, categories, packaging, and units']],
            ]],
            ['People & oversight', 'Support the team and keep decisions and reporting in view.', [
                ['Attendance & employee management', 'Bring team structure and daily attendance into one place.', ['Punch-in and daily attendance records', 'Employees, departments, positions, and grades', 'Shifts, holidays, and weekly offs', 'Attendance registers and punch-versus-tour reports']],
                ['Leave management', 'Manage time away with balances, rules, and approvals.', ['Leave applications, types, and rules', 'Leave balances, adjustments, and ledger', 'Encashments and lapse records', 'Payroll leave snapshots']],
                ['Approvals & access control', 'Give each team member the right access and keep decisions moving.', ['Approval flows and approval settings', 'My Approvals and bulk approval actions', 'Approval delegation', 'Users, roles, and permissions']],
                ['Reports & exports', 'Review field execution, commercial progress, and workforce activity.', ['Visit coverage, DCR, and expense reports', 'Lead, deal, and overdue follow-up reports', 'Sales document and stock movement registers', 'Sample, SGIP, attendance, and leave exports']],
                ['Sales Assistant & administration', 'Support planning and configure the workspace around your company.', ['Daily priorities and suggested route order', 'Monthly tour plan drafts and pre-call briefings', 'Voice notes, sample suggestions, and follow-up scripts', 'Suggested next actions for leads and deals', 'Company onboarding, branding, and data imports', 'Organization setup, number series, and integration settings']],
            ]],
        ] as [$groupTitle, $groupDescription, $modules])
            <div class="toolkit-group">
                <div class="toolkit-group-heading"><span>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }} / PRODUCT AREA</span><h3>{{ $groupTitle }}</h3><p>{{ $groupDescription }}</p></div>
                <div class="toolkit-items">
                    @foreach ($modules as [$moduleTitle, $moduleDescription, $tools])
                        <details class="toolkit-item" @if ($loop->first) open @endif>
                            <summary><span class="toolkit-item-title"><strong>{{ $moduleTitle }}</strong><span>{{ $moduleDescription }}</span></span><span class="toolkit-toggle" aria-hidden="true"></span></summary>
                            <ul>@foreach ($tools as $tool)<li>{{ $tool }}</li>@endforeach</ul>
                        </details>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    <section class="home-section easy-start" id="how-it-works" aria-labelledby="start-title">
        <div><p class="eyebrow">START ON YOUR OWN</p><h2 id="start-title">From signup to first field day.</h2><p>No sales call is required to open your company workspace. You choose the address, verify your email, and follow the setup prompts. Have your team and company data ready? Initial setup can often be finished in about 30 minutes.</p><a class="btn btn-primary" href="{{ route('signup.create') }}">Create your company <span aria-hidden="true">↗</span></a></div>
        <ol><li><span>01</span><strong>Create your workspace</strong><small>Choose a company address like acme.{{ $baseDomain }}.</small></li><li><span>02</span><strong>Verify and sign in</strong><small>We prepare your private workspace after email verification.</small></li><li><span>03</span><strong>Add your people and data</strong><small>Use in-app setup and imports to prepare the first field day.</small></li><li><span>04</span><strong>Get help when needed</strong><small>Use the <a href="{{ route('help.index') }}">short guides</a> first, or <a href="{{ route('support.create') }}">contact support</a>.</small></li></ol>
    </section>

    <section class="home-section price-story" id="pricing" aria-labelledby="pricing-title">
        <div><p class="eyebrow">STRAIGHTFORWARD PRICING</p><h2 id="pricing-title">One price. The whole application.</h2><p>All modules are included for every company. Start with the 10-license minimum and add users as your team grows.</p></div>
        <div class="price-card"><div><strong>₹59</strong><span>per user license / month after your free trial</span></div><p><b>30 days free</b> · Then minimum 10 licenses · <b>₹590/month</b></p><ul><li>All features and modules included during the trial</li><li>Trial starts when your workspace is ready; no card required</li><li>Pay each upcoming month with Razorpay after the trial</li><li>Cancel during the trial and owe nothing</li><li>Prices include applicable tax</li></ul><a class="btn btn-primary" href="{{ route('signup.create') }}">Start your free trial</a><p><a href="{{ route('legal.show', 'trial-terms') }}">Read the trial terms</a></p></div>
    </section>

    <section class="home-section demo-story" id="demo" aria-labelledby="demo-title"><div><p class="eyebrow">PRODUCT WALKTHROUGH</p><h2 id="demo-title">See how a field day fits together.</h2><p>A short walkthrough video is being prepared. You can explore the <a href="#features">complete toolkit</a> now or create your workspace and try the setup yourself.</p></div>@if($demoVideoUrl)<a class="btn btn-line" href="{{ $demoVideoUrl }}" target="_blank" rel="noopener noreferrer">Watch the short demo ↗</a>@else<span class="demo-note">Short demo video coming soon</span>@endif</section>

    <section class="home-section home-faq" aria-labelledby="faq-title"><p class="eyebrow">COMMON QUESTIONS</p><h2 id="faq-title">Before you begin.</h2><details><summary>Do I need to book a sales call?</summary><p>No. Create your company, verify your email and follow the setup prompts yourself.</p></details><details><summary>When is the first payment due?</summary><p>Your first 30 days are free, starting when your workspace is ready. If you continue, the first paid month starts on day 31. The owner pays through Razorpay; there is no automatic recurring charge.</p></details><details><summary>Can I cancel any time?</summary><p>Yes. Cancel from Billing during the trial and owe nothing. After the trial, any paid month already started remains payable; no later monthly bills are created.</p></details><details><summary>Are any features restricted by plan?</summary><p>No. The full application is included during the trial and after it. The price changes only with the number of user licenses, with a 10-license minimum.</p></details><details><summary>Can I get help?</summary><p>Yes. Start with our <a href="{{ route('help.index') }}">setup guides</a>, then <a href="{{ route('support.create') }}">contact support</a> whenever you need a person.</p></details></section>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/marketing-home.js') }}" defer></script>
@endpush

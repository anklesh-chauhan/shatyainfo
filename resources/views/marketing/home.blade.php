@extends('marketing.layout')

@section('title', 'Shatya Info · Pharma field operations')
@section('description', 'Plan pharma visits, record calls and samples, and manage sales, stock, and teams. All modules included. Try free for 30 days.')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/marketing-home.css') }}?v={{ hash_file('sha256', public_path('css/marketing-home.css')) }}">
@endpush

@section('content')
<div class="home-page">
    <section class="home-hero" aria-label="Introducing Shatya Info">
        <div class="hero-slider" data-hero-slider aria-roledescription="carousel" aria-label="What Shatya Info helps you do">
            <div class="hero-slides" aria-live="off">
                <article class="hero-slide is-active" data-slide aria-roledescription="slide" aria-label="1 of 3">
                    <div class="hero-copy">
                        <p class="eyebrow">Pharma field operations</p>
                        <h1>Your field day, in focus.</h1>
                        <p>Plan visits, record calls, and review daily reports in one workspace. Try every module free for 30 days once your workspace is ready.</p>
                        <div class="hero-actions"><a class="btn btn-primary" href="{{ route('signup.create') }}">Start free trial <span aria-hidden="true">↗</span></a><a class="text-link" href="#how-it-works">How it works <span aria-hidden="true">→</span></a></div>
                    </div>
                    <div class="hero-art" aria-label="Illustration of a representative's daily tour">
                        <div class="art-caption">01 / THE FIELD DAY</div>
                        <div class="product-window">
                            <div class="window-top"><span class="window-brand"><img src="{{ asset('shatya-info-logo-transparent.png') }}" alt="" width="22" height="22"> <b>Shatya Info</b></span><span>Today’s tour ⌄</span></div>
                            <div class="window-content"><span class="ui-label">MONDAY · FIELD PLAN</span><h2>Morning, Priya.</h2><p>Your day at a glance.</p>
                                <div class="day-progress"><span>Today’s calls</span><strong>04 planned</strong><i></i></div>
                                <div class="visit-line"><span class="visit-time">09:30</span><span><b>Dr. A. Mehta</b><small>Discussion & samples</small></span><span class="visit-status">Next</span></div>
                                <div class="visit-line"><span class="visit-time">11:45</span><span><b>Dr. N. Shah</b><small>Follow-up · Product update</small></span><span class="visit-arrow">↗</span></div>
                                <div class="visit-line"><span class="visit-time">15:00</span><span><b>City Medical</b><small>Order follow-up</small></span><span class="visit-arrow">↗</span></div>
                            </div>
                        </div>
                        <p class="art-disclaimer">Illustration · Sample data</p>
                    </div>
                </article>
                <article class="hero-slide" data-slide aria-roledescription="slide" aria-label="2 of 3" aria-hidden="true" inert>
                    <div class="hero-copy">
                        <p class="eyebrow">Doctor relationships</p>
                        <h1>Start each call informed.</h1>
                        <p>Review past visits, samples, and follow-ups in each doctor’s record before the next call.</p>
                        <div class="hero-actions"><a class="btn btn-primary" href="{{ route('signup.create') }}">Start free trial <span aria-hidden="true">↗</span></a><a class="text-link" href="#field-work">Field work <span aria-hidden="true">→</span></a></div>
                    </div>
                    <div class="hero-art" aria-label="Illustration of a doctor visit record">
                        <div class="art-caption">02 / THE RELATIONSHIP</div>
                        <div class="product-window">
                            <div class="window-top"><span class="window-brand"><img src="{{ asset('shatya-info-logo-transparent.png') }}" alt="" width="22" height="22"> <b>Shatya Info</b></span><span>Visit record ⌄</span></div>
                            <div class="window-content"><span class="ui-label">ACCOUNT / DOCTOR</span><h2>Dr. A. Mehta</h2><p>Clinic visit · Today at 09:30</p>
                                <div class="record-grid"><div><small>VISIT PURPOSE</small><b>Product discussion</b></div><div><small>STATUS</small><b>Completed</b></div></div>
                                <div class="record-note"><small>CONVERSATION</small><p>Discussed products. Follow-up requested.</p></div>
                                <div class="record-next"><span>↗</span><div><small>NEXT STEP</small><b>Schedule a follow-up</b></div></div>
                            </div>
                        </div>
                        <p class="art-disclaimer">Illustration · Sample data</p>
                    </div>
                </article>
                <article class="hero-slide" data-slide aria-roledescription="slide" aria-label="3 of 3" aria-hidden="true" inert>
                    <div class="hero-copy">
                        <p class="eyebrow">Management visibility</p>
                        <h1>Stay ahead of the day.</h1>
                        <p>Review visits, daily reports, approvals, and sales online. Follow up while details are fresh.</p>
                        <div class="hero-actions"><a class="btn btn-primary" href="{{ route('signup.create') }}">Start free trial <span aria-hidden="true">↗</span></a><a class="text-link" href="#operations">Operations <span aria-hidden="true">→</span></a></div>
                    </div>
                    <div class="hero-art" aria-label="Illustration of a management review">
                        <div class="art-caption">03 / THE BIGGER PICTURE</div>
                        <div class="product-window">
                            <div class="window-top"><span class="window-brand"><img src="{{ asset('shatya-info-logo-transparent.png') }}" alt="" width="22" height="22"> <b>Shatya Info</b></span><span>Team overview ⌄</span></div>
                            <div class="window-content"><span class="ui-label">FIELD OPERATIONS</span><h2>Review the day.</h2><p>See what needs attention.</p>
                                <div class="report-list"><div><span class="report-icon">✓</span><span><b>Daily call reports</b><small>Visits and follow-ups</small></span><span>View ↗</span></div><div><span class="report-icon">↗</span><span><b>Approvals</b><small>Requests awaiting review</small></span><span>View ↗</span></div><div><span class="report-icon">▤</span><span><b>Sales documents</b><small>Quotations and invoices</small></span><span>View ↗</span></div></div>
                            </div>
                        </div>
                        <p class="art-disclaimer">Illustration · Sample data</p>
                    </div>
                </article>
            </div>
            <div class="slider-controls"><div class="slide-picker" aria-label="Choose a slide"><button type="button" class="slide-dot is-active" data-slide-to="0" aria-label="Show field day slide" aria-current="true"><span>01</span><i></i></button><button type="button" class="slide-dot" data-slide-to="1" aria-label="Show doctor relationships slide"><span>02</span><i></i></button><button type="button" class="slide-dot" data-slide-to="2" aria-label="Show management visibility slide"><span>03</span><i></i></button></div><div class="slide-arrows"><button type="button" data-slide-prev aria-label="Previous slide">←</button><button type="button" data-slide-next aria-label="Next slide">→</button></div></div>
        </div>
    </section>

    <div class="home-index" aria-label="Product areas"><span>For pharma teams</span><a href="#field-work">Field work <sup>01</sup></a><a href="#operations">Operations <sup>02</sup></a><a href="#platform">Workspace <sup>03</sup></a></div>

    <section class="home-section latest-features" id="whats-new" aria-labelledby="latest-features-title">
        <div class="toolkit-intro">
            <div><p class="eyebrow">WHAT’S NEW</p><h2 id="latest-features-title">Better calls. Clearer decisions.</h2></div>
            <p>Connect product discussions, market insights, sales, and approvals.</p>
        </div>
        <div class="latest-feature-grid">
            <article>
                <p class="feature-area">Doctor calls</p>
                <h3>E-detailing library</h3>
                <p>Share product PDFs and images. Track materials presented during visits.</p>
                <a href="#module-e-detailing-library">Explore e-detailing</a>
            </article>
            <article>
                <p class="feature-area">Market observations</p>
                <h3>RCPA observations</h3>
                <p>Record chemist stock, estimated offtake, and competitors. Clearly identify field estimates.</p>
                <a href="#module-rcpa-observations">Explore RCPA</a>
            </article>
            <article>
                <p class="feature-area">Stockist sales</p>
                <h3>Secondary sales reconciliation</h3>
                <p>Import statements, review stock variances, and approve sales with traceable corrections.</p>
                <a href="#module-secondary-sales-statements">Explore secondary sales</a>
            </article>
            <article>
                <p class="feature-area">Sales performance</p>
                <h3>Territory sales targets</h3>
                <p>Set value and quantity targets. Compare approved secondary sales by month, quarter, or year.</p>
                <a href="#module-territory-targets-achievement">Explore territory targets</a>
            </article>
            <article>
                <p class="feature-area">Account coverage</p>
                <h3>Account reach &amp; frequency</h3>
                <p>Compare calls with monthly targets. Review coverage gaps, division profiles, and account potential.</p>
                <a href="#module-account-reach-frequency">Explore account coverage</a>
            </article>
            <article>
                <p class="feature-area">Team decisions</p>
                <h3>Approval inbox &amp; alerts</h3>
                <p>Review requests in My Approvals, act in bulk, and receive decision alerts.</p>
                <a href="#module-approvals-access-control">Explore approvals</a>
            </article>
        </div>
        <p class="latest-features-note">Access depends on your role. Push alerts need company setup and device permission.</p>
    </section>

    <section class="home-section field-story" id="field-work" aria-labelledby="field-work-title">
        <div class="section-intro"><p class="eyebrow">01 / FIELD WORK</p><h2 id="field-work-title">Plan. Visit. Report.</h2><p>Keep tour plans, visit notes, samples, travel, and expenses together.</p></div>
        <div class="story-list"><article><span>01</span><div><h3>Plan routes</h3><p>Tour plans, priorities, territories, and pre-call briefings.</p></div></article><article><span>02</span><div><h3>Record visits</h3><p>Account records, check-ins, notes, photos, and samples.</p></div></article><article><span>03</span><div><h3>Close the day</h3><p>Daily reports, travel, expenses, and follow-ups.</p></div></article></div>
    </section>

    <section class="home-section operations-story" id="operations" aria-labelledby="operations-title"><div class="operations-lead"><p class="eyebrow">02 / OPERATIONS</p><h2 id="operations-title">Sales, stock, and people. Connected.</h2><p>See how field visits connect to orders, samples, attendance, and approvals.</p></div><div class="operations-grid"><article><span class="operation-glyph" aria-hidden="true">↗</span><h3>Sales & CRM</h3><p>Track leads, deals, quotes, orders, and invoices.</p></article><article><span class="operation-glyph" aria-hidden="true">▤</span><h3>Samples & stock</h3><p>Track requests, issues, stock, and purchases.</p></article><article><span class="operation-glyph" aria-hidden="true">◎</span><h3>People & approvals</h3><p>Manage attendance, leave, access, and approvals.</p></article></div></section>

    <section class="home-section platform-story" id="platform" aria-labelledby="platform-title"><div class="platform-copy"><p class="eyebrow">03 / WORKSPACE</p><h2 id="platform-title">One team. One company address.</h2><p>Managers work online. Representatives use the field app. Everyone signs in with your company address.</p><a class="text-link" href="{{ route('company.login') }}">Company sign in <span aria-hidden="true">→</span></a></div><div class="platform-detail"><div><span>01</span><p>Your own address and separate database</p></div><div><span>02</span><p>Team roles and permissions</p></div><div><span>03</span><p>Field, sales, samples, and attendance reports</p></div></div></section>

    <section class="home-section toolkit" id="features" aria-labelledby="toolkit-title">
        <div class="toolkit-intro">
            <div><p class="eyebrow">PRODUCT FEATURES</p><h2 id="toolkit-title">Your team’s toolkit.</h2></div>
            <p>Explore tools for field work, sales, stock, and people.</p>
        </div>
        @foreach ([
            ['Field & relationships', 'Plan calls. Review visits.', [
                ['Tour planning & field visits', 'Prepare representatives for each visit.', ['Monthly and daily tour plans', 'Call purposes and pre-call briefings', 'Check-in/out, photos, and location', 'Territories, regions, patches, and preferences']],
                ['Doctors & customer relationships', 'Keep accounts, visits, and follow-ups together.', ['Doctor and customer records', 'Contacts and addresses', 'Follow-up priorities, status, and outcomes', 'Visit history and sample ledger']],
                ['E-detailing library', 'Present published product materials during calls.', ['Private product PDFs and images', 'Publish or withdraw team materials', 'Log presentations during active visits', 'Presentation history and visit notes']],
                ['RCPA observations', 'Log retail chemist prescription audit observations.', ['Chemist stock and estimated offtake', 'Estimated doctor prescription share', 'Competitor observations', 'Observation register and CSV with estimates labelled']],
                ['Daily call reports & travel', 'Review visits, travel, and expenses.', ['Daily call reports (DCRs) and visits', 'Travel segments and transport', 'Expenses, types, and settings', 'Fare charts and expense summaries']],
                ['Samples & SGIP', 'Track sample requests, issues, and limits.', ['Sample requests and issues', 'SGIP distributions and limits', 'Samples by doctor', 'SGIP compliance reports']],
            ]],
            ['Sales & stock', 'Track opportunities, documents, and stock.', [
                ['Sales CRM & campaigns', 'Track opportunities through your pipeline.', ['Leads, activities, sources, custom fields', 'Deals and pipeline', 'Follow-ups and overdue reports', 'Campaigns and promotional schemes']],
                ['Sales documents', 'Keep documents and customer terms together.', ['Quotes, orders, and invoices', 'Delivery challans and PDF downloads', 'Prices, taxes, and payment terms', 'Shipping and document preferences']],
                ['Purchasing & inventory', 'Connect receipts, stock, and samples.', ['Purchase orders and goods receipts', 'Stock balances and movements', 'Adjustments and stock transfers', 'Items, brands, categories, packaging, units']],
            ]],
            ['Secondary sales & performance', 'Review statements. Measure territory performance.', [
                ['Secondary sales statements', 'Review stockist sales before measuring performance.', ['CSV/Excel imports and source downloads', 'Product and unit mapping', 'Reconcile opening, purchases, sales, returns, closing stock', 'Approvals, rejections, and variance review']],
                ['Statement scans & corrections', 'Keep originals and review corrections.', ['Statement scans and PDFs', 'Configured OCR with manual review and mapping', 'Correction reasons and reviewer decisions', 'Approved statement reversals and replacements']],
                ['Territory targets & achievement', 'Compare approved sales with territory goals.', ['Monthly, quarterly, annual targets', 'Value and normalized quantity targets', 'Territory achievement dashboard', 'Dated assignments and target revisions']],
            ]],
            ['People & oversight', 'Manage people, access, and approvals.', [
                ['Attendance & employee management', 'Review punches, shifts, and employee records.', ['Punches and daily attendance', 'Employees, departments, positions, grades', 'Shifts, holidays, weekly offs', 'Attendance and punch-versus-tour reports']],
                ['Leave management', 'Track leave, balances, and approvals.', ['Leave applications, types, rules', 'Balances, adjustments, and ledger', 'Encashments and lapses', 'Payroll leave snapshots']],
                ['Approvals & access control', 'Assign access. Review requests.', ['Approval flows and settings', 'My Approvals: bulk approve/reject', 'In-app request and decision alerts', 'Browser/mobile push with setup and permission', 'Approval delegation', 'Users, roles, permissions']],
                ['Account reach & frequency', 'Track account coverage and call gaps.', ['Monthly call targets and completed calls', 'Call gaps, achievement %, reached accounts', 'Division profiles, segments, account potential', 'Month-end owners, territories, and CSV export']],
                ['Reports & exports', 'Review field, sales, and workforce activity.', ['Visit coverage, DCR, expenses', 'Leads, deals, overdue follow-ups', 'Sales and stock registers', 'Sample, SGIP, attendance, leave exports', 'Account reach and frequency']],
                ['Sales Assistant & administration', 'Get planning suggestions. Manage company settings.', ['Priorities and suggested routes', 'Tour drafts and pre-call briefings', 'Voice notes, sample suggestions, follow-up scripts', 'Suggested lead and deal actions', 'Onboarding, branding, data imports', 'Organization, number series, integrations']],
            ]],
        ] as [$groupTitle, $groupDescription, $modules])
            <div class="toolkit-group">
                <div class="toolkit-group-heading"><span>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }} / PRODUCT AREA</span><h3>{{ $groupTitle }}</h3><p>{{ $groupDescription }}</p></div>
                <div class="toolkit-items">
                    @foreach ($modules as [$moduleTitle, $moduleDescription, $tools])
                        <details class="toolkit-item" id="module-{{ \Illuminate\Support\Str::slug($moduleTitle) }}" @if ($loop->first) open @endif>
                            <summary><span class="toolkit-item-title"><strong>{{ $moduleTitle }}</strong><span>{{ $moduleDescription }}</span></span><span class="toolkit-toggle" aria-hidden="true"></span></summary>
                            <ul>@foreach ($tools as $tool)<li>{{ $tool }}</li>@endforeach</ul>
                        </details>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    <section class="home-section easy-start" id="how-it-works" aria-labelledby="start-title">
        <div><p class="eyebrow">GET STARTED</p><h2 id="start-title">Ready for your first field day.</h2><p>Choose an address, verify your email, and complete setup. No sales call or card needed.</p><a class="btn btn-primary" href="{{ route('signup.create') }}">Start free trial <span aria-hidden="true">↗</span></a></div>
        <ol><li><span>01</span><strong>Create a workspace</strong><small>Choose an address: acme.{{ $baseDomain }}.</small></li><li><span>02</span><strong>Verify and sign in</strong><small>Email verification starts your private workspace setup.</small></li><li><span>03</span><strong>Add people and data</strong><small>Follow setup prompts and import your data.</small></li><li><span>04</span><strong>Get help</strong><small>Read the <a href="{{ route('help.index') }}">guides</a> or <a href="{{ route('support.create') }}">contact support</a>.</small></li></ol>
    </section>

    <section class="home-section price-story" id="pricing" aria-labelledby="pricing-title">
        <div><p class="eyebrow">PRICING</p><h2 id="pricing-title">30 days free. Then ₹59/user/month.</h2><p>All modules included. After trial: 10-license minimum, ₹590/month.</p></div>
        <div class="price-card"><div><strong>₹59</strong><span>per user/month after trial</span></div><p><b>30 days free</b> · Then 10-license minimum · <b>₹590/month</b></p><ul><li>Every module, during and after trial</li><li>Trial starts when your workspace is ready. No card needed.</li><li>After trial, prepay monthly through Razorpay</li><li>Cancel during trial, pay nothing</li><li>Displayed bill = total payable</li></ul><a class="btn btn-primary" href="{{ route('signup.create') }}">Start free trial</a><p><a href="{{ route('legal.show', 'trial-terms') }}">Trial terms</a></p></div>
    </section>

    <section class="home-section demo-story" id="demo" aria-labelledby="demo-title"><div><p class="eyebrow">PRODUCT WALKTHROUGH</p><h2 id="demo-title">See a field day in action.</h2><p>@if($demoVideoUrl)Watch the demo. Explore the <a href="#features">features</a>. @else Explore the <a href="#features">features</a> or try them free. @endif</p></div>@if($demoVideoUrl)<a class="btn btn-line" href="{{ $demoVideoUrl }}" target="_blank" rel="noopener noreferrer">Watch demo ↗</a>@else<a class="btn btn-line" href="{{ route('signup.create') }}">Start free trial</a>@endif</section>

    <section class="home-section home-faq" aria-labelledby="faq-title"><p class="eyebrow">COMMON QUESTIONS</p><h2 id="faq-title">Before you start.</h2><details><summary>Is a sales call required?</summary><p>No. Register, verify your email, and complete setup yourself.</p></details><details><summary>When do I pay?</summary><p>Your 30-day trial starts when your workspace is ready. Then the owner pays monthly through Razorpay. No automatic recurring charges.</p></details><details><summary>Can I cancel anytime?</summary><p>Yes, from Billing. Cancel during trial and pay nothing. After trial, a started paid month remains payable. Future bills stop.</p></details><details><summary>Are all features included?</summary><p>Yes, during and after trial. Monthly pricing is per user license, with a 10-license minimum.</p></details><details><summary>Need help?</summary><p>Read the <a href="{{ route('help.index') }}">setup guides</a> or <a href="{{ route('support.create') }}">contact support</a>.</p></details></section>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/marketing-home.js') }}?v={{ hash_file('sha256', public_path('js/marketing-home.js')) }}" defer></script>
@endpush

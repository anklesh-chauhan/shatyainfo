@extends('marketing.layout')

@section('title', 'Shatya Info · Pharma field force & business management')

@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <div>
            <p class="kicker">Built for pharma teams</p>
            <h1 id="hero-title">Your field force.<br>Your business.<br><span>One connected workspace.</span></h1>
            <p class="lede">Bring doctor visits, samples, sales, and your people together. Give your field team a clear plan for the day and your managers the visibility to move business forward.</p>
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('signup.create') }}">Create your company &rarr;</a>
                <a class="btn btn-line" href="#features">Explore all features</a>
            </div>
            <p class="hero-note">Your company address · Dedicated workspace · Web and field app</p>
        </div>
        <aside class="preview" aria-label="Illustrative field workflow, not live company data">
            <div class="preview-head"><strong>Shatya Info / Field overview</strong><small>Illustration</small></div>
            <div class="preview-body">
                <div class="preview-title"><div><p class="kicker">A day, connected</p><h2>Ready for the field.</h2></div><span class="pill">Today's tour</span></div>
                <div class="preview-stats"><div><strong>01</strong><span>Plan your calls</span></div><div><strong>02</strong><span>Record visits</span></div><div><strong>03</strong><span>Submit your day</span></div></div>
                <div class="tour-row"><time>09:00</time><div><strong>Start the day</strong><small>Punch in and review your tour</small></div><span class="pill">Attendance</span></div>
                <div class="tour-row"><time>10:30</time><div><strong>Connect with a doctor</strong><small>Call notes, samples, and follow-up</small></div><span class="pill">Doctor visit</span></div>
                <div class="tour-row"><time>14:00</time><div><strong>Move a sale forward</strong><small>Customer, quotation, and order</small></div><span class="pill">Sales CRM</span></div>
                <div class="tour-row"><time>18:00</time><div><strong>Close the loop</strong><small>Daily call report and expenses</small></div><span class="pill">DCR</span></div>
                <div class="preview-foot">From the first punch-in to the final approval.</div>
            </div>
        </aside>
    </section>

    <div class="module-strip" aria-label="Product areas"><span>Field operations</span><span>Sales & CRM</span><span>Samples & inventory</span><span>People & attendance</span><span>Reports & approvals</span></div>

    <section class="section" id="features" aria-labelledby="features-title">
        <div class="section-heading"><p class="kicker">The complete toolkit</p><h2 id="features-title">Every part of your operation.<br>Working together.</h2><p>From the doctor relationship to the stock movement, keep the details in the same workspace. Explore the project's core modules and supporting tools below.</p></div>
        <div class="feature-grid">
            @foreach ([
                ['Tour planning & field visits', 'Give every field day a clear direction, with the context your team needs before each call.', ['Monthly tour plans and today’s tour', 'Doctor calls, visit purposes, and pre-call briefings', 'Visit check-in, check-out, photos, and location', 'Territories, regions, patches, and visit preferences']],
                ['Doctors & customer relationships', 'Keep the people you visit and the conversations you have connected.', ['Doctor and customer account records', 'Contacts and multiple addresses', 'Follow-up priorities, statuses, and outcomes', 'Visit history and doctor sample ledger']],
                ['Daily call reports & travel', 'Turn daily field activity into a structured record that managers can review.', ['Daily call reports (DCRs) and visit records', 'Travel segments and transport modes', 'Expense entries, types, and configurations', 'Standard fare charts and expense summaries']],
                ['Samples & SGIP', 'Track sample requests, distribution, and the limits that guide your team.', ['Sample requests and sample issues', 'SGIP distributions and configured limits', 'Doctor-wise sample tracking', 'SGIP compliance reporting']],
                ['Sales CRM & campaigns', 'Follow an opportunity from the first conversation through the sales pipeline.', ['Leads, activities, sources, and custom fields', 'Deals and pipeline visibility', 'Follow-up tracking and overdue reports', 'Marketing campaigns and promotional schemes']],
                ['Sales documents', 'Keep commercial documents and customer terms together as a sale progresses.', ['Quotations, sales orders, and invoices', 'Delivery challans and document PDF downloads', 'Customer prices, taxes, and payment terms', 'Shipping methods and document preferences']],
                ['Purchasing & inventory', 'Connect incoming goods, stock changes, and sample movements.', ['Purchase orders and goods receipt notes', 'Stock balances and inventory movements', 'Inventory adjustments and stock transfers', 'Items, brands, categories, packaging, and units']],
                ['Attendance & employee management', 'Bring your team structure and day-to-day attendance into one place.', ['Punch-in and daily attendance records', 'Employees, departments, positions, and grades', 'Shifts, holidays, and weekly offs', 'Attendance registers and punch-versus-tour reports']],
                ['Leave management', 'Manage time away with balances, rules, and a clear approval process.', ['Leave applications, types, and rules', 'Leave balances, adjustments, and ledger', 'Encashments and lapse records', 'Payroll leave snapshots']],
                ['Approvals & access control', 'Give each team member the right access and keep decisions moving.', ['Approval flows and approval settings', 'My Approvals and bulk approval actions', 'Approval delegation', 'Users, roles, and permissions']],
                ['Reports & exports', 'Review field execution, commercial progress, and workforce activity.', ['Visit coverage, DCR, and expense reports', 'Lead, deal, and overdue follow-up reports', 'Sales document and stock movement registers', 'Sample, SGIP, attendance, and leave exports']],
                ['Sales Assistant & administration', 'Support daily planning and configure the workspace around your company.', ['Daily priorities and suggested route order', 'Monthly tour plan drafts and pre-call briefings', 'Voice notes, sample suggestions, and follow-up scripts', 'Suggested next actions for leads and deals', 'Company onboarding, branding, and data imports', 'Organization setup, number series, and integration settings']],
            ] as [$title, $description, $features])
                <article class="feature-card"><span class="feature-number" aria-hidden="true">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $title }}</h3><p>{{ $description }}</p><ul>@foreach ($features as $feature)<li>{{ $feature }}</li>@endforeach</ul></article>
            @endforeach
        </div>
    </section>

    <section class="section" id="workflow" aria-labelledby="workflow-title">
        <div class="section-heading"><p class="kicker">A connected working day</p><h2 id="workflow-title">Plan. Visit. Record. Review.</h2><p>A practical workflow for your representatives, managers, and operations team.</p></div>
        <div class="workflow">
            <article><span class="num">1</span><h3>Plan with purpose</h3><p>Build the monthly tour, review daily priorities, and prepare for the next doctor call.</p></article>
            <article><span class="num">2</span><h3>Make every visit count</h3><p>Check in, capture the conversation, record samples, and schedule the next follow-up.</p></article>
            <article><span class="num">3</span><h3>Bring the day together</h3><p>Complete the DCR, add travel and expenses, and submit the records for review.</p></article>
            <article><span class="num">4</span><h3>Act on the details</h3><p>Review approvals, monitor coverage, and follow sales and inventory activity through reports.</p></article>
        </div>
    </section>

    <section class="field-section" id="field-app" aria-labelledby="field-title">
        <div><p class="kicker">At the desk. On the move.</p><h2 id="field-title">The office and field team, on the same page.</h2><p>Managers work in the web workspace. Representatives use the Shatya Info field app with the same company address, keeping their daily workflow close at hand.</p><a class="btn btn-primary" href="{{ route('company.login') }}">Open your company &rarr;</a></div>
        <ul class="checklist"><li>Attendance, punch-in, and leave</li><li>Doctors, accounts, and address management</li><li>Today's tour and monthly tour planning</li><li>Visit location, photos, and sample recording</li><li>Daily call reports and expense submission</li><li>Sales Assistant priorities and planning</li></ul>
    </section>

    <section class="steps" id="get-started" aria-labelledby="setup-title">
        <div><p class="kicker">Your company, your workspace</p><h2 id="setup-title">A clear start for<br>your whole team.</h2><p class="lede">Create a company workspace with its own address and separate database, then bring your people and business setup together.</p></div>
        <ol class="clean">
            <li><span class="num">1</span><div><strong>Create your company</strong><div class="hint">Enter your company and owner details. Choose an address like acme.{{ $baseDomain }}.</div></div></li>
            <li><span class="num">2</span><div><strong>Verify your email</strong><div class="hint">Confirm your email to begin preparing your company workspace.</div></div></li>
            <li><span class="num">3</span><div><strong>Complete your setup</strong><div class="hint">Sign in as the owner, complete onboarding, and configure your team and master data.</div></div></li>
        </ol>
    </section>

    <section class="section faq" aria-labelledby="faq-title">
        <div class="section-heading"><p class="kicker">A few useful details</p><h2 id="faq-title">Before you get started.</h2></div>
        <details><summary>Who is Shatya Info designed for?</summary><p>Pharma field representatives, sales managers, HR teams, and operations teams who need to manage doctor visits, samples, sales, attendance, and approvals together.</p></details>
        <details><summary>Does each company have its own workspace?</summary><p>Yes. Each company has its own address and separate database. Team members sign in to their company, and access within the workspace follows their assigned roles and permissions.</p></details>
        <details><summary>Can the field app use the same company?</summary><p>Yes. Enter the same company address in the field app and sign in with your company account to use the field workflows available to your role.</p></details>
        <details><summary>How do I join a company that already exists?</summary><p>Use <a href="{{ route('company.login') }}">company sign in</a> to open your existing workspace. Your company administrator manages your account and access.</p></details>
    </section>

    <section class="final-cta"><div><h2>Bring your field and business together.</h2><p>Start with your company. Build a more connected working day.</p></div><a class="btn" href="{{ route('signup.create') }}">Create your company &rarr;</a></section>
@endsection

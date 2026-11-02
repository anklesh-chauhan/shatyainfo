@extends('marketing.layout')

@section('title', 'Help and setup guides · Shatya Info')

@section('content')
<div style="max-width:850px;margin:20px auto 70px">
    <p class="kicker">HELP CENTRE</p>
    <h1 style="font-size:clamp(2.4rem,5vw,3.7rem)">Set up your company and team.</h1>
    <p class="lede">Follow the steps for registration, team setup, or billing. If you get stuck, contact support.</p>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));margin:36px 0">
        <a class="panel" href="#company-setup" style="text-decoration:none"><strong>Set up your company</strong><p>Register, verify your email, and sign in.</p></a>
        <a class="panel" href="#invite-team" style="text-decoration:none"><strong>Add your team</strong><p>Employees, access, and starting data.</p></a>
        <a class="panel" href="#billing-help" style="text-decoration:none"><strong>Billing and payment</strong><p>Trial dates, licenses, and monthly payment.</p></a>
    </div>

    <section id="company-setup" class="section"><h2>1. Set up your company</h2><ol><li>Choose a company address and create your owner account.</li><li>Verify your email with the six-digit code.</li><li>Wait for the workspace to become ready, then sign in at your company address.</li><li>Follow the setup prompts to add your company details and reference data.</li></ol><p class="hint">Setup time depends on how much company and team data you need to add.</p></section>
    <section id="invite-team" class="section"><h2>2. Add your team</h2><ol><li>Add your organization and employees in the web workspace.</li><li>Give each person a user account and the right access.</li><li>Add doctors, customers, products, and territories with the available setup and import tools.</li><li>Give field users the company address for app sign-in.</li></ol><p>You can start with the areas your team needs first. All modules are included.</p></section>
    <section id="billing-help" class="section"><h2>3. Understand your trial and monthly bill</h2><p>Your 30-day trial starts when your workspace is ready. All modules are available and no card is required. Check the trial-end date on your Billing page. Cancel before that date and owe nothing for the trial.</p><p>After the trial, the owner pays for each upcoming month through Razorpay checkout from Billing. The price is ₹59 per user license per month, with a minimum of 10 licenses (₹590 per month). There is no automatic recurring card charge.</p><p>You can cancel from Billing at any time. Any paid month already started and earlier unpaid bills remain due; later months will not be billed. Contact support if you need to resume billing.</p><p>If checkout is unavailable on Billing, contact support for payment help.</p></section>

    <div class="final-cta"><div><h2>Stuck on a step?</h2><p>Tell us what happened and include your company address if you have one.</p></div><a class="btn" href="{{ route('support.create') }}">Contact support</a></div>
</div>
@endsection

@extends('marketing.layout')

@section('title', 'Help and setup guides · Shatya Info')

@section('content')
<div style="max-width:850px;margin:20px auto 70px">
    <p class="kicker">HELP CENTRE</p>
    <h1 style="font-size:clamp(2.4rem,5vw,3.7rem)">Get your team moving.</h1>
    <p class="lede">Start with the task you are doing. These short guides cover the first steps; if you get stuck, our team is available.</p>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));margin:36px 0">
        <a class="panel" href="#company-setup" style="text-decoration:none"><strong>Set up your company</strong><p>From registration to your first sign-in.</p></a>
        <a class="panel" href="#invite-team" style="text-decoration:none"><strong>Bring in your team</strong><p>People, roles and starting data.</p></a>
        <a class="panel" href="#billing-help" style="text-decoration:none"><strong>Billing and payment</strong><p>The free trial, licenses and Razorpay.</p></a>
    </div>

    <section id="company-setup" class="section"><h2>1. Set up your company</h2><ol><li>Choose a company address and create your owner account.</li><li>Verify your email with the six-digit code.</li><li>Wait for the workspace to become ready, then sign in at your company address.</li><li>Follow the setup prompts to add your company details and reference data.</li></ol><p class="hint">With your data prepared, workspace provisioning and initial setup can often be completed in about 30 minutes. Larger imports may take longer.</p></section>
    <section id="invite-team" class="section"><h2>2. Bring in your team</h2><ol><li>Set up your organization and employees in the web workspace.</li><li>Give each person the access they need.</li><li>Add doctors, customers, products and territories using the available setup and import tools.</li><li>Ask field users to sign in with the same company address.</li></ol><p>All product areas are included. You can start with the tools your team needs today and use the others when ready.</p></section>
    <section id="billing-help" class="section"><h2>3. Understand your free trial and monthly bill</h2><p>Your 30-day free trial starts when your workspace is ready. Every feature is available and no card is required. We will email the company owner before the trial ends. Cancel during the trial and owe nothing.</p><p>After the trial, the owner pays for each upcoming month through Razorpay checkout from the Billing page. The price is ₹59 per user license per month, with a minimum of 10 licenses (₹590 per month), including applicable tax. There is no automatic recurring charge.</p><p>You can cancel any time from Billing. Any paid month already started and previous unpaid bills remain due; no later monthly bills will be created. Contact support if you need to resume billing later.</p><p>If the Billing page does not show checkout yet, please contact support while online payments are being configured.</p></section>

    <div class="final-cta"><div><h2>Need a hand?</h2><p>Tell us where you are stuck and include your company address if you have one.</p></div><a class="btn" href="{{ route('support.create') }}">Contact support</a></div>
</div>
@endsection

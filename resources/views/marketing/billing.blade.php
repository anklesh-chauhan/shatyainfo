@extends('marketing.layout')

@section('title', 'Company billing · Shatya Info')

@section('content')
<div style="max-width:880px;margin:20px auto 70px">
    <p class="kicker">COMPANY BILLING</p>
    <h1 style="font-size:clamp(2.4rem,5vw,3.5rem)">Simple monthly billing.</h1>
    <p class="lede">₹59 per user license each month, with 10 licenses included as the minimum. Your minimum is ₹590 per month. All features are available.</p>
    <p>The first bill is created after one month of workspace use. You pay each monthly bill yourself through Razorpay checkout. Prices shown include applicable tax. Cancel any time; your current month is your final bill.</p>
    @if($tenant->billing_started_at)
        <p class="panel">Workspace ready: <strong>{{ $tenant->billing_started_at->format('d M Y') }}</strong> · First monthly bill: <strong>{{ $tenant->billing_started_at->copy()->addMonthNoOverflow()->format('d M Y') }}</strong></p>
    @endif

    @if(session('billing_status')) <p class="panel" role="status">{{ session('billing_status') }}</p> @endif
    @error('checkout') <p class="alert" role="alert">{{ $message }}</p> @enderror

    <h2 style="margin-top:42px">Monthly bills</h2>
    @forelse($invoices as $invoice)
        <article class="panel" style="margin:14px 0;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap">
            <div>
                <strong>Month starting {{ $invoice->period_start->format('d M Y') }}</strong>
                <p style="margin:4px 0;color:var(--muted)">{{ $invoice->licensed_users }} licenses × ₹59 · ₹{{ number_format($invoice->amount_paise / 100) }} total · {{ $invoice->status === 'paid' ? 'Paid' : 'Payment due' }}</p>
            </div>
            @if($invoice->status !== 'paid')
                @if($checkoutConfigured)
                    @if($invoice->razorpay_order_id)
                        <button class="btn btn-primary" type="button" data-pay-invoice="{{ $invoice->id }}">Pay with Razorpay</button>
                    @else
                        <form method="post" action="{{ route('billing.order', $invoice) }}">@csrf<button class="btn btn-primary" type="submit">Pay with Razorpay</button></form>
                    @endif
                @else
                    <a href="{{ route('support.create') }}">Contact support to pay</a>
                @endif
            @endif
        </article>
    @empty
        <div class="panel"><strong>No payment due yet.</strong><p>Your first bill appears after one month of use.</p></div>
    @endforelse

    <section class="panel" style="margin:36px 0">
        <h2 style="font-size:1.35rem">Cancellation</h2>
        @if($tenant->billing_cancelled_at)
            <p>Cancelled on {{ $tenant->billing_cancelled_at->format('d M Y') }}. Your current month is your final bill; earlier unpaid bills remain due. No later monthly bills will be created.</p>
            <p class="hint">Need to resume? <a href="{{ route('support.create') }}">Contact support</a>.</p>
        @else
            <p>You can cancel any time. The month in progress remains payable, and no later monthly bills will be created.</p>
            <details><summary>Cancel future billing</summary><p>This stops future billing after your current month. Bills already due remain payable.</p><form method="post" action="{{ route('billing.cancel') }}">@csrf<button class="btn btn-line" type="submit">Confirm cancellation</button></form></details>
        @endif
    </section>

    <p class="hint">Need help with a bill? <a href="{{ route('help.index') }}#billing-help">Read the billing guide</a> or <a href="{{ route('support.create') }}">contact support</a>.</p>
</div>
@endsection

@if($checkoutConfigured && $invoices->contains(fn ($invoice) => $invoice->status !== 'paid' && $invoice->razorpay_order_id))
    @push('scripts')
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
            const billingOrders = @json($invoices->filter(fn ($invoice) => $invoice->status !== 'paid' && $invoice->razorpay_order_id)->mapWithKeys(fn ($invoice) => [$invoice->id => ['orderId' => $invoice->razorpay_order_id, 'amount' => $invoice->amount_paise, 'confirmUrl' => route('billing.confirm', $invoice)]])->all());
            const razorpayKey = @json($razorpayKey);
            const csrfToken = @json(csrf_token());

            document.querySelectorAll('[data-pay-invoice]').forEach((button) => {
                button.addEventListener('click', () => {
                    const order = billingOrders[button.dataset.payInvoice];
                    if (!order || !window.Razorpay) return;
                    new Razorpay({
                        key: razorpayKey,
                        amount: order.amount,
                        currency: 'INR',
                        name: 'Shatya Info',
                        description: 'Monthly company license bill',
                        order_id: order.orderId,
                        handler: (payment) => {
                            const form = document.createElement('form');
                            form.method = 'post';
                            form.action = order.confirmUrl;
                            for (const [name, value] of Object.entries({...payment, _token: csrfToken})) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = name;
                                input.value = value;
                                form.append(input);
                            }
                            document.body.append(form);
                            form.submit();
                        },
                    }).open();
                });
            });

            const selectedInvoice = new URLSearchParams(window.location.search).get('pay');
            if (selectedInvoice && billingOrders[selectedInvoice]) document.querySelector('[data-pay-invoice="' + selectedInvoice + '"]')?.click();
        </script>
    @endpush
@endif

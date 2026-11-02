<?php

use App\Models\BillingInvoice;
use App\Models\Tenant;
use App\Services\Billing\MonthlyBilling;
use App\Services\Billing\RazorpayCheckout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

uses(TestCase::class);

test('monthly pricing applies the ten license minimum', function (): void {
    $billing = new MonthlyBilling;

    expect($billing->amountPaise(1))->toBe(59000)
        ->and($billing->amountPaise(10))->toBe(59000)
        ->and($billing->amountPaise(11))->toBe(64900);
});

test('a new company owes nothing for the first thirty days and pays for the next month', function (): void {
    $billing = new MonthlyBilling;
    $tenant = new Tenant;
    $tenant->forceFill([
        'billing_started_at' => '2026-11-02 10:00:00',
        'billing_trial_ends_at' => '2026-12-02 10:00:00',
    ]);

    expect($billing->duePeriodStart($tenant, 1, CarbonImmutable::parse('2026-12-02 09:59:59')))->toBeNull()
        ->and($billing->duePeriodStart($tenant, 1, CarbonImmutable::parse('2026-12-02 10:00:00'))?->toDateTimeString())->toBe('2026-12-02 10:00:00')
        ->and($billing->duePeriodStart($tenant, 2, CarbonImmutable::parse('2026-12-02 10:00:00')))->toBeNull()
        ->and($billing->shouldBillPeriod(CarbonImmutable::parse('2026-12-02 10:00:00'), CarbonImmutable::parse('2026-12-01 12:00:00')))->toBeFalse();
});

test('existing companies retain billing after their first month of use', function (): void {
    $billing = new MonthlyBilling;
    $tenant = new Tenant;
    $tenant->forceFill(['billing_started_at' => '2026-11-02 10:00:00']);

    expect($billing->duePeriodStart($tenant, 1, CarbonImmutable::parse('2026-12-02 09:59:59')))->toBeNull()
        ->and($billing->duePeriodStart($tenant, 1, CarbonImmutable::parse('2026-12-02 10:00:00'))?->toDateTimeString())->toBe('2026-11-02 10:00:00');
});

test('cancellation keeps the current month bill and stops later months', function (): void {
    $billing = new MonthlyBilling;
    $cancelledAt = CarbonImmutable::parse('2026-11-15 10:00:00');

    expect($billing->shouldBillPeriod(CarbonImmutable::parse('2026-11-02'), $cancelledAt))->toBeTrue()
        ->and($billing->shouldBillPeriod(CarbonImmutable::parse('2026-12-02'), $cancelledAt))->toBeFalse()
        ->and($billing->shouldBillPeriod(CarbonImmutable::parse('2026-11-02'), null))->toBeTrue();
});

test('checkout confirmation requires a valid signature and captured matching payment', function (): void {
    config()->set('services.razorpay.key_id', 'rzp_test_example');
    config()->set('services.razorpay.key_secret', 'test-secret');

    $invoice = new BillingInvoice;
    $invoice->forceFill(['razorpay_order_id' => 'order_123', 'amount_paise' => 59000]);
    $signature = hash_hmac('sha256', 'order_123|pay_123', 'test-secret');
    $checkout = new RazorpayCheckout;

    Http::fake(['api.razorpay.com/v1/payments/pay_123' => Http::sequence()
        ->push(['status' => 'captured', 'order_id' => 'order_123', 'amount' => 59000, 'currency' => 'INR'])
        ->push(['status' => 'authorized', 'order_id' => 'order_123', 'amount' => 59000, 'currency' => 'INR'])]);

    expect($checkout->verifyCapturedPayment($invoice, 'order_123', 'pay_123', $signature))->toBeTrue()
        ->and($checkout->verifyCapturedPayment($invoice, 'order_123', 'pay_123', str_repeat('0', 64)))->toBeFalse();

    expect($checkout->verifyCapturedPayment($invoice, 'order_123', 'pay_123', $signature))->toBeFalse();
});

test('billing page renders when a Razorpay order is ready', function (): void {
    $tenant = new Tenant;
    $tenant->forceFill(['name' => 'Example Company', 'billing_started_at' => '2026-11-02 10:00:00']);

    $invoice = new BillingInvoice;
    $invoice->forceFill([
        'id' => 7,
        'period_start' => '2026-11-02',
        'licensed_users' => 10,
        'amount_paise' => 59000,
        'status' => 'pending',
        'razorpay_order_id' => 'order_test_123',
    ]);

    $html = view('marketing.billing', [
        'tenant' => $tenant,
        'invoices' => collect([$invoice]),
        'checkoutOrders' => [7 => ['orderId' => 'order_test_123', 'amount' => 59000, 'confirmUrl' => 'https://example.test/billing/7/confirm']],
        'checkoutConfigured' => true,
        'razorpayKey' => 'rzp_test_example',
        'errors' => new ViewErrorBag,
    ])->render();

    expect($html)->toContain('const billingOrders = ')
        ->and($html)->toContain('order_test_123')
        ->and($html)->toContain('Cancel future billing');
});

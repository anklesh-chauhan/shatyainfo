<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\Tenant;
use App\Services\Billing\MonthlyBilling;
use App\Services\Billing\RazorpayCheckout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request, MonthlyBilling $billing, RazorpayCheckout $checkout): View
    {
        $tenant = $this->authorizedTenant($request);
        $billing->generateDueInvoices($tenant);
        $invoices = BillingInvoice::query()->where('tenant_id', $tenant->id)->latest('period_start')->get();
        $checkoutOrders = $invoices
            ->filter(fn (BillingInvoice $invoice): bool => $invoice->status !== 'paid' && filled($invoice->razorpay_order_id))
            ->mapWithKeys(fn (BillingInvoice $invoice): array => [
                $invoice->id => [
                    'orderId' => $invoice->razorpay_order_id,
                    'amount' => $invoice->amount_paise,
                    'confirmUrl' => route('billing.confirm', $invoice),
                ],
            ])->all();

        return view('marketing.billing', [
            'tenant' => $tenant,
            'invoices' => $invoices,
            'checkoutOrders' => $checkoutOrders,
            'checkoutConfigured' => $checkout->configured(),
            'razorpayKey' => config('services.razorpay.key_id'),
        ]);
    }

    public function order(Request $request, BillingInvoice $invoice, RazorpayCheckout $checkout): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        abort_if($invoice->status === 'paid', 409);

        try {
            $checkout->createOrder($invoice);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['checkout' => $exception->getMessage()]);
        }

        return redirect()->route('billing.index', ['pay' => $invoice->id]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);

        if (! $tenant->billing_cancelled_at) {
            $tenant->forceFill(['billing_cancelled_at' => now()])->saveQuietly();
        }

        $duringTrial = $tenant->billing_trial_ends_at && now()->lessThan($tenant->billing_trial_ends_at);

        return redirect()->route('billing.index')->with(
            'billing_status',
            $duringTrial
                ? 'Trial cancelled. No payment is due for your free 30 days.'
                : 'Cancellation recorded. Any paid month already started remains payable; no new billing periods will begin.',
        );
    }

    public function confirm(Request $request, BillingInvoice $invoice, RazorpayCheckout $checkout): RedirectResponse
    {
        $tenant = $this->authorizedTenant($request);
        abort_unless($invoice->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'size:64'],
        ]);

        try {
            $captured = $checkout->verifyCapturedPayment($invoice, $data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']);
        } catch (Throwable $exception) {
            report($exception);
            $captured = false;
        }

        if (! $captured) {
            return redirect()->route('billing.index')->withErrors(['checkout' => 'Payment is still being confirmed. Please refresh this page shortly.']);
        }

        $this->markPaid($invoice, $data['razorpay_payment_id']);

        return redirect()->route('billing.index')->with('billing_status', 'Payment received. Thank you.');
    }

    public function webhook(Request $request): Response
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        $signature = (string) $request->header('X-Razorpay-Signature');

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            abort(401);
        }

        $event = $request->json()->all();

        if (($event['event'] ?? null) === 'payment.captured') {
            $payment = data_get($event, 'payload.payment.entity', []);
            $invoice = BillingInvoice::query()->where('razorpay_order_id', $payment['order_id'] ?? '')->first();

            if ($invoice && ($payment['currency'] ?? null) === 'INR' && (int) ($payment['amount'] ?? 0) === $invoice->amount_paise && is_string($payment['id'] ?? null)) {
                $this->markPaid($invoice, $payment['id']);
            }
        }

        return response('OK');
    }

    private function authorizedTenant(Request $request): Tenant
    {
        $tenant = Tenant::current();
        abort_unless($tenant && $request->user()?->hasRole('super_admin'), 403);

        return $tenant;
    }

    private function markPaid(BillingInvoice $invoice, string $paymentId): void
    {
        DB::connection('mysql')->transaction(function () use ($invoice, $paymentId): void {
            $locked = BillingInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status !== 'paid') {
                $locked->forceFill(['status' => 'paid', 'razorpay_payment_id' => $paymentId, 'paid_at' => now()])->save();
            }
        });
    }
}

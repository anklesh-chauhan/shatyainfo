<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RazorpayCheckout
{
    public function configured(): bool
    {
        return filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.key_secret'));
    }

    public function createOrder(BillingInvoice $invoice): string
    {
        if ($invoice->razorpay_order_id) {
            return $invoice->razorpay_order_id;
        }

        if (! $this->configured()) {
            throw new RuntimeException('Online payments are being configured. Please contact support.');
        }

        $response = $this->client()->post('https://api.razorpay.com/v1/orders', [
            'amount' => $invoice->amount_paise,
            'currency' => 'INR',
            'receipt' => 'si-'.$invoice->id,
            'notes' => ['billing_invoice_id' => (string) $invoice->id],
        ]);

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new RuntimeException('Could not open Razorpay checkout. Please try again shortly.');
        }

        $invoice->forceFill(['razorpay_order_id' => $response->json('id')])->save();

        return $invoice->razorpay_order_id;
    }

    public function verifyCapturedPayment(BillingInvoice $invoice, string $orderId, string $paymentId, string $signature): bool
    {
        if ($orderId !== $invoice->razorpay_order_id || ! $this->configured()) {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, (string) config('services.razorpay.key_secret'));

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $response = $this->client()->get('https://api.razorpay.com/v1/payments/'.rawurlencode($paymentId));

        return $response->successful()
            && $response->json('status') === 'captured'
            && $response->json('order_id') === $orderId
            && (int) $response->json('amount') === $invoice->amount_paise
            && $response->json('currency') === 'INR';
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.razorpay.key_id'), (string) config('services.razorpay.key_secret'))
            ->acceptJson()->timeout(15);
    }
}

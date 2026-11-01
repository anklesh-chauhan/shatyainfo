<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoice extends Model
{
    protected $connection = 'mysql';

    protected $fillable = ['tenant_id', 'period_start', 'licensed_users', 'amount_paise', 'status', 'razorpay_order_id', 'razorpay_payment_id', 'paid_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'paid_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

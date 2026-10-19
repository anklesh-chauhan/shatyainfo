<?php

namespace App\Models;

use App\Traits\HasOperationalApproval;

class SalesInvoice extends SalesDocument
{
    use HasOperationalApproval;

    protected $table = 'sales_invoices';

    protected $fillable = [
        ...parent::FILLABLE,
        'account_master_id',
        'due_date',
        'payment_status',
        'paid_at',
        'other_ref',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'date' => 'date',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];

    public function salesOrders()
    {
        return $this->belongsToMany(SalesOrder::class, 'sales_order_sales_invoice_pivot');
    }

    public function quotes()
    {
        return $this->belongsToMany(Quote::class, 'quote_sales_invoice_pivot');
    }

    public function accountMaster()
    {
        return $this->belongsTo(AccountMaster::class);
    }

    public function deliveryChallans()
    {
        return $this->hasMany(DeliveryChallan::class);
    }

    public function hasPendingDelivery(): bool
    {
        $this->loadMissing('items');

        return $this->items->contains(
            fn (SalesDocumentItem $item): bool => $item->item_master_id !== null && $item->remainingQuantity() > 0
        );
    }

    public function refreshDeliveryStatus(): void
    {
        $this->loadMissing('items');

        if ($this->items->isEmpty()) {
            return;
        }

        $stockItems = $this->items->filter(fn (SalesDocumentItem $item): bool => $item->item_master_id !== null);

        if ($stockItems->isEmpty()) {
            return;
        }

        $allDelivered = $stockItems->every(
            fn (SalesDocumentItem $item): bool => (float) $item->quantity_delivered >= (float) $item->quantity
        );

        if ($allDelivered && $this->status !== 'canceled') {
            $this->forceFill(['status' => 'accepted'])->saveQuietly();
        }
    }

    public function getPaymentStatusAttribute($value)
    {
        return $value === 'paid' ? 'Paid' : ($value === 'unpaid' ? 'Unpaid' : 'Partially Paid');
    }

    public function setPaymentStatusAttribute($value)
    {
        $this->attributes['payment_status'] = strtolower($value);
    }
}

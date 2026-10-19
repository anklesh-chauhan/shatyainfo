<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends BaseModel
{
    use HasOperationalApproval;

    protected $fillable = [
        'document_number',
        'supplier_id',
        'location_master_id',
        'order_date',
        'expected_delivery_date',
        'status',
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency',
        'payment_term_id',
        'notes',
        'approval_status',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'status' => PurchaseOrderStatus::class,
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'supplier_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'location_master_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function goodsReceiptNotes(): HasMany
    {
        return $this->hasMany(GoodsReceiptNote::class);
    }

    public function recalculateTotals(): void
    {
        $this->loadMissing('lines');

        $subtotal = $this->lines->sum(fn (PurchaseOrderLine $line): float => (float) $line->line_total);
        $taxAmount = $this->lines->sum(fn (PurchaseOrderLine $line): float => (float) $line->line_total * ((float) $line->tax_rate / 100));

        $this->forceFill([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($subtotal + $taxAmount, 2),
        ])->save();
    }

    public function refreshReceiptStatus(): void
    {
        $this->loadMissing('lines');

        if ($this->lines->isEmpty()) {
            return;
        }

        $allReceived = $this->lines->every(
            fn (PurchaseOrderLine $line): bool => (float) $line->quantity_received >= (float) $line->quantity_ordered
        );

        $anyReceived = $this->lines->contains(
            fn (PurchaseOrderLine $line): bool => (float) $line->quantity_received > 0
        );

        $status = match (true) {
            $allReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => $this->status === PurchaseOrderStatus::Cancelled
                ? PurchaseOrderStatus::Cancelled
                : ($this->status === PurchaseOrderStatus::Draft
                    ? PurchaseOrderStatus::Draft
                    : PurchaseOrderStatus::Approved),
        };

        if ($this->status !== $status) {
            $this->forceFill(['status' => $status])->saveQuietly();
        }
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted], true)
            && in_array($this->approval_status, ['draft', 'rejected'], true) && ! $this->approvalDocumentIsLocked();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (PurchaseOrder $purchaseOrder): void {
            $purchaseOrder->document_number ??= NumberSeries::getNextNumber(PurchaseOrder::class);
            $purchaseOrder->status ??= PurchaseOrderStatus::Draft;
            $purchaseOrder->approval_status ??= 'draft';
        });

        static::created(function (PurchaseOrder $purchaseOrder): void {
            NumberSeries::incrementNextNumber(PurchaseOrder::class);
        });
    }
}

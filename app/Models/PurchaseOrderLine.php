<?php

namespace App\Models;

use App\Traits\LocksWithApprovalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends BaseModel
{
    use LocksWithApprovalDocument;

    public function approvalDocument(): ?Model
    {
        return $this->purchaseOrder()->first();
    }

    protected $fillable = [
        'purchase_order_id',
        'item_master_id',
        'quantity_ordered',
        'quantity_received',
        'unit_price',
        'tax_rate',
        'line_total',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }

    public function goodsReceiptNoteLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteLine::class);
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    public function recalculateLineTotal(): void
    {
        $this->forceFill([
            'line_total' => round((float) $this->quantity_ordered * (float) $this->unit_price, 2),
        ])->save();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (PurchaseOrderLine $line): void {
            $line->line_total = round((float) $line->quantity_ordered * (float) $line->unit_price, 2);
        });

        static::saved(function (PurchaseOrderLine $line): void {
            $line->purchaseOrder?->recalculateTotals();
        });

        static::deleted(function (PurchaseOrderLine $line): void {
            $line->purchaseOrder?->recalculateTotals();
        });
    }
}

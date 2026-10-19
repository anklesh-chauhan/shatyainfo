<?php

namespace App\Models;

use App\Traits\LocksWithApprovalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptNoteLine extends BaseModel
{
    use LocksWithApprovalDocument;

    public function approvalDocument(): ?Model
    {
        return $this->goodsReceiptNote()->first();
    }

    protected $fillable = [
        'goods_receipt_note_id',
        'purchase_order_line_id',
        'item_master_id',
        'quantity_received',
        'unit_cost',
        'batch_number',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }
}

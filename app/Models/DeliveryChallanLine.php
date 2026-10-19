<?php

namespace App\Models;

use App\Traits\LocksWithApprovalDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryChallanLine extends BaseModel
{
    use LocksWithApprovalDocument;

    public function approvalDocument(): ?Model
    {
        return $this->deliveryChallan()->first();
    }

    protected $fillable = [
        'delivery_challan_id',
        'sales_document_item_id',
        'item_master_id',
        'quantity_delivered',
        'unit_cost',
        'batch_number',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delivered' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function deliveryChallan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class);
    }

    public function salesDocumentItem(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }
}

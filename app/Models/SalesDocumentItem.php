<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use App\Traits\LocksWithApprovalDocument;
use Illuminate\Database\Eloquent\Model;

class SalesDocumentItem extends BaseModel
{
    use LocksWithApprovalDocument;

    public function approvalDocument(): ?Model
    {
        return $this->document()->first();
    }

    use HasApprovalWorkflow;

    protected $table = 'sales_document_items';

    protected $fillable = [
        'document_id',
        'document_type',
        'item_master_id',
        'quantity',
        'quantity_delivered',
        'price',
        'discount',
        'unit',
        'unit_price',
        'hsn_sac', // Harmonized System Nomenclature/SAC (Service Accounting Code)
        'tax_rate',
        'amount',
        'final_taxable_amount',
        'description',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quantity_delivered' => 'decimal:3',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function document()
    {
        return $this->morphTo();
    }

    public function itemMaster()
    {
        return $this->belongsTo(ItemMaster::class);
    }

    public function deliveryChallanLines()
    {
        return $this->hasMany(DeliveryChallanLine::class);
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity - (float) $this->quantity_delivered);
    }
}

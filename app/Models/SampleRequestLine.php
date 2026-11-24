<?php

namespace App\Models;

use Database\Factories\SampleRequestLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SampleRequestLine extends BaseModel
{
    /** @use HasFactory<SampleRequestLineFactory> */
    use HasFactory;

    protected $fillable = [
        'sample_request_id',
        'item_master_id',
        'quantity_requested',
        'quantity_approved',
        'quantity_issued',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:3',
            'quantity_approved' => 'decimal:3',
            'quantity_issued' => 'decimal:3',
        ];
    }

    public function sampleRequest(): BelongsTo
    {
        return $this->belongsTo(SampleRequest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }

    public function remainingApprovedQuantity(): float
    {
        return max(0, round((float) $this->quantity_approved - (float) $this->quantity_issued, 3));
    }
}

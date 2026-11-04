<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldOrderState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['fields' => 'array', 'clocks' => 'array'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuUnitConversion extends Model
{
    protected $guarded = [];

    public function itemMaster(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class);
    }

    public function setUnitAttribute(string $unit): void
    {
        $this->attributes['unit'] = strtolower(trim($unit));
    }
}

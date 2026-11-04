<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecondarySalesStatement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SecondarySalesLine::class);
    }

    public function stockist(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'stockist_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $statement): void {
            abort_if(in_array($statement->getOriginal('status'), ['approved', 'rejected'], true), 409, 'Reviewed statements are immutable.');
        });
        static::deleting(fn () => abort(409, 'Retain statement audit history.'));
    }
}

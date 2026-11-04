<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritorySalesAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $assignment): void {
            abort_if(array_diff(array_keys($assignment->getDirty()), ['effective_to', 'updated_at']) || $assignment->getRawOriginal('effective_to') !== null,
                409, 'Assignment history is permanent; append the next effective assignment.');
        });
        static::deleting(fn () => abort(409, 'Retain territory assignment history.'));
    }
}

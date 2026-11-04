<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritorySalesTarget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'revisions' => 'array', 'target_value_paise' => 'integer'];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $target): void {
            abort_if(CarbonImmutable::parse($target->getRawOriginal('starts_on'))->lte(today()), 409, 'Targets are fixed after their period begins.');
            abort_if($target->isDirty(['territory_id', 'period_type', 'starts_on', 'ends_on', 'created_by']), 409, 'Target period identity is permanent.');
            $history = $target->getOriginal('revisions') ?? [];
            $history[] = ['target_value_paise' => $target->getOriginal('target_value_paise'), 'target_quantity' => $target->getOriginal('target_quantity'),
                'note' => $target->getOriginal('note'), 'changed_by' => auth()->id(), 'changed_at' => now()->toIso8601String()];
            $target->revisions = $history;
        });
        static::deleting(fn () => abort(409, 'Retain targets and their revision history.'));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RcpaObservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['competitors' => 'array', 'observed_on' => 'date', 'captured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function chemist(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'chemist_id');
    }

    public function itemMaster(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class);
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function therapyArea(): BelongsTo
    {
        return $this->belongsTo(AccountReportingMaster::class);
    }

    public function scopeForRegister(Builder $query, User $actor): Builder
    {
        if ($actor->can('View:Rcpa')) {
            return $query->whereIn('chemist_id', AccountMaster::query()->applyVisibility('AccountMaster', $actor)->select('id'));
        }

        return $query->where('user_id', $actor->id);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('RCPA observations are append-only.'));
        static::deleting(fn () => throw new LogicException('Retain RCPA observations for audit.'));
    }
}

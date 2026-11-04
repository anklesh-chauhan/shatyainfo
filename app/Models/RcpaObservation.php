<?php

namespace App\Models;

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

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('RCPA observations are append-only.'));
        static::deleting(fn () => throw new LogicException('Retain RCPA observations for audit.'));
    }
}

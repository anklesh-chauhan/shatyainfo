<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FieldVisitEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target' => 'array', 'payload' => 'array',
            'captured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Field events are append-only.'));
        static::deleting(fn () => throw new LogicException('Field events are append-only.'));
    }
}

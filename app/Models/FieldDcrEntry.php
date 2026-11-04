<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FieldDcrEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['captured_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('DCR entries are append-only.'));
        static::deleting(fn () => throw new LogicException('DCR entries are append-only.'));
    }
}

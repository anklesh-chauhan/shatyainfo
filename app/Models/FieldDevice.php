<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered field client that reports which dates still hold unsynchronized work.
 *
 * @property array<int, array{date: string, count: int, oldest_captured_at: ?string}> $pending_dates
 */
class FieldDevice extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function pendingCountForDate(string $date): int
    {
        foreach ($this->pending_dates ?? [] as $entry) {
            if (($entry['date'] ?? null) === $date) {
                return (int) ($entry['count'] ?? 0);
            }
        }

        return 0;
    }

    protected function casts(): array
    {
        return ['pending_dates' => 'array', 'history' => 'array', 'reported_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $device): void {
            abort_if($device->isDirty(['user_id', 'device_id']), 409, 'A device registration belongs to its original account.');
        });
        static::deleting(fn () => abort(409, 'Retain device registration history.'));
    }
}

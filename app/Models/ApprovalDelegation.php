<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ApprovalDelegation extends BaseModel
{
    protected $fillable = [
        'delegator_user_id',
        'delegate_user_id',
        'module',
        'starts_at',
        'ends_at',
        'active',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function scopeActiveFor(Builder $query, int $delegatorUserId, ?string $module = null, ?string $date = null): Builder
    {
        $at = $date ? Carbon::parse($date) : now();

        return $query
            ->where('delegator_user_id', $delegatorUserId)
            ->where('active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('module')->orWhere('module', $module))
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->latest('starts_at');
    }
}

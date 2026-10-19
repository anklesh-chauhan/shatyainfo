<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountCallTarget extends Model
{
    protected $fillable = ['calls_per_month', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'calls_per_month' => 'integer'];
    }

    public function accountMaster(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class);
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            AccountMaster::query()->whereKey($this->account_master_id)->lockForUpdate()->firstOrFail();
            Validator::make($this->getAttributes(), [
                'calls_per_month' => ['required', 'integer', 'between:0,1000'],
                'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            ])->validate();
            if ($this->effective_from->day !== 1 || ($this->effective_to && $this->effective_to->day !== $this->effective_to->daysInMonth)) {
                throw ValidationException::withMessages(['effective_from' => 'Monthly targets must start on the first day and end on the last day of a month.']);
            }
            if (self::query()->where('account_master_id', $this->account_master_id)->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
                ->where('effective_from', '<=', $this->effective_to?->toDateString() ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $this->effective_from->toDateString()))->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'A call target already covers this month. End that target first.']);
            }
            if (! $this->exists) {
                $this->created_by = auth()->id();
            }

            return parent::save($options);
        });
    }
}

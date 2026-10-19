<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountDivisionProfile extends Model
{
    protected $fillable = ['division_ou_id', 'rating_type_id', 'effective_from', 'effective_to', 'potential_value', 'potential_unit_id', 'potential_period', 'currency', 'assessed_on'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'assessed_on' => 'date', 'potential_value' => 'decimal:2'];
    }

    public function accountMaster(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'division_ou_id');
    }

    public function ratingType(): BelongsTo
    {
        return $this->belongsTo(RatingType::class);
    }

    public function potentialUnit(): BelongsTo
    {
        return $this->belongsTo(AccountReportingMaster::class, 'potential_unit_id');
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            AccountMaster::query()->whereKey($this->account_master_id)->lockForUpdate()->firstOrFail();
            Validator::make($this->getAttributes(), [
                'rating_type_id' => ['required', Rule::exists('rating_types', 'id')->where('is_lifecycle', false)->whereNull('deleted_at')],
                'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
                'potential_value' => ['nullable', 'required_with:potential_unit_id,potential_period,currency,assessed_on', 'numeric', 'min:0', 'max:9999999999999999.99'],
                'potential_unit_id' => ['nullable', 'required_with:potential_value', Rule::exists('account_reporting_masters', 'id')->where('kind', 'potential_unit')->where('is_active', true)],
                'potential_period' => ['nullable', 'required_with:potential_value', Rule::in(['monthly', 'quarterly', 'annual'])],
                'assessed_on' => ['nullable', 'required_with:potential_value', 'date', 'before_or_equal:today'],
                'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            ])->validate();
            if ($this->division_ou_id && ! OrganizationalUnit::query()->whereKey($this->division_ou_id)->where('is_active', true)->whereHas('typeMaster', fn ($q) => $q->where('name', 'Division')->where('typeable_type', OrganizationalUnit::class))->exists()) {
                throw ValidationException::withMessages(['division_ou_id' => 'Choose an active Division.']);
            }
            if ($this->potential_value !== null && $this->potentialUnit()->value('is_monetary') && blank($this->currency)) {
                throw ValidationException::withMessages(['currency' => 'A three-letter currency code is required for monetary potential.']);
            }
            if (! $this->potentialUnit()->value('is_monetary')) {
                $this->currency = null;
            }
            $overlap = self::query()->where('account_master_id', $this->account_master_id)->where('division_ou_id', $this->division_ou_id)
                ->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
                ->where('effective_from', '<=', $this->effective_to?->toDateString() ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $this->effective_from->toDateString()))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => 'This division already has a profile for part of this period. End that profile first.']);
            }
            if (! $this->exists) {
                $this->created_by = auth()->id();
            }

            return parent::save($options);
        });
    }
}

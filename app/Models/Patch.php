<?php

namespace App\Models;

use App\Services\PositionService;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class Patch extends BaseModel
{
    use HasApprovalWorkflow, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'territory_id',
        'city_pin_code_id',
        'description',
        'color',
        'approval_status',
    ];

    public function territory()
    {
        return $this->belongsTo(Territory::class);
    }

    public function cityPinCode()
    {
        return $this->belongsTo(CityPinCode::class, 'city_pin_code_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(AccountMaster::class)
            ->using(AccountMasterPatch::class)
            ->withPivot(['sequence_no', 'distance_km'])
            ->orderByPivot('sequence_no')
            ->withTimestamps();
    }

    public function customerPinCodes(): Collection
    {
        return $this->city_pin_code_id
            ? CityPinCode::query()->whereKey($this->city_pin_code_id)->pluck('pin_code')
            : $this->territory?->cityPinCodes()->pluck('pin_code') ?? collect();
    }

    public function eligibleCustomers(Builder $query): Builder
    {
        $pins = $this->customerPinCodes();

        return $query->whereHas('addresses', fn (Builder $addresses): Builder => $addresses->whereIn('pin_code', $pins))
            ->whereHas('typeMaster', fn (Builder $types): Builder => $types->where('name', 'Customer')
                ->orWhereHas('parent', fn (Builder $parent): Builder => $parent->where('name', 'Customer')));
    }

    public function legacyApprovalState(): ?string
    {
        if ($this->approval_status === 'pending' && $this->approval === null && ! ApprovalSetting::supportsModule(self::class)) {
            return 'draft';
        }

        return $this->approval_status;
    }

    protected static function booted(): void
    {
        static::saving(function (Patch $model) {

            /** @var User|null $user */
            $user = Auth::user();

            // CLI / Seeder / System safety
            if (! $user) {
                return;
            }

            // Admins bypass
            if (
                $user->hasAnyRole(['super_admin', 'administration_admin']) ||
                $user->can('AccessAllRecords')
            ) {
                return;
            }

            // Enforce territory restriction
            if ($user->can('ViewOwnTerritory:Patch')) {

                $allowedTerritories = PositionService::getTerritoryIdsForUser($user);

                if (
                    empty($allowedTerritories) ||
                    ! in_array($model->territory_id, $allowedTerritories, true)
                ) {
                    throw new \DomainException(
                        'You are not allowed to assign this territory.'
                    );
                }
            }
        });
    }
}

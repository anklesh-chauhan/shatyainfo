<?php

namespace App\Models;

use App\Services\PositionService;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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

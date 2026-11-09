<?php

namespace App\Traits;

use App\Models\BaseModel;
use App\Models\User;
use App\Services\OrganizationalUnitService;
use App\Services\PositionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

trait HasVisibilityScope
{
    public function scopeApplyVisibility(Builder $query, string $model, ?User $actor = null): Builder
    {
        $user = $actor ?? Auth::user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        /* =====================================================
        | 1. SUPER / FULL ACCESS
        ===================================================== */
        if (
            $user->hasRole('super_admin') ||
            $user->hasRole('administration_admin') ||
            $user->can('AccessAllRecords')
        ) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        $query->where(function (Builder $q) use ($user, $model, $table) {

            /* =====================================================
            | A. ALWAYS SHOW OWN RECORDS
            ===================================================== */
            if (Schema::hasColumn($table, 'created_by')) {
                $q->orWhere($table.'.created_by', $user->id);
            }

            if (Schema::hasColumn($table, 'user_id')) {
                $q->orWhere($table.'.user_id', $user->id);
            }

            if (Schema::hasColumn($table, 'login_id')) {
                $q->orWhere($table.'.login_id', $user->id);
            }

            /* =====================================================
            | B. VIEW OWN TERRITORY
            ===================================================== */
            if ($user->can("ViewOwnTerritory:{$model}")) {

                $territoryIds = PositionService::getTerritoryIdsForUser($user);

                if (! empty($territoryIds)) {
                    $modelInstance = $q->getModel();
                    $usesCustomTerritoryScope = false;

                    if (method_exists($modelInstance, 'scopeApplyTerritoryVisibility')) {
                        $declaringClass = (new \ReflectionMethod($modelInstance, 'scopeApplyTerritoryVisibility'))
                            ->getDeclaringClass()
                            ->getName();

                        $usesCustomTerritoryScope = $declaringClass !== BaseModel::class;
                    }

                    if ($usesCustomTerritoryScope) {
                        $q->orWhere(function (Builder $tq) use ($territoryIds) {
                            $tq->applyTerritoryVisibility($territoryIds);
                        });
                    } elseif (Schema::hasColumn($table, 'territory_id')) {
                        $q->orWhereIn($table.'.territory_id', $territoryIds);
                    } elseif (Schema::hasColumn($table, 'id') && $table === 'territories') {
                        $q->orWhereIn($table.'.id', $territoryIds);
                    }
                }
            }

            /* =====================================================
            | C. VIEW OWN OU
            ===================================================== */
            if ($user->can("ViewOwnOU:{$model}")) {

                $ouIds = OrganizationalUnitService::getUserOuIds($user);

                if (! empty($ouIds)) {
                    $modelInstance = $q->getModel();
                    $usesCustomOuScope = false;

                    if (method_exists($modelInstance, 'scopeApplyOuVisibility')) {
                        $declaringClass = (new \ReflectionMethod($modelInstance, 'scopeApplyOuVisibility'))
                            ->getDeclaringClass()
                            ->getName();

                        $usesCustomOuScope = $declaringClass !== BaseModel::class;
                    }

                    if ($usesCustomOuScope) {
                        $q->orWhere(function (Builder $oq) use ($ouIds) {
                            $oq->applyOuVisibility($ouIds);
                        });
                    } else {
                        $modelInstance = $q->getModel();

                        if (Schema::hasColumn($table, 'territory_id') && method_exists($modelInstance, 'territory')) {
                            $q->orWhereHas('territory', function (Builder $territoryQuery) use ($ouIds) {
                                $territoryQuery->whereIn('territories.division_ou_id', $ouIds);
                            });
                        } else {
                            $q->orWhereHas(
                                'creator.employee.employmentDetail.organizationalUnits',
                                fn (Builder $ouQuery) => $ouQuery->whereIn('organizational_units.id', $ouIds)
                            );
                        }
                    }
                }
            }

            /* =====================================================
            | D. VIEW OWN (MODEL-DEFINED)
            ===================================================== */
            if ($user->can("ViewOwn:{$model}")) {

                if (method_exists($q->getModel(), 'scopeApplyOwnVisibility')) {
                    $q->orWhere(function ($oq) use ($user) {
                        $oq->applyOwnVisibility($user);
                    });
                }
            }
        });

        return $query;
    }
}

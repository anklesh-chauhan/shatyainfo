<?php

namespace App\Services\Visit;

use App\Models\SalesDcr;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class DcrService
{
    public function getOrCreateForDate(Carbon|string $date, ?User $owner = null, ?Closure $beforeCreate = null): SalesDcr
    {
        $user = $owner ?? Auth::user();
        $dcrDate = $date instanceof Carbon ? $date->toDateString() : $date;

        return $user->getConnection()->transaction(function () use ($user, $dcrDate, $beforeCreate): SalesDcr {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $identity = ['user_id' => $user->id, 'dcr_date' => $dcrDate];
            $existing = SalesDcr::query()->where($identity)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $beforeCreate?->__invoke();

            return SalesDcr::query()->create([
                ...$identity, 'approval_status' => 'draft', 'territory_id' => $user->territory_id,
            ]);
        }, attempts: 3);
    }
}

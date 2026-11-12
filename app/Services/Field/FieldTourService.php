<?php

namespace App\Services\Field;

use App\Models\SalesTourPlanDetail;
use App\Models\User;
use Illuminate\Support\Collection;

class FieldTourService
{
    /**
     * @return Collection<int, SalesTourPlanDetail>
     */
    public function forDate(User $user, ?string $date = null): Collection
    {
        if (! app(FieldPlanningService::class)->capabilities()['supports_mtp']) {
            return collect();
        }

        $date ??= today()->toDateString();

        return SalesTourPlanDetail::query()
            ->whereDate('date', $date)
            ->whereHas('tourPlan', fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'territory',
                'tourPlan',
                'patches.companies.typeMaster',
                'patches.companies.contactDetails',
                'visits' => fn ($query) => $query
                    ->where('employee_id', $user->id)
                    ->with(['visitables.visitable', 'media.tags']),
            ])
            ->orderBy('id')
            ->get();
    }
}

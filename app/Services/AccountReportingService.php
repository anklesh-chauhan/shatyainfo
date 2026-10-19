<?php

namespace App\Services;

use App\Models\AccountMaster;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AccountReportingService
{
    public function monthlyQuery(string $month): Builder
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();

        return AccountMaster::query()->applyVisibility('AccountMaster')
            ->with(['specialty', 'therapyArea', 'accountStatus',
                'callTargets' => fn ($q) => $q->where('effective_from', '<=', $start->toDateString())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $end->toDateString())),
                'assignmentHistory' => fn ($q) => $q->where('effective_from', '<=', $end)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $end))->with(['owner', 'territory']),
            ])->addSelect(['completed_calls' => Visit::query()->selectRaw('COUNT(*)')->where('visit_status', 'completed')->whereBetween('visit_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('visitables', fn ($q) => $q->where('visitable_type', AccountMaster::class)->whereColumn('visitable_id', 'account_masters.id'))]);
    }

    public function metrics(AccountMaster $account): array
    {
        $target = $account->callTargets->first()?->calls_per_month;
        $actual = (int) $account->completed_calls;

        return ['target' => $target, 'completed' => $actual, 'gap' => $target === null ? null : max(0, $target - $actual),
            'achievement' => $target ? round($actual * 100 / $target, 1) : null, 'reached' => $actual > 0];
    }
}

<?php

namespace App\Services\SalespersonAi;

use App\Models\AccountMaster;
use App\Models\SalesTourPlan;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;

class TourPlanDraftService
{
    /**
     * @return array{month: string, based_on_month: string|null, days: list<array<string, mixed>>, uncovered_accounts: list<array<string, mixed>>, summary: string}
     */
    public function forUser(User $user, string $month): array
    {
        $target = Carbon::createFromFormat('Y-m', $month)?->startOfMonth();

        if (! $target) {
            return [
                'month' => $month,
                'based_on_month' => null,
                'days' => [],
                'uncovered_accounts' => [],
                'summary' => 'The month is not valid.',
            ];
        }

        $previous = $target->copy()->subMonth();
        $plan = SalesTourPlan::query()
            ->where('user_id', $user->id)
            ->where('month', 'like', $previous->format('Y-m').'%')
            ->with(['details.patches.companies', 'details.territory'])
            ->first();

        if (! $plan) {
            return [
                'month' => $target->format('Y-m'),
                'based_on_month' => null,
                'days' => [],
                'uncovered_accounts' => [],
                'summary' => 'No plan was found for '.$previous->format('F Y').', so there is no draft to copy.',
            ];
        }

        $days = [];

        foreach ($plan->details as $detail) {
            $shifted = Carbon::parse($detail->date)->addMonth();

            if ($shifted->format('Y-m') !== $target->format('Y-m')) {
                continue;
            }

            $key = $shifted->toDateString();
            $patchIds = $detail->patches->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $patchNames = $detail->patches->pluck('name')->all();

            if (! isset($days[$key])) {
                $days[$key] = [
                    'date' => $key,
                    'territory_id' => $detail->territory_id,
                    'territory_name' => $detail->territory?->name,
                    'patch_ids' => $patchIds,
                    'patch_names' => $patchNames,
                    'reason' => 'Same calendar day as '.$detail->date->toDateString().' on the previous plan.',
                ];

                continue;
            }

            $days[$key]['patch_ids'] = array_values(array_unique([...$days[$key]['patch_ids'], ...$patchIds]));
            $days[$key]['patch_names'] = array_values(array_unique([...$days[$key]['patch_names'], ...$patchNames]));
        }

        ksort($days);
        $uncovered = $this->uncoveredAccounts($user, $plan, $previous);
        $dayCount = count($days);

        return [
            'month' => $target->format('Y-m'),
            'based_on_month' => $previous->format('Y-m'),
            'days' => array_values($days),
            'uncovered_accounts' => $uncovered,
            'summary' => $dayCount === 0
                ? 'The previous plan has no days that land in '.$target->format('F Y').'.'
                : $dayCount.' draft day'.($dayCount === 1 ? '' : 's').' for '.$target->format('F Y').' copied from '.$previous->format('F Y').'. Create the tour plan yourself after you edit it.',
        ];
    }

    /**
     * @return list<array{id: int, name: string, reason: string}>
     */
    private function uncoveredAccounts(User $user, SalesTourPlan $plan, Carbon $previous): array
    {
        $accounts = [];

        foreach ($plan->details as $detail) {
            foreach ($detail->patches as $patch) {
                foreach ($patch->companies as $company) {
                    $accounts[$company->id] = $company->name;
                }
            }
        }

        if ($accounts === []) {
            return [];
        }

        $completed = Visit::query()
            ->where('employee_id', $user->id)
            ->where('visit_status', 'completed')
            ->whereBetween('visit_date', [
                $previous->copy()->startOfMonth()->toDateString(),
                $previous->copy()->endOfMonth()->toDateString(),
            ])
            ->with('visitables')
            ->get()
            ->flatMap(fn (Visit $visit) => $visit->visitables
                ->where('visitable_type', AccountMaster::class)
                ->pluck('visitable_id'))
            ->map(fn ($id): int => (int) $id)
            ->all();

        $rows = [];

        foreach ($accounts as $id => $name) {
            if (in_array((int) $id, $completed, true)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $id,
                'name' => $name,
                'reason' => 'Planned in '.$previous->format('F Y').' with no completed visit.',
            ];
        }

        return $rows;
    }
}

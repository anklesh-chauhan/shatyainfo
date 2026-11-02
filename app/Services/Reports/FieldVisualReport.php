<?php

namespace App\Services\Reports;

use App\Models\ExpenseType;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\SalesTourPlanDetail;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FieldVisualReport
{
    /**
     * @var array<string, string>
     */
    public const PERIODS = [
        'today' => 'Today',
        'last_7' => 'Last 7 days',
        'this_month' => 'This month',
        'last_30' => 'Last 30 days',
    ];

    /**
     * @var array<string, string>
     */
    private const CALL_LABELS = [
        'completed' => 'Completed',
        'started' => 'In progress',
        'checked_out_pending' => 'Checked out',
        'draft' => 'Not started',
        'cancelled' => 'Cancelled',
    ];

    /**
     * @var array<string, string>
     */
    private const CALL_COLORS = [
        'completed' => '#16a34a',
        'started' => '#2563eb',
        'checked_out_pending' => '#0891b2',
        'draft' => '#f59e0b',
        'cancelled' => '#94a3b8',
    ];

    /**
     * @var array<string, string>
     */
    private const DCR_LABELS = [
        'draft' => 'Draft',
        'pending' => 'Pending',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /**
     * @var array<string, string>
     */
    private const DCR_COLORS = [
        'draft' => '#f59e0b',
        'pending' => '#2563eb',
        'submitted' => '#7c3aed',
        'approved' => '#16a34a',
        'rejected' => '#dc2626',
    ];

    /**
     * @return array{key: string, label: string, from: CarbonImmutable, until: CarbonImmutable}
     */
    public function period(?string $key): array
    {
        $key = array_key_exists((string) $key, self::PERIODS) ? (string) $key : 'last_7';
        $until = CarbonImmutable::today();

        $from = match ($key) {
            'today' => $until,
            'this_month' => $until->startOfMonth(),
            'last_30' => $until->subDays(29),
            default => $until->subDays(6),
        };

        return [
            'key' => $key,
            'label' => self::PERIODS[$key],
            'from' => $from,
            'until' => $until,
        ];
    }

    /**
     * @return array{labels: list<string>, completed: list<int>, open: list<int>, cancelled: list<int>}
     */
    public function callsByDay(?User $user, ?string $periodKey): array
    {
        $period = $this->period($periodKey);
        $days = [];
        $cursor = $period['from'];

        while ($cursor->lte($period['until'])) {
            $days[$cursor->toDateString()] = ['completed' => 0, 'open' => 0, 'cancelled' => 0];
            $cursor = $cursor->addDay();
        }

        if ($user) {
            $this->asUser($user);

            $rows = Visit::query()
                ->applyVisibility('Visit')
                ->whereDate('visits.visit_date', '>=', $period['from']->toDateString())
                ->whereDate('visits.visit_date', '<=', $period['until']->toDateString())
                ->selectRaw('visits.visit_date as day, visits.visit_status as visit_status, count(*) as aggregate')
                ->groupBy('visits.visit_date', 'visits.visit_status')
                ->get();

            foreach ($rows as $row) {
                $day = CarbonImmutable::parse($row->day)->toDateString();

                if (! isset($days[$day])) {
                    continue;
                }

                $bucket = match ($row->visit_status) {
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    default => 'open',
                };

                $days[$day][$bucket] += (int) $row->aggregate;
            }
        }

        return [
            'labels' => array_map(
                fn (string $day): string => CarbonImmutable::parse($day)->format('d M'),
                array_keys($days)
            ),
            'completed' => array_column($days, 'completed'),
            'open' => array_column($days, 'open'),
            'cancelled' => array_column($days, 'cancelled'),
        ];
    }

    /**
     * @return array{labels: list<string>, data: list<int>, colors: list<string>}
     */
    public function callStatus(?User $user, ?string $periodKey): array
    {
        return $this->statusSlices(
            $user,
            $periodKey,
            Visit::class,
            'Visit',
            'visits.visit_date',
            'visits.visit_status',
            self::CALL_LABELS,
            self::CALL_COLORS,
        );
    }

    /**
     * @return array{labels: list<string>, data: list<int>, colors: list<string>}
     */
    public function dcrStatus(?User $user, ?string $periodKey): array
    {
        return $this->statusSlices(
            $user,
            $periodKey,
            SalesDcr::class,
            'SalesDcr',
            'sales_dcrs.dcr_date',
            'sales_dcrs.approval_status',
            self::DCR_LABELS,
            self::DCR_COLORS,
        );
    }

    /**
     * @return array{labels: list<string>, data: list<int>}
     */
    public function planAndCalls(?User $user, ?string $periodKey): array
    {
        $period = $this->period($periodKey);
        $tourDays = 0;
        $completed = 0;
        $open = 0;

        if ($user) {
            $this->asUser($user);

            $tourDays = SalesTourPlanDetail::query()
                ->applyVisibility('SalesTourPlanDetail')
                ->whereDate('date', '>=', $period['from']->toDateString())
                ->whereDate('date', '<=', $period['until']->toDateString())
                ->count();

            $counts = Visit::query()
                ->applyVisibility('Visit')
                ->whereDate('visits.visit_date', '>=', $period['from']->toDateString())
                ->whereDate('visits.visit_date', '<=', $period['until']->toDateString())
                ->selectRaw('visits.visit_status as visit_status, count(*) as aggregate')
                ->groupBy('visits.visit_status')
                ->get();

            foreach ($counts as $row) {
                $status = $row->visit_status;
                $total = (int) $row->aggregate;

                if ($status === 'completed') {
                    $completed += $total;
                } elseif ($status !== 'cancelled') {
                    $open += $total;
                }
            }
        }

        return [
            'labels' => ['Tour days', 'Completed calls', 'Open calls'],
            'data' => [$tourDays, $completed, $open],
        ];
    }

    /**
     * @return array{labels: list<string>, data: list<float>, total: float}
     */
    public function expensesByType(?User $user, ?string $periodKey): array
    {
        $period = $this->period($periodKey);

        if (! $user) {
            return ['labels' => ['No expenses'], 'data' => [0.0], 'total' => 0.0];
        }

        $this->asUser($user);

        $rows = SalesDcrExpense::query()
            ->selectRaw('expense_type_id, sum(amount) as aggregate')
            ->whereHas(
                'salesDcr',
                fn (Builder $query): Builder => $query
                    ->applyVisibility('SalesDcr')
                    ->whereDate('dcr_date', '>=', $period['from']->toDateString())
                    ->whereDate('dcr_date', '<=', $period['until']->toDateString())
            )
            ->groupBy('expense_type_id')
            ->orderByDesc('aggregate')
            ->limit(8)
            ->get();

        if ($rows->isEmpty()) {
            return ['labels' => ['No expenses'], 'data' => [0.0], 'total' => 0.0];
        }

        $names = ExpenseType::query()
            ->whereIn('id', $rows->pluck('expense_type_id')->filter())
            ->pluck('name', 'id');

        $labels = [];
        $data = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $amount = round((float) $row->aggregate, 2);
            $labels[] = $names[$row->expense_type_id] ?? 'Other';
            $data[] = $amount;
            $total += $amount;
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'total' => round($total, 2),
        ];
    }

    /**
     * @return array{labels: list<string>, data: list<int>}
     */
    public function callsByEmployee(?User $user, ?string $periodKey): array
    {
        $period = $this->period($periodKey);

        if (! $user) {
            return ['labels' => ['No completed calls'], 'data' => [0]];
        }

        $this->asUser($user);

        $rows = Visit::query()
            ->applyVisibility('Visit')
            ->where('visits.visit_status', 'completed')
            ->whereDate('visits.visit_date', '>=', $period['from']->toDateString())
            ->whereDate('visits.visit_date', '<=', $period['until']->toDateString())
            ->selectRaw('visits.employee_id as employee_id, count(*) as aggregate')
            ->groupBy('visits.employee_id')
            ->orderByDesc('aggregate')
            ->limit(8)
            ->get();

        if ($rows->isEmpty()) {
            return ['labels' => ['No completed calls'], 'data' => [0]];
        }

        $names = User::query()
            ->whereIn('id', $rows->pluck('employee_id')->filter())
            ->pluck('name', 'id');

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $labels[] = $names[$row->employee_id] ?? 'Unassigned';
            $data[] = (int) $row->aggregate;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * @return array{labels: list<string>, data: list<float>, team_average: float, days: int}
     */
    public function averageCalls(?User $user, ?string $periodKey): array
    {
        $period = $this->period($periodKey);
        $days = max(1, (int) $period['from']->diffInDays($period['until']) + 1);

        if (! $user) {
            return [
                'labels' => ['No completed calls'],
                'data' => [0.0],
                'team_average' => 0.0,
                'days' => $days,
            ];
        }

        $this->asUser($user);

        $rows = Visit::query()
            ->applyVisibility('Visit')
            ->where('visits.visit_status', 'completed')
            ->whereDate('visits.visit_date', '>=', $period['from']->toDateString())
            ->whereDate('visits.visit_date', '<=', $period['until']->toDateString())
            ->selectRaw('visits.employee_id as employee_id, count(*) as aggregate')
            ->groupBy('visits.employee_id')
            ->orderByDesc('aggregate')
            ->limit(8)
            ->get();

        $teamTotal = (int) Visit::query()
            ->applyVisibility('Visit')
            ->where('visits.visit_status', 'completed')
            ->whereDate('visits.visit_date', '>=', $period['from']->toDateString())
            ->whereDate('visits.visit_date', '<=', $period['until']->toDateString())
            ->count();

        $teamAverage = round($teamTotal / $days, 1);

        if ($rows->isEmpty()) {
            return [
                'labels' => ['No completed calls'],
                'data' => [0.0],
                'team_average' => $teamAverage,
                'days' => $days,
            ];
        }

        $names = User::query()
            ->whereIn('id', $rows->pluck('employee_id')->filter())
            ->pluck('name', 'id');

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $labels[] = $names[$row->employee_id] ?? 'Unassigned';
            $data[] = round(((int) $row->aggregate) / $days, 1);
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'team_average' => $teamAverage,
            'days' => $days,
        ];
    }

    /**
     * @param  class-string  $model
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $colors
     * @return array{labels: list<string>, data: list<int>, colors: list<string>}
     */
    private function statusSlices(
        ?User $user,
        ?string $periodKey,
        string $model,
        string $visibilityKey,
        string $dateColumn,
        string $statusColumn,
        array $labels,
        array $colors,
    ): array {
        $counts = [];

        if ($user) {
            $this->asUser($user);
            $period = $this->period($periodKey);

            $counts = $model::query()
                ->applyVisibility($visibilityKey)
                ->whereDate($dateColumn, '>=', $period['from']->toDateString())
                ->whereDate($dateColumn, '<=', $period['until']->toDateString())
                ->selectRaw($statusColumn.' as status_key, count(*) as aggregate')
                ->groupBy($statusColumn)
                ->get()
                ->mapWithKeys(fn (object $row): array => [(string) $row->status_key => (int) $row->aggregate])
                ->all();
        }

        $ordered = array_values(array_unique([
            ...array_keys($labels),
            ...array_keys($counts),
        ]));

        $sliceLabels = [];
        $data = [];
        $sliceColors = [];

        foreach ($ordered as $status) {
            $count = $counts[$status] ?? 0;

            if ($count === 0) {
                continue;
            }

            $sliceLabels[] = $labels[$status] ?? str((string) $status)->replace('_', ' ')->title()->toString();
            $data[] = $count;
            $sliceColors[] = $colors[$status] ?? '#64748b';
        }

        if ($data === []) {
            return [
                'labels' => ['No records'],
                'data' => [0],
                'colors' => ['#e5e7eb'],
            ];
        }

        return [
            'labels' => $sliceLabels,
            'data' => $data,
            'colors' => $sliceColors,
        ];
    }

    private function asUser(User $user): void
    {
        if (Auth::id() !== $user->id) {
            Auth::setUser($user);
        }
    }
}

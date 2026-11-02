<?php

namespace App\Support;

use App\Filament\Widgets\AverageCallsChart;
use App\Filament\Widgets\CallsByDayChart;
use App\Filament\Widgets\CallsByPersonChart;
use App\Filament\Widgets\CallStatusChart;
use App\Filament\Widgets\DcrStatusChart;
use App\Filament\Widgets\ExpenseByTypeChart;
use App\Filament\Widgets\PlanAndCallsChart;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class DashboardLayout
{
    /**
     * @return array<string, array{label: string, description: string, class: class-string<Widget>}>
     */
    public function catalog(): array
    {
        return [
            'calls_by_day' => [
                'label' => 'Calls by day',
                'description' => 'Completed, still open, and cancelled calls for each day.',
                'class' => CallsByDayChart::class,
            ],
            'call_status' => [
                'label' => 'Call status',
                'description' => 'How calls in the period finished: done, in progress, not started, or cancelled.',
                'class' => CallStatusChart::class,
            ],
            'dcr_status' => [
                'label' => 'DCR status',
                'description' => 'Daily call reports waiting, approved, or rejected.',
                'class' => DcrStatusChart::class,
            ],
            'plan_and_calls' => [
                'label' => 'Plan and calls',
                'description' => 'Tour days on the plan next to completed and open calls.',
                'class' => PlanAndCallsChart::class,
            ],
            'expenses' => [
                'label' => 'Field expenses',
                'description' => 'Claimed expense totals by type.',
                'class' => ExpenseByTypeChart::class,
            ],
            'calls_by_person' => [
                'label' => 'Calls by person',
                'description' => 'Completed calls for the people you can see.',
                'class' => CallsByPersonChart::class,
            ],
            'average_calls' => [
                'label' => 'Average calls',
                'description' => 'Completed calls per day for each person in the selected period.',
                'class' => AverageCallsChart::class,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(
            fn (array $item): string => $item['label'],
            $this->catalog()
        );
    }

    /**
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        return array_map(
            fn (array $item): string => $item['description'],
            $this->catalog()
        );
    }

    /**
     * @param  list<mixed>  $keys
     * @return list<string>
     */
    public function sanitize(array $keys): array
    {
        $allowed = array_keys($this->catalog());

        return array_values(array_filter(
            $allowed,
            fn (string $key): bool => in_array($key, $keys, true)
        ));
    }

    /**
     * @return list<string>
     */
    public function selected(?User $user): array
    {
        $stored = $user?->dashboard_widgets;

        if (! is_array($stored)) {
            return array_keys($this->catalog());
        }

        return $this->sanitize($stored);
    }

    public function enabled(string $key): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return in_array($key, $this->selected($user), true);
    }

    /**
     * @return list<class-string<Widget>>
     */
    public function chartWidgets(?User $user): array
    {
        $catalog = $this->catalog();

        return array_map(
            fn (string $key): string => $catalog[$key]['class'],
            $this->selected($user)
        );
    }
}

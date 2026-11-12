<?php

namespace App\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FieldCoverageStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $report = null;

    protected function getStats(): array
    {
        $report = $this->report ?? [];

        $coverageValue = ($report['coverage_percent'] ?? null) === null
            ? 'N/A'
            : ($report['covered_customers'] ?? 0).'/'.($report['eligible_customers'] ?? 0);
        $coverageHint = ($report['coverage_percent'] ?? null) === null
            ? 'No eligible customers'
            : ($report['coverage_percent'].'% unique coverage');

        $frequencyValue = ($report['frequency_percent'] ?? null) === null
            ? 'N/A'
            : ($report['credited_frequency_calls'] ?? 0).'/'.($report['target_calls'] ?? 0);
        $frequencyHint = ($report['frequency_percent'] ?? null) === null
            ? 'No call targets'
            : ($report['frequency_percent'].'% frequency');

        return [
            Stat::make('Unique coverage', $coverageValue)
                ->description($coverageHint)
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('primary'),
            Stat::make('Call frequency', $frequencyValue)
                ->description($frequencyHint)
                ->icon(Heroicon::OutlinedChartBar)
                ->color('success'),
            Stat::make('Joint participation', (string) ($report['joint_participations'] ?? 0))
                ->description('Approved joint visits')
                ->icon(Heroicon::OutlinedUsers)
                ->color('info'),
            Stat::make('Outside assignment', (string) ($report['unassigned_completed_calls'] ?? 0))
                ->description('Approved calls off roster')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('warning'),
        ];
    }
}

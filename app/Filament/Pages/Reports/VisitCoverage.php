<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\VisitCoverageExporter;
use App\Models\SalesTourPlanDetail;
use App\Services\Reports\VisitCoverageCalculator;
use App\Services\Reports\VisitCoverageQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class VisitCoverage extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = VisitCoverageExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Visit coverage';

    protected static ?string $title = 'Visit coverage';

    protected static ?int $navigationSort = 11;

    protected function visibilityPermissionKey(): ?string
    {
        return 'SalesTourPlanDetail';
    }

    protected function dateColumn(): ?string
    {
        return 'date';
    }

    protected function applyUserFilter(Builder $query, mixed $userId): Builder
    {
        if (blank($userId)) {
            return $query;
        }

        return $query->whereHas(
            'tourPlan',
            fn (Builder $planQuery): Builder => $planQuery->where('user_id', $userId)
        );
    }

    protected function getReportQuery(): Builder
    {
        return app(VisitCoverageQuery::class)->builder();
    }

    protected function getReportColumns(): array
    {
        $calculator = app(VisitCoverageCalculator::class);

        return [
            TextColumn::make('date')
                ->date()
                ->sortable(),
            TextColumn::make('tourPlan.user.name')
                ->label('Employee')
                ->searchable()
                ->sortable(),
            TextColumn::make('territory.name')
                ->label('Territory')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('visitType.name')
                ->label('Visit type')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('planned_accounts')
                ->label('Planned')
                ->state(fn (SalesTourPlanDetail $record): int => $calculator->forDetail($record)['planned']),
            TextColumn::make('completed_accounts')
                ->label('Completed')
                ->state(fn (SalesTourPlanDetail $record): int => $calculator->forDetail($record)['completed']),
            TextColumn::make('visits')
                ->label('Visits')
                ->state(fn (SalesTourPlanDetail $record): int => $calculator->forDetail($record)['visits']),
            TextColumn::make('coverage_percent')
                ->label('Coverage')
                ->state(fn (SalesTourPlanDetail $record): string => $calculator->forDetail($record)['percentage'].'%')
                ->badge()
                ->color(fn (SalesTourPlanDetail $record): string => match (true) {
                    $calculator->forDetail($record)['percentage'] >= 80 => 'success',
                    $calculator->forDetail($record)['percentage'] >= 40 => 'warning',
                    default => 'danger',
                }),
        ];
    }
}

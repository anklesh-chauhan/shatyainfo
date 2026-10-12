<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\LeaveLedgerExporter;
use App\Models\LeaveType;
use App\Services\Reports\LeaveLedgerQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class LeaveLedger extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = LeaveLedgerExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Leave ledger';

    protected static ?string $title = 'Leave ledger';

    protected static ?int $navigationSort = 22;

    protected function visibilityPermissionKey(): ?string
    {
        return null;
    }

    protected function dateColumn(): ?string
    {
        return 'date';
    }

    protected function userColumn(): ?string
    {
        return 'user_id';
    }

    protected function territoryColumn(): ?string
    {
        return null;
    }

    protected function defaultSortDirection(): string
    {
        return 'desc';
    }

    protected function getReportQuery(): Builder
    {
        return app(LeaveLedgerQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('leave_type_id')
                ->label('Leave type')
                ->options(fn (): array => LeaveType::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            SelectFilter::make('source')
                ->label('Transaction')
                ->options([
                    'Leave Applied' => 'Leave applied',
                    'Encashment' => 'Encashment',
                    'Lapse' => 'Lapse',
                ]),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('date')
                ->date()
                ->sortable(),
            TextColumn::make('employee_name')
                ->label('Employee')
                ->searchable()
                ->sortable(),
            TextColumn::make('leaveType.name')
                ->label('Leave type')
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('source')
                ->label('Transaction')
                ->badge(),
            TextColumn::make('amount')
                ->label('Days')
                ->numeric(decimalPlaces: 2)
                ->badge()
                ->color(fn (mixed $state): string => (float) $state > 0 ? 'success' : 'danger'),
        ];
    }
}

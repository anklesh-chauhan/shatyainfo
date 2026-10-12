<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\ExpenseSummaryExporter;
use App\Filament\Resources\SalesDcrs\SalesDcrResource;
use App\Models\SalesDcrExpense;
use App\Services\Reports\ExpenseSummaryQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpenseSummary extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = ExpenseSummaryExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyRupee;

    protected static ?string $navigationLabel = 'Expense summary';

    protected static ?string $title = 'Expense summary';

    protected static ?int $navigationSort = 12;

    protected function visibilityPermissionKey(): ?string
    {
        return null;
    }

    protected function dateColumn(): ?string
    {
        return 'created_at';
    }

    protected function defaultSortColumn(): ?string
    {
        return 'id';
    }

    protected function applyPeriodFilter(Builder $query, array $data): Builder
    {
        return $query->whereHas('salesDcr', function (Builder $dcrQuery) use ($data): void {
            $dcrQuery
                ->when(
                    $data['from'] ?? null,
                    fn (Builder $periodQuery, mixed $date): Builder => $periodQuery->whereDate('dcr_date', '>=', $date)
                )
                ->when(
                    $data['until'] ?? null,
                    fn (Builder $periodQuery, mixed $date): Builder => $periodQuery->whereDate('dcr_date', '<=', $date)
                );
        });
    }

    protected function applyUserFilter(Builder $query, mixed $userId): Builder
    {
        if (blank($userId)) {
            return $query;
        }

        return $query->whereHas(
            'salesDcr',
            fn (Builder $dcrQuery): Builder => $dcrQuery->where('user_id', $userId)
        );
    }

    protected function applyTerritoryFilter(Builder $query, mixed $territoryId): Builder
    {
        if (blank($territoryId)) {
            return $query;
        }

        return $query->whereHas(
            'salesDcr',
            fn (Builder $dcrQuery): Builder => $dcrQuery->where('territory_id', $territoryId)
        );
    }

    protected function getReportQuery(): Builder
    {
        return app(ExpenseSummaryQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('expense_type_id')
                ->label('Expense type')
                ->relationship('expenseType', 'name')
                ->searchable(),
            TernaryFilter::make('is_auto_calculated')
                ->label('Auto calculated'),
            SelectFilter::make('dcr_status')
                ->label('DCR status')
                ->options([
                    'draft' => 'Draft',
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query->when(
                        $data['value'] ?? null,
                        fn (Builder $statusQuery, mixed $status): Builder => $statusQuery->whereHas(
                            'salesDcr',
                            fn (Builder $dcrQuery): Builder => $dcrQuery->where('approval_status', $status)
                        )
                    );
                }),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('salesDcr.dcr_date')
                ->label('DCR date')
                ->date()
                ->sortable(),
            TextColumn::make('salesDcr.user.name')
                ->label('Employee')
                ->searchable(),
            TextColumn::make('salesDcr.territory.name')
                ->label('Territory')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('expenseType.name')
                ->label('Type')
                ->searchable(),
            TextColumn::make('amount')
                ->money('INR')
                ->sortable(),
            IconColumn::make('is_auto_calculated')
                ->label('Auto')
                ->boolean()
                ->toggleable(),
            TextColumn::make('salesDcr.approval_status')
                ->label('DCR status')
                ->badge()
                ->colors([
                    'gray' => 'draft',
                    'info' => 'pending',
                    'success' => 'approved',
                    'danger' => 'rejected',
                ]),
            TextColumn::make('salesDcr.total_expense_approved')
                ->label('DCR approved')
                ->money('INR')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('salesDcr.total_expense_rejected')
                ->label('DCR rejected')
                ->money('INR')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (SalesDcrExpense $record): ?string => $record->sales_dcr_id
                ? SalesDcrResource::getUrl('edit', ['record' => $record->sales_dcr_id])
                : null);
    }
}

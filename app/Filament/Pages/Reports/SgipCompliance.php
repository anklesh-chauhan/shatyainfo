<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\SgipComplianceExporter;
use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Models\SgipViolation;
use App\Services\Reports\SgipComplianceQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SgipCompliance extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = SgipComplianceExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'SGIP compliance';

    protected static ?string $title = 'SGIP compliance';

    protected static ?int $navigationSort = 14;

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
        return $query->whereHas('distribution', function (Builder $distributionQuery) use ($data): void {
            $distributionQuery
                ->when(
                    $data['from'] ?? null,
                    fn (Builder $periodQuery, mixed $date): Builder => $periodQuery->whereDate('visit_date', '>=', $date)
                )
                ->when(
                    $data['until'] ?? null,
                    fn (Builder $periodQuery, mixed $date): Builder => $periodQuery->whereDate('visit_date', '<=', $date)
                );
        });
    }

    protected function applyUserFilter(Builder $query, mixed $userId): Builder
    {
        if (blank($userId)) {
            return $query;
        }

        return $query->whereHas(
            'distribution',
            fn (Builder $distributionQuery): Builder => $distributionQuery->where('user_id', $userId)
        );
    }

    protected function applyTerritoryFilter(Builder $query, mixed $territoryId): Builder
    {
        if (blank($territoryId)) {
            return $query;
        }

        return $query->whereHas(
            'distribution',
            fn (Builder $distributionQuery): Builder => $distributionQuery->where('territory_id', $territoryId)
        );
    }

    protected function getReportQuery(): Builder
    {
        return app(SgipComplianceQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('violation_type')
                ->label('Violation')
                ->options([
                    'quantity' => 'Quantity',
                    'value' => 'Value',
                    'campaign_quota' => 'Campaign quota',
                ]),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('distribution.visit_date')
                ->label('Visit date')
                ->date()
                ->sortable(),
            TextColumn::make('distribution.doctor.name')
                ->label('Doctor')
                ->searchable(),
            TextColumn::make('distribution.user.name')
                ->label('Employee')
                ->searchable(),
            TextColumn::make('distribution.territory.name')
                ->label('Territory')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('distribution.marketingCampaign.name')
                ->label('Campaign')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('violation_type')
                ->label('Violation')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'quantity' => 'Quantity',
                    'value' => 'Value',
                    'campaign_quota' => 'Campaign quota',
                    default => (string) $state,
                })
                ->colors([
                    'warning' => 'quantity',
                    'info' => 'value',
                    'danger' => 'campaign_quota',
                ]),
            TextColumn::make('allowed_value')
                ->label('Allowed')
                ->numeric(decimalPlaces: 2)
                ->sortable(),
            TextColumn::make('actual_value')
                ->label('Actual')
                ->numeric(decimalPlaces: 2)
                ->sortable(),
            TextColumn::make('overage')
                ->label('Overage')
                ->state(fn (SgipViolation $record): float => (float) $record->actual_value - (float) $record->allowed_value)
                ->numeric(decimalPlaces: 2),
            TextColumn::make('limit.period')
                ->label('Limit period')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('distribution.approval_status')
                ->label('Status')
                ->badge()
                ->colors([
                    'gray' => 'draft',
                    'info' => 'submitted',
                    'success' => 'approved',
                ]),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (SgipViolation $record): ?string => $record->sgip_distribution_id
                ? SgipDistributionResource::getUrl('edit', ['record' => $record->sgip_distribution_id])
                : null);
    }
}

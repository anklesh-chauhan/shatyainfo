<?php

namespace App\Filament\Pages\Reports;

use App\Enums\ItemType;
use App\Filament\Exports\Reports\DoctorSampleLedgerExporter;
use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Models\SgipDistributionItem;
use App\Services\Reports\DoctorSampleLedgerQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DoctorSampleLedger extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = DoctorSampleLedgerExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Doctor sample ledger';

    protected static ?string $title = 'Doctor sample ledger';

    protected static ?int $navigationSort = 13;

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
        return app(DoctorSampleLedgerQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('item_type')
                ->label('Item type')
                ->options(ItemType::class)
                ->query(function (Builder $query, array $data): Builder {
                    return $query->when(
                        $data['value'] ?? null,
                        fn (Builder $itemQuery, mixed $type): Builder => $itemQuery->whereHas(
                            'item',
                            fn (Builder $masterQuery): Builder => $masterQuery->where('item_type', $type)
                        )
                    );
                }),
            SelectFilter::make('marketing_campaign_id')
                ->label('Campaign')
                ->relationship('distribution.marketingCampaign', 'name')
                ->searchable(),
            Filter::make('violations_only')
                ->label('Violations only')
                ->query(fn (Builder $query): Builder => $query->whereHas('distribution.violations')),
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
                ->searchable()
                ->sortable(),
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
            TextColumn::make('item.item_type')
                ->label('Item type')
                ->badge()
                ->toggleable(),
            TextColumn::make('item.item_name')
                ->label('Item')
                ->searchable(),
            TextColumn::make('quantity')
                ->numeric(decimalPlaces: 3)
                ->sortable(),
            TextColumn::make('total_value')
                ->label('Value')
                ->money('INR')
                ->sortable(),
            TextColumn::make('distribution.approval_status')
                ->label('Status')
                ->badge()
                ->colors([
                    'info' => 'submitted',
                    'success' => 'approved',
                ]),
            IconColumn::make('has_violations')
                ->label('Compliance')
                ->boolean()
                ->getStateUsing(fn (SgipDistributionItem $record): bool => $record->distribution?->violations->isNotEmpty() ?? false)
                ->trueIcon('heroicon-o-exclamation-triangle')
                ->falseIcon('heroicon-o-check-circle')
                ->trueColor('danger')
                ->falseColor('success'),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (SgipDistributionItem $record): ?string => $record->sgip_distribution_id
                ? SgipDistributionResource::getUrl('edit', ['record' => $record->sgip_distribution_id])
                : null);
    }
}

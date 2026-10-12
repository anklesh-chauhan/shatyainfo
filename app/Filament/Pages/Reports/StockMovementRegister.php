<?php

namespace App\Filament\Pages\Reports;

use App\Enums\ItemType;
use App\Filament\Exports\Reports\StockMovementRegisterExporter;
use App\Services\Reports\StockMovementRegisterQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class StockMovementRegister extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = StockMovementRegisterExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Stock movements';

    protected static ?string $title = 'Stock movements';

    protected static ?int $navigationSort = 15;

    protected function visibilityPermissionKey(): ?string
    {
        return 'InventoryMovement';
    }

    protected function dateColumn(): ?string
    {
        return 'movement_at';
    }

    protected function userColumn(): ?string
    {
        return null;
    }

    protected function territoryColumn(): ?string
    {
        return null;
    }

    protected function getReportQuery(): Builder
    {
        return app(StockMovementRegisterQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('location_master_id')
                ->label('Location')
                ->relationship('location', 'name')
                ->searchable(),
            SelectFilter::make('item_master_id')
                ->label('Item')
                ->relationship('item', 'item_name')
                ->searchable(),
            SelectFilter::make('movement_type')
                ->label('Movement')
                ->options([
                    'grn_receipt' => 'GRN receipt',
                    'adjustment_receipt' => 'Adjustment receipt',
                    'adjustment_issue' => 'Adjustment issue',
                    'adjustment_increase' => 'Adjustment increase',
                    'adjustment_decrease' => 'Adjustment decrease',
                    'transfer_in' => 'Transfer in',
                    'transfer_out' => 'Transfer out',
                    'audit_variance' => 'Audit variance',
                    'delivery_challan' => 'Delivery challan',
                    'sample_issue_out' => 'Sample issue out',
                    'sample_issue_in' => 'Sample issue in',
                    'sgip_distribution' => 'SGIP distribution',
                ]),
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
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('movement_at')
                ->label('Moved at')
                ->dateTime('d M Y, h:i A')
                ->sortable(),
            TextColumn::make('item.item_code')
                ->label('Code')
                ->searchable()
                ->sortable(),
            TextColumn::make('item.item_name')
                ->label('Item')
                ->searchable()
                ->sortable(),
            TextColumn::make('item.item_type')
                ->label('Item type')
                ->badge()
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('location.name')
                ->label('Location')
                ->searchable()
                ->sortable(),
            TextColumn::make('movement_type')
                ->label('Movement')
                ->badge()
                ->searchable(),
            TextColumn::make('quantity_in')
                ->label('In')
                ->numeric(decimalPlaces: 3)
                ->alignEnd(),
            TextColumn::make('quantity_out')
                ->label('Out')
                ->numeric(decimalPlaces: 3)
                ->alignEnd(),
            TextColumn::make('balance_after')
                ->label('Balance')
                ->numeric(decimalPlaces: 3)
                ->alignEnd()
                ->sortable(),
            TextColumn::make('remarks')
                ->limit(40)
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }
}

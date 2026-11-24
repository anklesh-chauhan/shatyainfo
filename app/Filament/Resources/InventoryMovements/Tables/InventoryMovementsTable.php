<?php

namespace App\Filament\Resources\InventoryMovements\Tables;

use App\Services\Inventory\StockLedger;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'item' => fn (BelongsTo $item) => $item->withTrashed(),
                'location' => fn (BelongsTo $location) => $location->withTrashed(),
            ]))
            ->columns([
                TextColumn::make('movement_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('item.item_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item.item_name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('movement_type')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity_in')
                    ->numeric(3)
                    ->alignEnd()
                    ->color('success'),
                TextColumn::make('quantity_out')
                    ->numeric(3)
                    ->alignEnd()
                    ->color('danger'),
                TextColumn::make('balance_after')
                    ->numeric(3)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('unit_cost')->numeric(4)->alignEnd()->toggleable(),
                TextColumn::make('total_value')->numeric(4)->alignEnd()->toggleable(),
                TextColumn::make('reference_id')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference_type')
                    ->formatStateUsing(fn (?string $state): string => class_basename((string) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remarks')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('item_master_id')
                    ->options(fn (): array => app(StockLedger::class)->catalog(auth()->user(), 'items')->orderBy('item_name')->pluck('item_name', 'id')->all())
                    ->label('Item')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('location_master_id')
                    ->options(fn (): array => app(StockLedger::class)->catalog(auth()->user(), 'locations')->orderBy('name')->pluck('name', 'id')->all())
                    ->label('Location')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('movement_type')
                    ->options(StockLedger::TYPES),
                Filter::make('movement_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_at', '<=', $date))),
            ])
            ->recordActions([])->toolbarActions([])
            ->defaultSort('movement_at', 'desc');
    }
}

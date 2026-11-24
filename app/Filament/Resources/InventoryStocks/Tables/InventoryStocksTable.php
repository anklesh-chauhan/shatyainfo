<?php

namespace App\Filament\Resources\InventoryStocks\Tables;

use App\Services\Inventory\StockRegister;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'item' => fn (BelongsTo $item) => $item->withTrashed(),
                'location' => fn (BelongsTo $location) => $location->withTrashed(),
            ]))
            ->columns([
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
                TextColumn::make('quantity_on_hand')
                    ->numeric(3)
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('quantity_reserved')
                    ->numeric(3)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('quantity_available')
                    ->numeric(3)
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('average_cost')
                    ->money('INR')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('last_movement_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('item_master_id')
                    ->options(fn (): array => app(StockRegister::class)->catalog(auth()->user(), 'items')->orderBy('item_name')->pluck('item_name', 'id')->all())
                    ->label('Item')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('location_master_id')
                    ->options(fn (): array => app(StockRegister::class)->catalog(auth()->user(), 'locations')->orderBy('name')->pluck('name', 'id')->all())
                    ->label('Location')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([])->toolbarActions([])
            ->defaultSort('last_movement_at', 'desc');
    }
}

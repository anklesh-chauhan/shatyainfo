<?php

namespace App\Filament\Resources\InventoryStocks\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventoryStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->relationship('item', 'item_name')
                    ->label('Item')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('location_master_id')
                    ->relationship('location', 'name')
                    ->label('Location')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('last_movement_at', 'desc');
    }
}

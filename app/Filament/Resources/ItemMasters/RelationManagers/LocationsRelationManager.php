<?php

namespace App\Filament\Resources\ItemMasters\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryStocks';

    protected static ?string $title = 'Stock by Location';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.location_code')
                    ->label('Code')
                    ->toggleable(),
                TextColumn::make('quantity_on_hand')
                    ->label('On Hand')
                    ->numeric(3)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('quantity_reserved')
                    ->label('Reserved')
                    ->numeric(3)
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('quantity_available')
                    ->label('Available')
                    ->numeric(3)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('average_cost')
                    ->money('INR')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('last_movement_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('last_movement_at', 'desc')
            ->emptyStateHeading('No stock recorded')
            ->emptyStateDescription('Receive stock via Purchase Order / GRN or post a stock adjustment.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}

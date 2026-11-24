<?php

namespace App\Filament\Resources\LocationMasters\RelationManagers;

use App\Services\Inventory\StockRegister;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryStocks';

    protected static ?string $title = 'Stock On Hand';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('inventory_stocks.id',
                app(StockRegister::class)->visible(auth()->user())->select('inventory_stocks.id'))
                ->with(['item' => fn (BelongsTo $item) => $item->withTrashed(), 'location' => fn (BelongsTo $location) => $location->withTrashed()]))
            ->columns([
                TextColumn::make('item.item_code')
                    ->label('Item Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item.item_name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
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
            ->emptyStateHeading('No stock at this location')
            ->emptyStateDescription('Receive stock via GRN or post a stock adjustment.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}

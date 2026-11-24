<?php

namespace App\Filament\Resources\InventoryStocks;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\InventoryStocks\Pages\ListInventoryStocks;
use App\Filament\Resources\InventoryStocks\Tables\InventoryStocksTable;
use App\Models\InventoryStock;
use App\Services\Inventory\StockRegister;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryStockResource extends BaseResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Purchasing';

    protected static ?string $model = InventoryStock::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Stock on Hand';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && app(StockRegister::class)->canRead(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return app(StockRegister::class)->visible(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return InventoryStocksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryStocks::route('/'),
        ];
    }
}

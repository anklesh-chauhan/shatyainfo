<?php

namespace App\Filament\Resources\InventoryStocks;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\InventoryStocks\Pages\ListInventoryStocks;
use App\Filament\Resources\InventoryStocks\Tables\InventoryStocksTable;
use App\Models\InventoryStock;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InventoryStockResource extends BaseResource
{
    protected static ?string $model = InventoryStock::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?string $navigationLabel = 'Stock On Hand';

    protected static ?int $navigationSort = 199;

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

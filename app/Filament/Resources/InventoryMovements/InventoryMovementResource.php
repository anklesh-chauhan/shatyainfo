<?php

namespace App\Filament\Resources\InventoryMovements;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Filament\Resources\InventoryMovements\Tables\InventoryMovementsTable;
use App\Models\InventoryMovement;
use App\Services\Inventory\StockLedger;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryMovementResource extends BaseResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Purchasing';

    protected static ?string $model = InventoryMovement::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Stock Ledger';

    protected static ?int $navigationSort = 80;

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && app(StockLedger::class)->canRead(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return app(StockLedger::class)->visible(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return InventoryMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryMovements::route('/'),
        ];
    }
}

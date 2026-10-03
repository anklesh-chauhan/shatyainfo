<?php

namespace App\Filament\Resources\CustomerPrices;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\CustomerPrices\Pages\CreateCustomerPrice;
use App\Filament\Resources\CustomerPrices\Pages\EditCustomerPrice;
use App\Filament\Resources\CustomerPrices\Pages\ListCustomerPrices;
use App\Filament\Resources\CustomerPrices\Schemas\CustomerPriceForm;
use App\Filament\Resources\CustomerPrices\Tables\CustomerPricesTable;
use App\Models\CustomerPrice;
use App\Traits\HasSafeGlobalSearch;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CustomerPriceResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = CustomerPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 190;

    protected static ?string $navigationLabel = 'Price & Discounts';

    protected static ?string $recordTitleAttribute = null;

    protected static array $globalSearchRelations = [
        'customer.name',
        'item.item_name',
        'item.item_code',
        'item.sku',
    ];

    public static function form(Schema $schema): Schema
    {
        return CustomerPriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerPricesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerPrices::route('/'),
            'create' => CreateCustomerPrice::route('/create'),
            'edit' => EditCustomerPrice::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\ShippingMethods;

use App\Filament\Clusters\GlobalConfiguration\LeadConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ShippingMethods\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethods\Pages\ListShippingMethods;
use App\Filament\Resources\ShippingMethods\Schemas\ShippingMethodForm;
use App\Filament\Resources\ShippingMethods\Tables\ShippingMethodsTable;
use App\Models\ShippingMethod;
use App\Traits\HasSafeGlobalSearch;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShippingMethodResource extends BaseResource
{
    protected static ?string $cluster = LeadConfigurationCluster::class;

    use HasSafeGlobalSearch;

    protected static ?string $model = ShippingMethod::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 150;

    public static function form(Schema $schema): Schema
    {
        return ShippingMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShippingMethodsTable::configure($table);
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
            'index' => ListShippingMethods::route('/'),
            'create' => CreateShippingMethod::route('/create'),
            'edit' => EditShippingMethod::route('/{record}/edit'),
        ];
    }
}

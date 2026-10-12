<?php

namespace App\Filament\Resources\DeliveryChallans;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\DeliveryChallans\Pages\CreateDeliveryChallan;
use App\Filament\Resources\DeliveryChallans\Pages\EditDeliveryChallan;
use App\Filament\Resources\DeliveryChallans\Pages\ListDeliveryChallans;
use App\Filament\Resources\DeliveryChallans\Schemas\DeliveryChallanForm;
use App\Filament\Resources\DeliveryChallans\Tables\DeliveryChallansTable;
use App\Models\DeliveryChallan;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeliveryChallanResource extends BaseResource
{
    protected static ?string $model = DeliveryChallan::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?string $navigationLabel = 'Delivery Challans';

    protected static ?int $navigationSort = 195;

    public static function form(Schema $schema): Schema
    {
        return DeliveryChallanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryChallansTable::configure($table)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryChallans::route('/'),
            'create' => CreateDeliveryChallan::route('/create'),
            'edit' => EditDeliveryChallan::route('/{record}/edit'),
        ];
    }
}

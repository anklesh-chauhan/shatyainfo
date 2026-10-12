<?php

namespace App\Filament\Resources\ItemMasters;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ItemMasterResource\RelationManagers;
use App\Filament\Resources\ItemMasters\Pages\CreateItemMaster;
use App\Filament\Resources\ItemMasters\Pages\EditItemMaster;
use App\Filament\Resources\ItemMasters\Pages\ListItemMasters;
use App\Filament\Resources\ItemMasters\RelationManagers\LeadsRelationManager;
use App\Filament\Resources\ItemMasters\RelationManagers\LocationsRelationManager;
use App\Filament\Resources\ItemMasters\RelationManagers\SuppliersRelationManager;
use App\Filament\Resources\ItemMasters\RelationManagers\VariantsRelationManager;
use App\Models\ItemMaster;
use App\Traits\HasSafeGlobalSearch;
use App\Traits\ItemMasterTableTrait;
use App\Traits\ItemMasterTrait;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class ItemMasterResource extends BaseResource
{
    use HasSafeGlobalSearch;
    use ItemMasterTableTrait;
    use ItemMasterTrait;

    protected static ?string $model = ItemMaster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?int $navigationSort = 198;

    protected static ?string $navigationLabel = 'Item Master';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::getItemMasterTraitField(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...self::getItemMasterTableTrait(),
            ])
            ->filters([
                // Use the new filter here
                self::getItemTypeFilter(),

                // Your existing filter
                Filter::make('expiry_date')->label('Expired Items')
                    ->query(fn ($query) => $query->whereDate('expiry_date', '<', now())),
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LocationsRelationManager::class,
            LeadsRelationManager::class,
            SuppliersRelationManager::class,
            VariantsRelationManager::class,
            // RelationManagers\SalesOrdersRelationManager::class,
            // RelationManagers\PurchaseOrdersRelationManager::class,
            // RelationManagers\PurchaseInvoicesRelationManager::class,
            // RelationManagers\SalesInvoicesRelationManager::class,
            // RelationManagers\StockTransfersRelationManager::class,
            // RelationManagers\StockAdjustmentsRelationManager::class,
            // RelationManagers\ItemStockRelationManager::class,
            // RelationManagers\ItemStockHistoryRelationManager::class,
            // RelationManagers\ItemStockTransferRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemMasters::route('/'),
            'create' => CreateItemMaster::route('/create'),
            'edit' => EditItemMaster::route('/{record}/edit'),
        ];
    }

    /**
     * Recursively format locations with indentation for sublocations.
     */
    public static function formatLocations($locations, $prefix = '')
    {
        $options = [];
        foreach ($locations as $location) {
            $options[$location->id] = $prefix.$location->name;
            if ($location->subLocations->count()) {
                $options += self::formatLocations($location->subLocations, $prefix.'-- ');
            }
        }

        return $options;
    }

    public static function formatCategories($categories, $prefix = '')
    {
        $options = [];
        foreach ($categories as $category) {
            $options[$category->id] = $prefix.$category->name;
            if ($category->subCategories->count()) {
                $options += self::formatCategories($category->subCategories, $prefix.'-- ');
            }
        }

        return $options;
    }
}

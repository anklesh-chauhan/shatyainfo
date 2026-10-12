<?php

namespace App\Traits;

use App\Enums\ItemType;
use App\Filament\Resources\ItemMasters\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\ItemMaster;
use App\Models\NumberSeries;
use App\Models\PackagingType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

trait ItemMasterTrait
{
    /**
     * Get common form fields for SalesDocument.
     */
    public static function getItemMasterTraitField($livewire = null): array
    {
        $isVariant = $livewire instanceof VariantsRelationManager;
        $parentItem = $isVariant ? $livewire->ownerRecord : null;

        return [
            Grid::make(3)
                ->schema([
                    TextInput::make('item_code')
                        ->label('Item Code')
                        ->default(fn () => NumberSeries::getNextNumber(ItemMaster::class))
                        ->disabled()
                        ->dehydrated(true),

                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn () => Category::whereNull('parent_id')->with('subCategories')->get()->pipe(fn ($categories) => Category::formatCategories($categories)))
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make('sku')
                        ->label('SKU'),

                    TextInput::make('item_name')
                        ->label('Item Name')
                        ->required(! $isVariant) // Required for main item only
                        ->default(fn () => $isVariant ? $parentItem->item_name : null) // Inherit name from parent
                        ->readOnly($isVariant)
                        ->dehydrateStateUsing(fn ($state) => $state ?? ($parentItem->item_name ?? null)),

                    Select::make('item_type')
                        ->label('Item Type')
                        ->options(ItemType::class)
                        ->helperText('Set this for samples, gifts, and promotional inputs used in field distribution.'),

                    TextInput::make('variant_name')
                        ->label('Variant Name')
                        ->required($isVariant),

                    TextInput::make('hsn_code')
                        ->label('HSN/SAC Code'),

                    Select::make('unit_of_measurement_id')
                        ->relationship('unitOfMeasurement', 'name')
                        ->label('Unit of Measurement'),

                    Select::make('taxes')
                        ->label('Applicable Taxes')
                        ->relationship('taxes', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->required(false),

                    Select::make('item_brand_id')
                        ->relationship('brand', 'name')
                        ->label('Brand'),

                    Textarea::make('description')
                        ->label('Description'),

                    Section::make('More Details')
                        ->schema([
                            Grid::make(4)
                                ->schema([
                                    TextInput::make('purchase_price')
                                        ->label('Purchase Price')
                                        ->numeric(),
                                    TextInput::make('selling_price')
                                        ->label('Selling Price')
                                        ->numeric(),
                                    TextInput::make('discount')
                                        ->label('Discount (%)')
                                        ->numeric(),
                                    TextInput::make('opening_stock')
                                        ->label('Opening Stock')
                                        ->numeric(),
                                    TextInput::make('minimum_stock_level')
                                        ->label('Minimum Stock Level')
                                        ->numeric(),
                                    TextInput::make('reorder_quantity')
                                        ->label('Reorder Quantity')
                                        ->numeric(),
                                    TextInput::make('lead_time')
                                        ->label('Lead Time')
                                        ->numeric(),
                                    TextInput::make('barcode')
                                        ->label('Barcode'),
                                    DatePicker::make('expiry_date')
                                        ->label('Expiry Date'),
                                    Select::make('packaging_type_id')
                                        ->label('Packaging Type')
                                        ->options(PackagingType::pluck('name', 'id'))
                                        ->searchable()
                                        ->preload()
                                        ->nullable(),
                                    TextInput::make('per_packing_qty')
                                        ->label('Per Packing Quantity')
                                        ->numeric(),
                                ]),
                        ])
                        ->columnSpanFull()
                        ->collapsed(),
                ])->columnSpanFull(),

        ];
    }
}

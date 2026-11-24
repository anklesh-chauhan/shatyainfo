<?php

namespace App\Filament\Resources\ItemMasters\Schemas;

use App\Enums\ItemType;
use App\Models\ItemMaster;
use App\Services\Inventory\ItemRegister;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemRegisterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    public static function fields(bool $variant = false): array
    {
        $fields = [Hidden::make('revision'), TextInput::make('item_code')->disabled()->dehydrated(false),
            TextInput::make('item_name')->required()->maxLength(255)->readOnly($variant),
            TextInput::make('variant_name')->maxLength(255)->required($variant),
            TextInput::make('sku')->maxLength(255), Select::make('item_type')->options(ItemType::class),
            self::selection('category_id', 'Category', 'categories', 'category')->required(),
            self::selection('item_brand_id', 'Brand', 'brands', 'brand'),
            self::selection('unit_of_measurement_id', 'Unit of Measurement', 'units', 'unitOfMeasurement'),
            self::selection('packaging_type_id', 'Packaging Type', 'packaging', 'packagingType'),
            Select::make('tax_ids')->label('Applicable Taxes')->multiple()->searchable()->options(fn (?ItemMaster $record): array => app(ItemRegister::class)->catalog(auth()->user(), 'taxes')->pluck('name', 'id')->union($record?->taxes?->pluck('name', 'id') ?? [])->all()),
            TextInput::make('hsn_code')->maxLength(255), TextInput::make('barcode')->maxLength(255),
            DatePicker::make('expiry_date'), Textarea::make('description')->maxLength(10000),
        ];
        foreach (['purchase_price', 'selling_price'] as $field) {
            $fields[] = TextInput::make($field)->numeric()->minValue(0)->maxValue(99999999.99)->rule('decimal:0,2');
        }
        $fields[] = TextInput::make('discount')->numeric()->integer()->minValue(0)->maxValue(100);
        foreach (['opening_stock', 'minimum_stock_level', 'reorder_quantity', 'lead_time', 'per_packing_qty'] as $field) {
            $fields[] = TextInput::make($field)->numeric()->integer()->minValue(0)->maxValue(2147483647);
        }

        return [Section::make('Item Details')->columns(3)->schema($fields)->columnSpanFull()
            ->disabled(fn (?ItemMaster $record): bool => $record !== null && ! app(ItemRegister::class)->canChange(auth()->user(), $record))];
    }

    private static function selection(string $field, string $label, string $type, string $relation): Select
    {
        return Select::make($field)->label($label)->searchable()->options(function (?ItemMaster $record) use ($type, $relation): array {
            $options = app(ItemRegister::class)->catalog(auth()->user(), $type)->orderBy('name')->pluck('name', 'id');
            $current = $record?->{$relation};
            if ($current !== null) {
                $options->put($current->id, $current->name);
            }

            return $options->all();
        });
    }
}

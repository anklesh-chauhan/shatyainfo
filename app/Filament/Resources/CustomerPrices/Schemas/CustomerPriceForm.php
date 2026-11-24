<?php

namespace App\Filament\Resources\CustomerPrices\Schemas;

use App\Models\CustomerPrice;
use App\Models\ItemMaster;
use App\Services\Field\FieldCustomerPriceService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class CustomerPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'name', modifyQueryUsing: fn (Builder $query): Builder => app(FieldCustomerPriceService::class)
                        ->customers(auth()->user(), $query))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                Select::make('item_master_id')
                    ->label('Item / Variant')
                    ->relationship('item', 'item_name', modifyQueryUsing: fn (Builder $query): Builder => $query->with('parent'))
                    ->getOptionLabelFromRecordUsing(fn (ItemMaster $record): string => app(FieldCustomerPriceService::class)->itemLabel($record))
                    ->searchable(['item_name', 'item_code', 'sku', 'variant_name'])
                    ->preload()
                    ->required()
                    ->unique(table: CustomerPrice::class, ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->withoutTrashed()->where('customer_id', $get('customer_id'))),

                TextInput::make('price')
                    ->label('Price')
                    ->numeric()
                    ->required(fn (?CustomerPrice $record): bool => $record === null || $record->price !== null)
                    ->minValue(0)
                    ->maxValue(9999999999999.99)
                    ->rules(['decimal:0,2'])
                    ->inputMode('decimal'),

                TextInput::make('discount')
                    ->label('Discount (%)')
                    ->numeric()
                    ->required()
                    ->default(0)
                    ->suffix('%')
                    ->inputMode('decimal')
                    ->minValue(0)
                    ->maxValue(100)
                    ->rules(['decimal:0,2']),
            ]);
    }
}

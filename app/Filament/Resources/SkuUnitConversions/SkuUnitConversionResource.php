<?php

namespace App\Filament\Resources\SkuUnitConversions;

use App\Filament\Resources\SkuUnitConversions\Pages\ManageSkuUnitConversions;
use App\Models\SkuUnitConversion;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class SkuUnitConversionResource extends Resource
{
    protected static ?string $model = SkuUnitConversion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('Manage:SkuUnitConversion') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('item_master_id')->relationship('itemMaster', 'item_name')->searchable()->required()->disabledOn('edit'),
                TextInput::make('unit')->label('Unit code (box, strip, tablet)')->required()->maxLength(30)->regex('/^[a-z][a-z0-9_]*$/')->disabledOn('edit')
                    ->rules(fn ($get, $record) => [Rule::unique('sku_unit_conversions', 'unit')->where('item_master_id', $get('item_master_id'))->ignore($record?->id)]),
                TextInput::make('base_units')->label('Base units per selected unit')->numeric()->minValue(0.000001)->maxValue(999999999999)->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('itemMaster.item_name')->searchable(),
                TextColumn::make('unit'),
                TextColumn::make('base_units')->numeric(decimalPlaces: 6),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSkuUnitConversions::route('/'),
        ];
    }
}

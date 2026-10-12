<?php

namespace App\Filament\Resources\ItemMeasurementUnits;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\ItemConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ItemMeasurementUnits\Pages\CreateItemMeasurementUnit;
use App\Filament\Resources\ItemMeasurementUnits\Pages\EditItemMeasurementUnit;
use App\Filament\Resources\ItemMeasurementUnits\Pages\ListItemMeasurementUnits;
use App\Models\ItemMeasurementUnit;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemMeasurementUnitResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = ItemMeasurementUnit::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $cluster = ItemConfigurationCluster::class;

    protected static ?int $navigationSort = 1003;

    protected static ?string $navigationLabel = 'Item Measurement Unit';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('item_master_id')
                    ->required()
                    ->numeric(),
                TextInput::make('unit_of_measurement_id')
                    ->required()
                    ->numeric(),
                TextInput::make('conversion_rate')
                    ->required()
                    ->numeric()
                    ->default(1.00),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item_master_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_of_measurement_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('conversion_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItemMeasurementUnits::route('/'),
            'create' => CreateItemMeasurementUnit::route('/create'),
            'edit' => EditItemMeasurementUnit::route('/{record}/edit'),
        ];
    }
}

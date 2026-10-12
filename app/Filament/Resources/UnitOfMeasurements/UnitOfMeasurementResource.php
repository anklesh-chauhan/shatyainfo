<?php

namespace App\Filament\Resources\UnitOfMeasurements;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\ItemConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\UnitOfMeasurements\Pages\CreateUnitOfMeasurement;
use App\Filament\Resources\UnitOfMeasurements\Pages\EditUnitOfMeasurement;
use App\Filament\Resources\UnitOfMeasurements\Pages\ListUnitOfMeasurements;
use App\Models\UnitOfMeasurement;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitOfMeasurementResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = UnitOfMeasurement::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $cluster = ItemConfigurationCluster::class;

    protected static ?int $navigationSort = 1003;

    protected static ?string $navigationLabel = 'Unit of Measurement';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
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
            'index' => ListUnitOfMeasurements::route('/'),
            'create' => CreateUnitOfMeasurement::route('/create'),
            'edit' => EditUnitOfMeasurement::route('/{record}/edit'),
        ];
    }
}

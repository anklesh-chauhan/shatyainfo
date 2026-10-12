<?php

namespace App\Filament\Resources\NumberSeries;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\NumberSeries\Pages\CreateNumberSeries;
use App\Filament\Resources\NumberSeries\Pages\EditNumberSeries;
use App\Filament\Resources\NumberSeries\Pages\ListNumberSeries;
use App\Helpers\ModelHelper;
use App\Models\AccountMaster;
use App\Models\NumberSeries;
use App\Models\TypeMaster;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class NumberSeriesResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = NumberSeries::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Number Series';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('model_type')
                    ->label('Number Series Type')
                    ->options(ModelHelper::getModelOptions()) // Dynamic Model Names
                    ->preload()
                    ->searchable()
                    ->required(),
                Grid::make(3)
                    ->schema([
                        TextInput::make('Prefix')
                            ->maxLength(255),
                        TextInput::make('next_number')
                            ->required()
                            ->numeric()
                            ->default(0),
                        TextInput::make('Suffix')
                            ->maxLength(255),
                    ]),
                Select::make('type_master_id')
                    ->label('Module Type')
                    ->helperText('Select the module type for this number series.')
                    ->options(
                        TypeMaster::query()
                            ->where('typeable_type', AccountMaster::class) // Filter for Address types
                            ->pluck('name', 'id')
                    )
                    ->preload()
                    ->searchable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('model_type')
                    ->label('Module')
                    ->searchable()
                    ->formatStateUsing(fn ($state) => class_basename($state)),
                TextInputColumn::make('Prefix')
                    ->searchable(),
                TextInputColumn::make('next_number')
                    ->sortable(),
                TextInputColumn::make('Suffix')
                    ->searchable(),
                TextColumn::make('typeMaster.name') // Updated to match relationship name
                    ->label('Module Type')
                    ->sortable()
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
            'index' => ListNumberSeries::route('/'),
            'create' => CreateNumberSeries::route('/create'),
            'edit' => EditNumberSeries::route('/{record}/edit'),
        ];
    }
}

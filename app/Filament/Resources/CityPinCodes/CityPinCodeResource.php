<?php

namespace App\Filament\Resources\CityPinCodes;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\AddressConfigurationCluster;
use App\Filament\Exports\CityPinCodeExporter;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\CityPinCodes\Pages\CreateCityPinCode;
use App\Filament\Resources\CityPinCodes\Pages\EditCityPinCode;
use App\Filament\Resources\CityPinCodes\Pages\ListCityPinCodes;
use App\Models\City;
use App\Models\CityPinCode;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CityPinCodeResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = CityPinCode::class;

    protected static ?string $cluster = AddressConfigurationCluster::class;

    protected static ?string $navigationLabel = 'Area Town';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Location Information')
                    ->description('Select city and related details')
                    ->icon('heroicon-o-map')
                    ->schema([
                        Grid::make(5)->schema([
                            Select::make('city_id')
                                ->label('City')
                                ->relationship('city', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (callable $set, $state) {
                                    if ($city = City::find($state)) {
                                        $set('state_id', $city->state_id);
                                        if ($city->state) {
                                            $set('country_id', $city->state->country_id);
                                        }
                                    }
                                }),

                            Select::make('state_id')
                                ->label('State')
                                ->relationship('state', 'name')
                                ->disabled()
                                ->dehydrated(false),

                            Select::make('country_id')
                                ->label('Country')
                                ->relationship('country', 'name')
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('pin_code')
                                ->label('PIN Code')
                                ->required()
                                ->maxLength(10)
                                ->placeholder('380001'),

                            TextInput::make('area_town')
                                ->label('Area / Town')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Maninagar, Satellite, etc.'),
                        ]),
                    ])->columnSpanFull(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('area_town')
                    ->label('Area / Town')
                    ->sortable()
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('pin_code')
                    ->label('PIN Code')
                    ->sortable()
                    ->searchable()
                    ->copyable(),

                TextColumn::make('city.name')
                    ->label('City')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('state.name')
                    ->label('State')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('country.name')
                    ->label('Country')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label('City')
                    ->relationship('city', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('state_id')
                    ->label('State')
                    ->relationship('state', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('country_id')
                    ->label('Country')
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload(),

            ])
            ->headerActions([
                // Button to export all/filtered records
                ExportAction::make()
                    ->exporter(CityPinCodeExporter::class)
                    ->label('Export'),
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
            'index' => ListCityPinCodes::route('/'),
            'create' => CreateCityPinCode::route('/create'),
            'edit' => EditCityPinCode::route('/{record}/edit'),
        ];
    }
}

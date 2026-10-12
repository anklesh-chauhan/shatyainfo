<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\AddressConfigurationCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\Cities\Pages\ListCities;
use App\Filament\Resources\Cities\RelationManagers\AreaTownRelationManager;
use App\Models\City;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CityResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = City::class;

    protected static ?string $cluster = AddressConfigurationCluster::class;

    protected static ?string $navigationLabel = 'City Master';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Information')
                    ->description('Enter city details')
                    ->icon('heroicon-o-building-office')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('state_id')
                                ->label('State')
                                ->relationship('state', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('country_id', null)),

                            Select::make('country_id')
                                ->label('Country')
                                ->relationship('country', 'name')
                                ->searchable()
                                ->preload()
                                ->required(),

                            TextInput::make('name')
                                ->label('City Name')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('Ahmedabad, Surat, etc.'),
                        ]),

                        Grid::make(3)->schema([
                            Select::make('city_class_id')
                                ->label('City Class')
                                ->relationship('cityClass', 'name')
                                ->searchable()
                                ->preload(),

                            Toggle::make('is_hill_station')
                                ->label('Is Hill Station?')
                                ->default(false)
                                ->inline(false),
                        ]),
                    ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('City Name')
                    ->sortable()
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('state.name')
                    ->label('State')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('country.name')
                    ->label('Country')
                    ->sortable()
                    ->searchable(),

                IconColumn::make('is_hill_station')
                    ->label('Hill Station')
                    ->boolean()
                    ->trueIcon('heroicon-o-sun')
                    ->falseIcon('heroicon-o-building-office-2')
                    ->trueColor('amber')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->tooltip(fn (bool $state): string => $state ? 'Hill Station' : 'Plains Area')
                    ->toggleable(),

                TextColumn::make('cityClass.name')
                    ->label('City Class')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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

                SelectFilter::make('city_class_id')
                    ->label('City Class')
                    ->relationship('cityClass', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('is_hill_station')
                    ->label('Hill Station')
                    ->form([
                        Toggle::make('is_hill_station')
                            ->label('Only Hill Stations'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['is_hill_station'] ?? false,
                        fn (Builder $query) => $query->where('is_hill_station', true)
                    )
                    ),
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
            AreaTownRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCities::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }
}

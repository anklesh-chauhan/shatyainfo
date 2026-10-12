<?php

namespace App\Filament\Resources\LocationMasters;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\AccountMasters\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\LocationMasters\Pages\CreateLocationMaster;
use App\Filament\Resources\LocationMasters\Pages\EditLocationMaster;
use App\Filament\Resources\LocationMasters\Pages\ListLocationMasters;
use App\Filament\Resources\LocationMasters\Pages\ViewLocationMaster;
use App\Filament\Resources\LocationMasters\RelationManagers\ItemsRelationManager;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\TypeMaster;
use App\Traits\AddressDetailsTrait;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class LocationMasterResource extends BaseResource
{
    use AddressDetailsTrait, HasSafeGlobalSearch;

    protected static ?string $model = LocationMaster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|\UnitEnum|null $navigationGroup = 'Contacts & Companies';

    protected static ?int $navigationSort = 200;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Location Name')->required(),
                TextInput::make('location_code')
                    ->label('Location Code')
                    ->default(fn () => NumberSeries::getNextNumber(LocationMaster::class))
                    ->disabled()
                    ->dehydrated(true),

                Select::make('parent_id')
                    ->label('Parent Location')
                    ->options(LocationMaster::pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Textarea::make('description')->label('Description'),

                Select::make('parent_type_id')
                    ->label('Location Type')
                    ->searchable()
                    ->options(
                        TypeMaster::whereNull('parent_id')
                            ->where('typeable_type', LocationMaster::class)
                            ->pluck('name', 'id')
                    )
                    ->required()
                    ->live()
                    ->afterStateHydrated(function (callable $set, callable $get) {
                        $typeId = $get('typeable_id');

                        if (! $typeId) {
                            return;
                        }

                        $type = TypeMaster::find($typeId);

                        $set('parent_type_id', $type?->parent_id ?? $typeId);
                    })
                    ->afterStateUpdated(function (callable $set, $state) {

                        $hasChildren = TypeMaster::where('parent_id', $state)->exists();

                        if (! $hasChildren) {
                            // No sub-types → store parent directly
                            $set('typeable_id', $state);
                        } else {
                            $set('typeable_id', null);
                        }
                    }),

                Select::make('typeable_id')
                    ->label('Location Sub Type')
                    ->searchable()
                    ->options(fn (Get $get) => TypeMaster::where('parent_id', $get('parent_type_id'))
                        ->pluck('name', 'id')
                    )
                    ->visible(fn (Get $get) => filled($get('parent_type_id')) &&
                        TypeMaster::where('parent_id', $get('parent_type_id'))->exists()
                    )
                    ->required(fn (Get $get) => TypeMaster::where('parent_id', $get('parent_type_id'))->exists()
                    )
                    ->live(),

                Select::make('contactable_id')
                    ->label('Contact Detail')
                    ->relationship('contactDetail', 'full_name')
                    ->searchable(),

                Select::make('territories')
                    ->relationship(name: 'territories', titleAttribute: 'name')
                    ->multiple() // This enables the many-to-many logic
                    ->preload()   // Useful if the territory list isn't massive
                    ->searchable(),

                TextInput::make('latitude')
                    ->label('Latitude')
                    ->numeric()
                    ->mask('99.9999999'),

                TextInput::make('longitude')
                    ->label('Longitude')
                    ->numeric()
                    ->mask('99.9999999'),

                FileUpload::make('image')->label('Image'),

                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Location Name')->sortable()->searchable(),
                TextColumn::make('location_code')->label('Code')->sortable()->searchable(),
                // Show Type Name from Morph Relation
                TextColumn::make('typeable.name')
                    ->label('Type')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('parentLocation.name')
                    ->label('Parent Location')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('latitude')->label('Latitude')->sortable(),
                TextColumn::make('longitude')->label('Longitude')->sortable(),
                ImageColumn::make('image')->label('Image'),
                TextColumn::make('is_active')->label('Active')->badge(),
            ])
            ->filters([
                Filter::make('is_active')->label('Active Locations')
                    ->query(fn ($query) => $query->where('is_active', true)),
            ])
            ->recordActions([
                ViewAction::make(),
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
            ItemsRelationManager::class,
            AddressesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocationMasters::route('/'),
            'create' => CreateLocationMaster::route('/create'),
            'edit' => EditLocationMaster::route('/{record}/edit'),
            'view' => ViewLocationMaster::route('/{record}'),
        ];
    }
}

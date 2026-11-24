<?php

namespace App\Filament\Resources\LocationMasters;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\LocationMasters\Pages\CreateLocationMaster;
use App\Filament\Resources\LocationMasters\Pages\EditLocationMaster;
use App\Filament\Resources\LocationMasters\Pages\ListLocationMasters;
use App\Filament\Resources\LocationMasters\Pages\ViewLocationMaster;
use App\Filament\Resources\LocationMasters\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\LocationMasters\RelationManagers\ItemsRelationManager;
use App\Models\LocationMaster;
use App\Models\TypeMaster;
use App\Services\Inventory\LocationRegister;
use App\Traits\AddressDetailsTrait;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class LocationMasterResource extends BaseResource
{
    protected static ?string $navigationLabel = 'Locations';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Purchasing';

    use AddressDetailsTrait, HasSafeGlobalSearch;

    protected static ?string $model = LocationMaster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && app(LocationRegister::class)->canRead(auth()->user());
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && app(LocationRegister::class)->canRead(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        return app(LocationRegister::class)->visible(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'),
            TextInput::make('name')->label('Location Name')->required()->maxLength(255),
            TextInput::make('location_code')->label('Location Code')->disabled()->dehydrated(false),
            Select::make('parent_id')->label('Parent Location')->nullable()->searchable()
                ->options(fn (?LocationMaster $record): array => self::formOptions('parents', $record)),
            Textarea::make('description')->maxLength(255),
            Select::make('parent_type_id')->label('Location Type')->dehydrated(false)->searchable()->required()->live()
                ->options(fn (?LocationMaster $record): array => self::formOptions('types', $record))
                ->afterStateHydrated(function (callable $set, callable $get): void {
                    $type = TypeMaster::query()->find($get('typeable_id'));
                    $set('parent_type_id', $type?->parent_id ?? $type?->id);
                })
                ->afterStateUpdated(function (callable $set, mixed $state): void {
                    $set('typeable_id', TypeMaster::query()->where('typeable_type', LocationMaster::class)->where('parent_id', $state)->exists() ? null : $state);
                }),
            Select::make('typeable_id')->label('Location Sub Type')->searchable()
                ->options(fn (Get $get, ?LocationMaster $record): array => self::formOptions('subtypes', $record, $get('parent_type_id')))
                ->visible(fn (Get $get): bool => filled($get('parent_type_id')) && TypeMaster::query()->where('typeable_type', LocationMaster::class)->where('parent_id', $get('parent_type_id'))->exists())
                ->dehydrated(true)->dehydratedWhenHidden()->required(),
            Select::make('contact_id')->label('Contact Detail')->searchable()
                ->options(fn (?LocationMaster $record): array => self::formOptions('contacts', $record)),
            Select::make('territory_ids')->label('Territories')->multiple()->searchable()
                ->options(fn (?LocationMaster $record): array => self::formOptions('territories', $record)),
            TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
            TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
            FileUpload::make('image')->image()->maxSize(2048)->storeFiles(false),
            Toggle::make('is_active')->label('Active')->default(true),
        ])->disabled(fn (?LocationMaster $record): bool => $record !== null && ! app(LocationRegister::class)->canChange(auth()->user(), $record));
    }

    public static function formOptions(string $kind, ?LocationMaster $record, ?int $parentType = null): array
    {
        $locations = app(LocationRegister::class);
        $query = $locations->catalog(auth()->user(), $kind, $record?->id, $parentType);
        if ($kind === 'subtypes' && ! TypeMaster::query()->where('typeable_type', LocationMaster::class)->where('parent_id', $parentType)->exists()) {
            $query = $locations->catalog(auth()->user(), 'types')->whereKey($parentType);
        }
        $options = $query->get()->pluck($kind === 'contacts' ? 'full_name' : 'name', 'id')->all();
        if ($record === null) {
            return $options;
        }
        $type = TypeMaster::withTrashed()->find($record->typeable_id);
        $current = match ($kind) {
            'parents' => [$record->parent_id],
            'types' => [$type?->parent_id ?? $type?->id],
            'subtypes' => (int) ($type?->parent_id ?? $type?->id) === (int) $parentType ? [$record->typeable_id] : [],
            'contacts' => [$record->contactDetail?->id],
            'territories' => $record->territories()->pluck('territories.id')->all(),
        };
        foreach (array_filter($current) as $id) {
            $options[$id] ??= 'Existing linked '.match ($kind) {
                'parents' => 'location', 'contacts' => 'contact', 'territories' => 'territory', default => 'type'
            };
        }

        return $options;
    }

    public static function adminInput(array $data, ?LocationMaster $record = null): array
    {
        $image = $data['image'] ?? null;
        if (is_array($image)) {
            $image = reset($image) ?: null;
        }
        if ($image instanceof UploadedFile) {
            $data['image_base64'] = base64_encode($image->get());
        } elseif ($image === null && $record?->image) {
            $data['remove_image'] = true;
        }
        if ($record === null) {
            unset($data['revision']);
        }
        unset($data['image']);

        return $data;
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
                Action::make('submit')->label('Send for Approval')->requiresConfirmation()
                    ->visible(fn (LocationMaster $record): bool => app(LocationRegister::class)->canAct(auth()->user(), $record, 'submit'))
                    ->action(fn (LocationMaster $record) => app(LocationRegister::class)->act(auth()->user(), [$record->id], 'submit', app(LocationRegister::class)->revision($record))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::locationBulkAction('submit', 'Send for Bulk Approval'),
                    self::locationBulkAction('delete', 'Delete selected'),
                ]),
            ]);
    }

    private static function locationBulkAction(string $action, string $label): BulkAction
    {
        return BulkAction::make($action)->label($label)->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => auth()->user()->can(($action === 'delete' ? 'DeleteAny' : 'Update').':LocationMaster'))
            ->action(function (Collection $records) use ($action): void {
                app(LocationRegister::class)->act(auth()->user(), $records->modelKeys(), $action, bulk: true);
                Notification::make()->title('Locations updated')->success()->send();
            });
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

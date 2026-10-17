<?php

namespace App\Filament\Resources\OrganizationalUnits;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\OrganizationalUnits\Pages\CreateOrganizationalUnit;
use App\Filament\Resources\OrganizationalUnits\Pages\EditOrganizationalUnit;
use App\Filament\Resources\OrganizationalUnits\Pages\ListOrganizationalUnits;
use App\Filament\Resources\UsersRelationManagerResource\RelationManagers\UsersRelationManager;
use App\Models\OrganizationalUnit;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class OrganizationalUnitResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = OrganizationalUnit::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Org Units';

    protected static ?string $cluster = EmployeeManagementCluster::class;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->autofocus()
                            ->placeholder('Enter unit name'),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->placeholder('Enter unique code'),
                        Select::make('type_master_id')
                            ->label('Type')
                            ->relationship('typeMaster', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->where('typeable_type', OrganizationalUnit::class)
                                ->whereNotNull('parent_id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Choose Division to make this unit available in employee and position Division selections.'),
                    ])
                    ->columns(2),
                Section::make('Additional Details')
                    ->schema([
                        Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull()
                            ->placeholder('Describe the organizational unit'),
                        Select::make('parent_id')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Select parent unit')
                            ->nullable(),
                        Toggle::make('is_active')
                            ->required()
                            ->default(true)
                            ->label('Active Status'),
                    ])
                    ->columns(2)
                    ->collapsed(true)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => Str::limit($record->description, 50)),
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('parent.name')
                    ->sortable()
                    ->searchable()
                    ->default('None')
                    ->label('Parent Unit'),
                TextColumn::make('typeMaster.name')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Created'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Updated'),
            ])
            ->filters([
                SelectFilter::make('type_master_id')
                    ->label('Type')
                    ->relationship('typeMaster', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('typeable_type', OrganizationalUnit::class)
                        ->whereNotNull('parent_id')),
                SelectFilter::make('parent_id')
                    ->relationship('parent', 'name')
                    ->label('Parent Unit')
                    ->placeholder('All Units'),
                TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->trueLabel('Active Units')
                    ->falseLabel('Inactive Units')
                    ->placeholder('All Units'),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('Created From'),
                        DatePicker::make('created_until')
                            ->label('Created Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['created_until'], fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Unit deleted')
                            ->body('The organizational unit has been deleted successfully.')
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title('Units deleted')
                                ->body('The selected organizational units have been deleted successfully.')
                        ),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizationalUnits::route('/'),
            'create' => CreateOrganizationalUnit::route('/create'),
            'edit' => EditOrganizationalUnit::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

<?php

namespace App\Filament\Resources\EmpDepartments;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\EmpDepartments\Pages\CreateEmpDeparment;
use App\Filament\Resources\EmpDepartments\Pages\EditEmpDeparment;
use App\Filament\Resources\EmpDepartments\Pages\ListEmpDeparments;
use App\Filament\Resources\EmpDepartments\Pages\ViewEmpDeparment;
use App\Filament\Resources\EmpDepartments\RelationManagers\EmploymentDetailsRelationManager;
use App\Filament\Resources\EmpDepartments\RelationManagers\GradesRelationManager;
use App\Filament\Resources\EmpDepartments\RelationManagers\JobTitlesRelationManager;
use App\Models\EmpDepartment;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class EmpDepartmentResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = EmpDepartment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $cluster = EmployeeManagementCluster::class;

    protected static ?string $navigationLabel = 'Departments';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Department Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('department_name')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
                        TextInput::make('department_code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->nullable()
                            ->columnSpanFull(),
                        Select::make('organizational_unit_id')
                            ->relationship('organizationalUnit', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('department_head_id')
                            ->relationship('head', 'first_name')
                            ->searchable()
                            ->preload()
                            ->label('Department Head')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                            ->nullable(),
                        Toggle::make('is_active')
                            ->default(true),
                        Textarea::make('remark')
                            ->nullable()
                            ->columnSpanFull(),
                        Hidden::make('created_by_user_id')
                            ->default(Auth::id()),
                        Hidden::make('updated_by_user_id')
                            ->default(Auth::id()),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('department_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department_code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organizationalUnit.name')
                    ->label('OU')
                    ->sortable(),
                TextColumn::make('head.full_name')
                    ->label('Department Head')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
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
                TrashedFilter::make(),
                SelectFilter::make('organizational_unit_id')
                    ->relationship('organizationalUnit', 'name')
                    ->label('Organizational Unit'),
                Filter::make('is_active')
                    ->query(fn (Builder $query) => $query->where('is_active', true))
                    ->label('Active Departments'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ApprovalAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            GradesRelationManager::class,
            JobTitlesRelationManager::class,
            EmploymentDetailsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmpDeparments::route('/'),
            'create' => CreateEmpDeparment::route('/create'),
            'view' => ViewEmpDeparment::route('/{record}'),
            'edit' => EditEmpDeparment::route('/{record}/edit'),
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

<?php

namespace App\Filament\Resources\Positions;

use App\Enums\PositionStatus;
use App\Filament\Actions\ApprovalAction;
use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Territory;
use App\Services\OrganizationalUnitService;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class PositionResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Position::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $cluster = EmployeeManagementCluster::class;

    protected static ?string $navigationLabel = 'Positions';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Position Details')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->unique(ignoreRecord: true)
                        ->required()
                        ->maxLength(255),
                    Select::make('status')
                        ->options(PositionStatus::class)
                        ->default(PositionStatus::Active)
                        ->required(),
                    TextInput::make('level')
                        ->nullable()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->nullable()
                        ->maxLength(65535)
                        ->columnSpanFull(),
                ]),

            Section::make('Reporting & Organizational Structure')
                ->columns(2)
                ->schema([
                    Select::make('job_role_id')
                        ->relationship('jobRole', 'name')
                        ->required(),

                    Select::make('reports_to_position_id')
                        ->label('Reports To Position')
                        ->relationship('reportsTo', 'name')
                        ->searchable()
                        ->nullable()
                        ->placeholder('Select a reporting position'),

                    // 1. Multiple Territories
                    Select::make('territories')
                        ->relationship(name: 'territories', titleAttribute: 'name')
                        ->multiple()
                        ->preload()
                        ->live()                    // Important for reactivity
                        ->searchable()
                        ->label('Associated Territories')
                        ->required(),

                    // 2. HQ Territory (Only from selected territories)
                    Select::make('hq_territory_id')
                        ->label('HQ Territory')
                        ->options(function (Get $get) {
                            $selectedIds = $get('territories') ?? [];

                            if (empty($selectedIds)) {
                                return [];
                            }

                            return Territory::whereIn('id', $selectedIds)
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        })
                        ->live(onBlur: true)           // More stable reactivity
                        ->searchable()
                        ->required()
                        ->placeholder('Select HQ Territory...')
                        ->disabled(fn (Get $get) => empty($get('territories')))
                        ->afterStateUpdated(function (Set $set, $state, Get $get) {
                            $selected = $get('territories') ?? [];
                            if ($state && ! in_array($state, $selected)) {
                                $set('hq_territory_id', null);   // Reset if HQ is no longer valid
                            }
                        }),

                    Select::make('location_id')
                        ->label('Location')
                        ->relationship('location', 'name')
                        ->searchable()
                        ->nullable()
                        ->preload()
                        ->placeholder('Select a location'),

                    Select::make('division_ou_id')
                        ->label('Division')
                        ->options(fn () => OrganizationalUnit::query()
                            ->whereHas('typeMaster', fn ($q) => $q->where('name', 'Division')
                            )
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->reactive()
                        ->afterStateUpdated(function (callable $set) {
                            // 🔥 RESET dependent field
                            $set('organizational_unit_id', null);
                            $set('organizationalUnits', []);
                        }),

                    Select::make('organizationalUnits')
                        ->label('Organizational Units')
                        ->multiple()
                        ->rules([fn (callable $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            try {
                                OrganizationalUnitService::validateSelection($get('division_ou_id') ? (int) $get('division_ou_id') : null, (array) $value);
                            } catch (ValidationException $exception) {
                                $fail('Choose organizational units within the selected Division.');
                            }
                        }])
                        ->relationship(
                            name: 'organizationalUnits',
                            titleAttribute: 'name',
                            modifyQueryUsing: function ($query, callable $get) {
                                $divisionId = $get('division_ou_id');

                                if (! $divisionId) {
                                    $query->whereRaw('1 = 0');

                                    return;
                                }

                                $query->whereIn('organizational_units.id', OrganizationalUnitService::divisionUnitIds((int) $divisionId));
                            }
                        )
                        ->searchable()
                        ->preload()
                        ->reactive(),
                ]),

            Section::make('Job Classification')
                ->columns(2)
                ->schema([
                    Select::make('department_id')
                        ->label('Department')
                        ->relationship('department', 'department_name')
                        ->searchable()
                        ->nullable()
                        ->preload()
                        ->placeholder('Select a department'),

                    Select::make('job_title_id')
                        ->label('Job Title')
                        ->relationship('jobTitle', 'title')
                        ->searchable()
                        ->nullable()
                        ->preload()
                        ->placeholder('Select a job title'),

                    Select::make('job_grade_id')
                        ->label('Job Grade')
                        ->relationship('jobGrade', 'grade_name')
                        ->searchable()
                        ->nullable()
                        ->preload()
                        ->placeholder('Select a job grade'),
                ]),

            Section::make('Assigned Employees')
                ->columns(1)
                ->description('Select employees assigned to this position.')
                ->schema([
                    Select::make('employees')
                        ->relationship('employees', 'first_name')
                        ->getOptionLabelFromRecordUsing(fn (Employee $record) => "{$record->employee_id} - {$record->first_name} {$record->last_name}"
                        )
                        ->multiple()
                        ->searchable(['employees.employee_id', 'first_name', 'last_name'])
                        ->preload()
                        ->label('Select Employees'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),

                TextColumn::make('level')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('reportsTo.name')
                    ->label('Reports To')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('territories.name')
                    ->label('Territories')
                    ->listWithLineBreaks()
                    ->searchable(),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('division.name')
                    ->label('Division')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('department.department_name')
                    ->label('Department')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('jobTitle.title')
                    ->label('Job Title')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('jobGrade.grade_name')
                    ->label('Job Grade')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('organizationalUnits.name')
                    ->label('Org. Units')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('territories')
                    ->relationship('territories', 'name')
                    ->multiple()
                    ->label('Filter by Territory'),

                SelectFilter::make('location_id')
                    ->label('Location')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('reports_to_position_id')
                    ->label('Reports To')
                    ->relationship('reportsTo', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('division_ou_id')
                    ->label('Division')
                    ->relationship('division', 'name', modifyQueryUsing: fn ($query) => $query->whereHas('typeMaster', fn ($type) => $type->where('name', 'Division')))
                    ->searchable()
                    ->preload(),

                SelectFilter::make('department_id')
                    ->label('Department')
                    ->relationship('department', 'department_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('job_title_id')
                    ->label('Job Title')
                    ->relationship('jobTitle', 'title')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('job_grade_id')
                    ->label('Job Grade')
                    ->relationship('jobGrade', 'grade_name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('organizationalUnits')
                    ->relationship('organizationalUnits', 'name')
                    ->multiple()
                    ->preload()
                    ->label('Organizational Units'),
            ])->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ApprovalAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPositions::route('/'),
            'create' => CreatePosition::route('/create'),
            'edit' => EditPosition::route('/{record}/edit'),
        ];
    }
}

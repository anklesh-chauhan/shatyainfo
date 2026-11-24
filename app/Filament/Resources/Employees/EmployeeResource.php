<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Services\HR\EmployeeRegister;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
// Import Tabs
use Filament\Support\Icons\Heroicon; // Import Tab
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class EmployeeResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Employee::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $cluster = EmployeeManagementCluster::class;

    protected static ?string $navigationLabel = 'Employees';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        return (string) app(EmployeeRegister::class)->visible(auth()->user())->count();
    }

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && app(EmployeeRegister::class)->canRead(auth()->user());
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && app(EmployeeRegister::class)->canRead(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(EmployeeForm::fields('employee', $schema->getRecord() instanceof Employee ? $schema->getRecord() : null))
            ->disabled(fn (?Employee $record): bool => $record !== null && ! app(EmployeeRegister::class)->canChange(auth()->user(), $record));
    }

    public static function adminInput(array $data, ?Employee $record = null): array
    {
        if ($record === null) {
            unset($data['revision']);
        }
        $image = $data['profile_picture'] ?? null;
        if (is_array($image)) {
            $image = reset($image) ?: null;
        }
        if ($image instanceof UploadedFile) {
            $data['image_base64'] = base64_encode($image->get());
        } elseif ($image === null && $record?->profile_picture) {
            $data['remove_image'] = true;
        }
        unset($data['profile_picture']);
        $data['provision_login'] ??= false;

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_id')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('middle_name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('gender')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('date_of_birth')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('marital_status')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('blood_group')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('country.name')
                    ->label('Nationality')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('mobile_number')
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Hidden by default
                TextColumn::make('employmentDetail.department.department_name')
                    ->label('Department')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false), // Visible by default
                TextColumn::make('employmentDetail.jobTitle.title')
                    ->label('Job Title')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false), // Visible by default
                TextColumn::make('employmentDetail.employment_type')
                    ->label('Employment Type')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false), // Visible by default
                TextColumn::make('employmentDetail.employment_status')
                    ->label('Employment Status')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false), // Visible by default
                IconColumn::make('trashed')->label('Deleted')->boolean()->state(fn (Employee $record): bool => $record->trashed()),
                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable()
                    ->label('Active'),
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
                SelectFilter::make('record_state')->label('Record state')->options(['active' => 'Current', 'trashed' => 'Deleted'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? '') {
                        'active' => $query->withoutTrashed(), 'trashed' => $query->onlyTrashed(), default => $query
                    }),
                TernaryFilter::make('is_active')
                    ->label('Is Active')
                    ->trueLabel('Active Employees')
                    ->falseLabel('Inactive Employees')
                    ->indicator('Active Status'), // Label for the indicator when filter is applied

                SelectFilter::make('gender')
                    ->options([
                        'Male' => 'Male',
                        'Female' => 'Female',
                        'Other' => 'Other',
                    ])
                    ->label('Gender'),

                SelectFilter::make('marital_status')
                    ->options([
                        'Single' => 'Single',
                        'Married' => 'Married',
                        'Divorced' => 'Divorced',
                    ])
                    ->label('Marital Status'),

                SelectFilter::make('blood_group')
                    ->options([
                        'A+' => 'A+', 'A-' => 'A-',
                        'B+' => 'B+', 'B-' => 'B-',
                        'AB+' => 'AB+', 'AB-' => 'AB-',
                        'O+' => 'O+', 'O-' => 'O-',
                    ])
                    ->label('Blood Group'),

                SelectFilter::make('country_id')
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Nationality'),

                SelectFilter::make('employment_type')
                    ->options([
                        'Permanent' => 'Permanent',
                        'Contract' => 'Contract',
                        'Part-Time' => 'Part-Time',
                        'Intern' => 'Intern',
                        'Temporary' => 'Temporary',
                        'Consultant' => 'Consultant',
                    ])
                    ->label('Employment Type')->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $value): Builder => $query->whereHas('employmentDetail', fn (Builder $employment): Builder => $employment->where('employment_type', $value)))),

                SelectFilter::make('employment_status')
                    ->options([
                        'Active' => 'Active',
                        'Inactive' => 'Inactive',
                        'Terminated' => 'Terminated',
                        'Retired' => 'Retired',
                        'On Leave' => 'On Leave',
                    ])
                    ->label('Employment Status')->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $value): Builder => $query->whereHas('employmentDetail', fn (Builder $employment): Builder => $employment->where('employment_status', $value)))),

                SelectFilter::make('department')
                    ->relationship('employmentDetail.department', 'department_name')
                    ->searchable()
                    ->preload()
                    ->label('Department'),

                SelectFilter::make('job_title')
                    ->relationship('employmentDetail.jobTitle', 'title')
                    ->searchable()
                    ->preload()
                    ->label('Job Title'),

                SelectFilter::make('grade')
                    ->relationship('employmentDetail.grade', 'grade_name')
                    ->searchable()
                    ->preload()
                    ->label('Grade'),

                SelectFilter::make('division')
                    ->relationship('employmentDetail.division', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Division'),

                SelectFilter::make('organizational_units')
                    ->label('Organizational Units')
                    ->multiple()
                    ->options(
                        OrganizationalUnit::query()
                            ->pluck('name', 'id')
                            ->toArray()
                    )
                    ->query(function (Builder $query, array $data) {

                        if (empty($data['values'])) {
                            return;
                        }

                        $query->whereHas('employmentDetail.organizationalUnits', function ($q) use ($data) {
                            $q->whereIn('organizational_units.id', $data['values']);
                        });
                    })
                    ->searchable()
                    ->preload(),

                Filter::make('hire_date')
                    ->schema([
                        DatePicker::make('hire_from')
                            ->placeholder('Hire Date From'),
                        DatePicker::make('hire_to')
                            ->placeholder('Hire Date To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['hire_from'],
                                fn (Builder $query, $date): Builder => $query->whereHas('employmentDetail', fn (Builder $subQuery) => $subQuery->whereDate('hire_date', '>=', $date)),
                            )
                            ->when(
                                $data['hire_to'],
                                fn (Builder $query, $date): Builder => $query->whereHas('employmentDetail', fn (Builder $subQuery) => $subQuery->whereDate('hire_date', '<=', $date)),
                            );
                    })
                    ->label('Hire Date Range'),

                Filter::make('last_increment_date')
                    ->schema([
                        DatePicker::make('increment_from')
                            ->placeholder('Increment Date From'),
                        DatePicker::make('increment_to')
                            ->placeholder('Increment Date To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['increment_from'],
                                fn (Builder $query, $date): Builder => $query->whereHas('employmentDetail', fn (Builder $subQuery) => $subQuery->whereDate('last_increment_date', '>=', $date)),
                            )
                            ->when(
                                $data['increment_to'],
                                fn (Builder $query, $date): Builder => $query->whereHas('employmentDetail', fn (Builder $subQuery) => $subQuery->whereDate('last_increment_date', '<=', $date)),
                            );
                    })
                    ->label('Last Increment Date Range'),
            ])->filtersFormColumns(2)
            ->recordActions([
                EditAction::make(),
                self::employeeAction('submit', 'Send for Approval'),
                self::employeeAction('delete', 'Delete'),
                self::employeeAction('restore', 'Restore'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::employeeBulkAction('submit', 'Send for Bulk Approval'),
                    self::employeeBulkAction('delete', 'Delete selected'),
                    self::employeeBulkAction('restore', 'Restore selected'),
                ]),
            ]);
    }

    private static function employeeAction(string $action, string $label): Action
    {
        return Action::make($action)->label($label)->requiresConfirmation()
            ->visible(fn (Employee $record): bool => app(EmployeeRegister::class)->canAct(auth()->user(), $record, $action))
            ->action(fn (Employee $record) => app(EmployeeRegister::class)->act(auth()->user(), [$record->id], $action, app(EmployeeRegister::class)->revision($record)));
    }

    private static function employeeBulkAction(string $action, string $label): BulkAction
    {
        return BulkAction::make($action)->label($label)->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => auth()->user()->can(match ($action) {
                'delete' => 'DeleteAny:Employee', 'restore' => 'RestoreAny:Employee', default => 'Update:Employee'
            }))
            ->action(fn (Collection $records) => app(EmployeeRegister::class)->act(auth()->user(), $records->modelKeys(), $action, bulk: true));
    }

    public static function getRelations(): array
    {
        return [RelationManagers\EmploymentDetailRelationManager::class, RelationManagers\ProfessionalTaxRelationManager::class,
            RelationManagers\StatutoryIdsRelationManager::class, RelationManagers\QualificationsRelationManager::class, RelationManagers\SkillsRelationManager::class,
            RelationManagers\ShiftAssignmentsRelationManager::class, RelationManagers\WeekOffsRelationManager::class, RelationManagers\BankDetailsRelationManager::class, PositionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(EmployeeRegister::class)->visible(auth()->user())->with('approval');
    }
}

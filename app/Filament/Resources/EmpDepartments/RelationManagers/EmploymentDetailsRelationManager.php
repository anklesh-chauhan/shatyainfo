<?php

namespace App\Filament\Resources\EmpDepartments\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmploymentDetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'employmentDetails';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->relationship('employee', 'full_name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('ticket_no')
                    ->maxLength(20)
                    ->nullable(),
                Select::make('job_title_id')
                    ->relationship('jobTitle', 'title')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Select::make('grade_id')
                    ->relationship('grade', 'grade_name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Select::make('division_ou_id')
                    ->relationship('division', 'name', modifyQueryUsing: fn ($query) => $query->whereHas('typeMaster', fn ($type) => $type->where('name', 'Division')))
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Select::make('organizational_unit_id')
                    ->relationship('organizationalUnit', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                DatePicker::make('hire_date')
                    ->required()
                    ->native(false),
                Select::make('employment_type')
                    ->options([
                        'Permanent' => 'Permanent',
                        'Contract' => 'Contract',
                        'Intern' => 'Intern',
                        'Temporary' => 'Temporary',
                        'Consultant' => 'Consultant',
                    ])
                    ->nullable(),
                Select::make('employment_status')
                    ->options([
                        'Active' => 'Active',
                        'Inactive' => 'Inactive',
                        'Terminated' => 'Terminated',
                        'Retired' => 'Retired',
                        'On Leave' => 'On Leave',
                    ])
                    ->required(),
                DatePicker::make('resign_offer_date')
                    ->native(false)
                    ->nullable(),
                DatePicker::make('last_working_date')
                    ->native(false)
                    ->nullable(),
                DatePicker::make('probation_date')
                    ->native(false)
                    ->nullable(),
                DatePicker::make('confirm_date')
                    ->native(false)
                    ->nullable(),
                DatePicker::make('fnf_retiring_date')
                    ->native(false)
                    ->nullable(),
                DatePicker::make('last_increment_date')
                    ->native(false)
                    ->nullable(),
                Select::make('work_location_id')
                    ->relationship('workLocation', 'location_name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Select::make('reporting_manager_id')
                    ->relationship('reportingManager', 'full_name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Textarea::make('remarks')
                    ->columnSpanFull()
                    ->nullable(),
            ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('employee.full_name')
            ->columns([
                TextColumn::make('employee.full_name'),
                TextColumn::make('ticket_no'),
                TextColumn::make('jobTitle.title'),
                TextColumn::make('grade.grade_name'),
                TextColumn::make('division.name'),
                TextColumn::make('organizationalUnit.name'),
                TextColumn::make('hire_date')
                    ->date(),
                TextColumn::make('employment_status'),
                TextColumn::make('workLocation.location_name'),
                TextColumn::make('reportingManager.full_name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->disabled(fn () => $this->getOwnerRecord()->employmentDetails()->count() > 0
                        && $this->getOwnerRecord()->employmentDetails()->where('employment_status', 'Active')->exists()
                    )
                    ->modalHeading('Add Employment Details')
                    ->modalDescription('You can only add one active employment detail per employee.'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

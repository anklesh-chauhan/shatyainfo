<?php

namespace App\Filament\Resources\Employees\RelationManagers;

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

class EmploymentDetailRelationManager extends RelationManager
{
    protected static string $relationship = 'employmentDetail';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('ticket_no')
                    ->maxLength(20)
                    ->nullable(),
                Select::make('department_id')
                    ->relationship('department', 'department_name')
                    ->searchable()
                    ->preload()
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
                    ->relationship('workLocation', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),
                Select::make('reporting_manager_id')
                    ->relationship('reportingManager', 'first_name') // Use a real column as fallback
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
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
            ->recordTitleAttribute('ticket_no')
            ->columns([
                TextColumn::make('ticket_no'),
                TextColumn::make('department.department_name'),
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
                CreateAction::make(),
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

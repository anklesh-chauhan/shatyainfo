<?php

namespace App\Filament\Resources\EmployeeAttendances\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;
use Filament\Forms;

class EmployeeAttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
    ->label('Employee')
    ->options(function () {
        return \App\Models\Employee::all()->pluck('full_name', 'id');
    })
    ->searchable()
    ->getSearchResultsUsing(function (string $search) {
        return \App\Models\Employee::query()
            ->whereLike('first_name', "%{$search}%")
            ->orWhereLike('middle_name', "%{$search}%")
            ->orWhereLike('last_name', "%{$search}%")
            ->get()
            ->pluck('full_name', 'id');
    })
    ->getOptionLabelUsing(fn ($value) => \App\Models\Employee::find($value)?->full_name)
    ->required(),

                Forms\Components\DatePicker::make('attendance_date')
                    ->required()
                    ->default(now())
                    ->minDate(now()->subMonths(3)),

                Forms\Components\TimePicker::make('check_in')
                    ->label('Check In Time'),

                Forms\Components\TimePicker::make('check_out')
                    ->label('Check Out Time'),

                Forms\Components\TextInput::make('total_hours')
                    ->disabled()
                    ->numeric()
                    ->label('Total Hours (Auto)')
                    ->dehydrated(false),

                Forms\Components\Select::make('status_id')
                    ->label('Status')
                    ->relationship('status', 'status')
                    ->preload()
                    ->searchable()
                    ->required(),
            ]);
    }
}

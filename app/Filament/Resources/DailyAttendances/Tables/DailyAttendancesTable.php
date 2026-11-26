<?php

namespace App\Filament\Resources\DailyAttendances\Tables;

use App\Jobs\ProcessDailyAttendanceJob;
use App\Models\DailyAttendance;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DailyAttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('attendance_date')
                    ->date()
                    ->sortable(),

                TextColumn::make('employee.full_name')
                    ->searchable(['first_name', 'last_name', 'employee_id'])
                    ->sortable(['first_name', 'last_name']),

                TextColumn::make('shift.name')
                    ->label('Shift')
                    ->sortable(),

                TextColumn::make('first_punch_in')
                    ->time('H:i')
                    ->label('In'),

                TextColumn::make('last_punch_out')
                    ->time('H:i')
                    ->label('Out'),

                TextColumn::make('actual_working_minutes')
                    ->label('Work (Min)')
                    ->sortable(),

                TextColumn::make('status.status')
                    ->badge()
                    ->label('Status')
                    ->color(fn ($record) => $record->status?->color_code),
            ])
            ->filters([
                SelectFilter::make('status_id')
                    ->relationship('status', 'status')
                    ->label('Status'),

                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('attendance_date', '>=', $data['from'])
                            )
                            ->when($data['to'], fn ($q) => $q->whereDate('attendance_date', '<=', $data['to'])
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('recalculate')
                    ->label('Recalculate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->button()
                    ->requiresConfirmation()
                    ->authorize(fn (DailyAttendance $record): bool => auth()->user()->can('update', $record))
                    ->action(function (DailyAttendance $record): void {
                        self::recalculate($record);
                    }),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function recalculate(DailyAttendance $record): void
    {
        app()->call([new ProcessDailyAttendanceJob($record->id), 'handle']);

        Notification::make()
            ->title('Attendance recalculated successfully')
            ->success()
            ->send();
    }
}

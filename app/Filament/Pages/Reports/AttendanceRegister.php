<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\AttendanceRegisterExporter;
use App\Filament\Resources\DailyAttendances\DailyAttendanceResource;
use App\Models\DailyAttendance;
use App\Services\Reports\AttendanceRegisterQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceRegister extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = AttendanceRegisterExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Attendance register';

    protected static ?string $title = 'Attendance register';

    protected static ?int $navigationSort = 20;

    protected function visibilityPermissionKey(): ?string
    {
        return null;
    }

    protected function dateColumn(): ?string
    {
        return 'attendance_date';
    }

    protected function userColumn(): ?string
    {
        return 'user_id';
    }

    protected function territoryColumn(): ?string
    {
        return null;
    }

    protected function applyUserFilter(Builder $query, mixed $userId): Builder
    {
        if (blank($userId)) {
            return $query;
        }

        return $query->whereHas(
            'employee',
            fn (Builder $employeeQuery): Builder => $employeeQuery->where('login_id', $userId)
        );
    }

    protected function getReportQuery(): Builder
    {
        return app(AttendanceRegisterQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('status_id')
                ->label('Status')
                ->relationship('status', 'status')
                ->searchable(),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('attendance_date')
                ->label('Date')
                ->date()
                ->sortable(),
            TextColumn::make('employee.full_name')
                ->label('Employee')
                ->searchable(['first_name', 'last_name']),
            TextColumn::make('shift.name')
                ->label('Shift')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('first_punch_in')
                ->label('First in')
                ->time('H:i')
                ->placeholder('—'),
            TextColumn::make('last_punch_out')
                ->label('Last out')
                ->time('H:i')
                ->placeholder('—'),
            TextColumn::make('status.status')
                ->label('Status')
                ->badge()
                ->placeholder('—'),
            TextColumn::make('actual_working_minutes')
                ->label('Work (min)')
                ->numeric()
                ->sortable()
                ->toggleable(),
            IconColumn::make('is_absent')
                ->label('Absent')
                ->boolean()
                ->toggleable(),
            IconColumn::make('is_half_day')
                ->label('Half day')
                ->boolean()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (DailyAttendance $record): string => DailyAttendanceResource::getUrl('edit', ['record' => $record]));
    }
}

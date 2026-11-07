<?php

namespace App\Filament\Resources\EmployeeAttendances\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeAttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->sortable()      // optional (remove if error)
                    ->searchable(),   // optional searchable
                TextColumn::make('attendance_date')->date()->sortable(),
                TextColumn::make('check_in'),
                TextColumn::make('check_out'),
                TextColumn::make('status.status')
                    ->badge()
                    ->tooltip(fn ($record) => $record->status->remarks ?? 'No remarks')
                    ->color(fn ($record) => match ($record->status->status_code) {
                        'P' => 'success',
                        'A' => 'danger',
                        'L' => 'warning',
                        'OD' => 'info',
                        'H' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('total_hours'),

                // add latitude and longitude
                TextColumn::make('check_in_latitude')
                    ->label('Check-in Latitude')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('check_in_longitude')
                    ->label('Check-in Longitude')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('check_out_latitude')
                    ->label('Check-out Latitude')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('check_out_longitude')
                    ->label('Check-out Longitude')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('attendance_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status_id')
                    ->relationship('status', 'status'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

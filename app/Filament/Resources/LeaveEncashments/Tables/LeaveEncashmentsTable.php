<?php

namespace App\Filament\Resources\LeaveEncashments\Tables;

use App\Filament\Actions\ApprovalAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class LeaveEncashmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('approval_status')->badge(),
                TextColumn::make('employee.first_name'),
                TextColumn::make('leaveType.name'),
                TextColumn::make('days'),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('encashed_on')->date(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ApprovalAction::make()->authorize(fn ($record): bool => auth()->user()->can('update', $record)),
                EditAction::make()->visible(fn ($record): bool => in_array($record->approval_status, ['draft', 'rejected'], true)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}

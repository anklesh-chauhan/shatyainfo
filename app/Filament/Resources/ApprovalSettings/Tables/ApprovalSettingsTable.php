<?php

namespace App\Filament\Resources\ApprovalSettings\Tables;

use App\Filament\Actions\ApprovalAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('approval_mode')
                    ->label('Approval requirement')
                    ->state(fn ($record): string => $record->enabled_modules === [] ? 'No approvals required' : 'Approvals enabled')
                    ->badge(),
                TextColumn::make('enabled_modules')
                    ->label('Enabled Modules')
                    ->placeholder('No approvals required')
                    ->formatStateUsing(fn ($state) => collect($state)->map(fn ($m) => class_basename($m))->implode(', '))
                    ->badge()
                    ->listWithLineBreaks()
                    ->wrap(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

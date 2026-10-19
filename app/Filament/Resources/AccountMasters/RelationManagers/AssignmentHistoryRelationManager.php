<?php

namespace App\Filament\Resources\AccountMasters\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssignmentHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'assignmentHistory';

    protected static ?string $title = 'Assignment History';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('owner.name'), TextColumn::make('territory.name'),
            TextColumn::make('patch_ids')->label('Patch IDs')->listWithLineBreaks(),
            TextColumn::make('effective_from')->dateTime(), TextColumn::make('effective_to')->dateTime()->placeholder('Current'),
        ])->defaultSort('id', 'desc');
    }
}

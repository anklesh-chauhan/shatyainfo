<?php

namespace App\Filament\Resources\Visits\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EDetailingPresentationsRelationManager extends RelationManager
{
    protected static string $relationship = 'eDetailingPresentations';

    protected static ?string $title = 'E-detailing history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('presented_at', 'desc')->columns([
            TextColumn::make('material_title')->label('Material presented')->searchable(),
            TextColumn::make('user.name')->label('Representative'),
            TextColumn::make('presented_at')->dateTime()->sortable(),
            TextColumn::make('notes')->wrap(),
            TextColumn::make('content_version')->label('File version')->limit(12)->toggleable(isToggledHiddenByDefault: true),
        ])->headerActions([])->recordActions([])->toolbarActions([])
            ->emptyStateHeading('No presentations recorded for this visit');
    }
}

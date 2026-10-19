<?php

namespace App\Filament\Resources\AccountMasters\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CallTargetsRelationManager extends RelationManager
{
    protected static string $relationship = 'callTargets';

    protected static ?string $title = 'Monthly Call Targets';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('calls_per_month')->integer()->minValue(0)->maxValue(1000)->required()->helperText('Account-wide across all divisions. Zero means no calls planned; a missing target means not configured.'),
            DatePicker::make('effective_from')->required()->helperText('First day of a month.'),
            DatePicker::make('effective_to')->afterOrEqual('effective_from')->helperText('Last day of a month, or leave blank for ongoing.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('calls_per_month'), TextColumn::make('effective_from')->date(), TextColumn::make('effective_to')->date()->placeholder('Open-ended')])
            ->headerActions([CreateAction::make()->authorize(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))])
            ->recordActions([EditAction::make()->authorize(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))])->defaultSort('effective_from', 'desc');
    }
}

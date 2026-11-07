<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class LeadActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'leadActivities';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('user_id')
                    ->default(Auth::id()),

                TextInput::make('activity_type')
                    ->label('Activity Type')
                    ->required(),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(3)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('activity_type')
                    ->label('Activity Type')
                    ->badge()
                    ->color(fn ($record) => match ($record->activity_type) {
                        'Follow-up Created' => 'success',
                        'Item Attached' => 'warning',
                        default => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Description')
                    ->wrap()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->description), // Shows full text on hover

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime() // Improved date format
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\ApprovalFlows\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApprovalFlowStepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('step_order')
                    ->numeric()
                    ->minValue(1)
                    ->required(),

                Select::make('job_role_id')
                    ->helperText('Exactly one active employee must match this role and territory on the same position. The requester cannot approve their own request.')
                    ->relationship('jobRole', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('territory_scope')
                    ->options([
                        'self' => 'Same Territory',
                        'children' => 'Child Territories',
                        'all' => 'All Territories',
                    ])
                    ->default('self')
                    ->required(),

                TextInput::make('sla_hours')
                    ->label('SLA Hours')
                    ->numeric()
                    ->minValue(1)
                    ->default(24)
                    ->required(),

                Toggle::make('can_skip')
                    ->label('Skip if no approver is available')
                    ->helperText('Optional steps may be skipped, but at least one actionable step must remain. Ambiguous matches are never skipped.')
                    ->default(false),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ApprovalFlowSteps')
            ->columns([
                TextColumn::make('step_order'),
                TextColumn::make('jobRole.name')->label('Job Role'),
                TextColumn::make('territory_scope')->badge(),
                TextColumn::make('sla_hours')->label('SLA Hours'),
                IconColumn::make('can_skip')->boolean(),
            ])
            ->defaultSort('step_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]));
    }
}

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
                    ->helperText('Choose a named approver below when several employees share this role.')
                    ->relationship('jobRole', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('approver_user_id')
                    ->label('Named approver (optional)')
                    ->helperText('If blank, exactly one active employee must match the role and territory. A named approver must still match both when a request is submitted.')
                    ->relationship('approver', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereHas('employee', fn (Builder $employeeQuery): Builder => $employeeQuery->where('is_active', true)))
                    ->searchable()
                    ->preload(),

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
                TextColumn::make('approver.name')->label('Named Approver')->placeholder('Automatic'),
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

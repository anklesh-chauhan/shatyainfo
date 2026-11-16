<?php

namespace App\Filament\Resources\SalesDcrs\RelationManagers;

use App\AutoCalculateExpensesAction;
use App\Models\SalesDcrExpense;
use App\Services\OptionalModuleService;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesDcrExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(OptionalModuleService::class)->navigationEnabled('SalesDcrExpense')
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('expense_type_id')
                    ->relationship('expenseType', 'name')
                    ->required(),

                Forms\Components\Select::make('transport_mode_id')
                    ->relationship('transportMode', 'name')
                    ->nullable(),

                Forms\Components\TextInput::make('quantity')
                    ->numeric(),

                Forms\Components\TextInput::make('rate')
                    ->numeric(),

                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->live(debounce: 500),

                Forms\Components\Toggle::make('is_auto_calculated')
                    ->disabled(),

                Forms\Components\Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('Expenses')
            ->columns([
                Tables\Columns\TextColumn::make('expenseType.name')
                    ->label('Expense'),

                Tables\Columns\TextColumn::make('transportMode.name')
                    ->label('Transport'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty'),

                Tables\Columns\TextColumn::make('rate')
                    ->label('Rate')
                    ->money('INR'),

                Tables\Columns\TextColumn::make('amount')
                    ->money('INR'),

                Tables\Columns\IconColumn::make('is_auto_calculated')
                    ->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                CreateAction::make(),
                AssociateAction::make()
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('Update:SalesDcrExpense'))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->whereHas('salesDcr', fn (Builder $reports): Builder => $reports
                            ->applyVisibility('SalesDcr')
                            ->whereIn('approval_status', ['draft', 'rejected']))),
                AutoCalculateExpensesAction::make()
                    ->authorize(fn (): bool => (bool) auth()->user()?->can('create', SalesDcrExpense::class)
                        && (bool) auth()->user()?->can('update', $this->getOwnerRecord())),
            ])
            ->recordActions([
                EditAction::make(),
                DissociateAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make(),
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

<?php

namespace App\Filament\Resources\ExpenseConfigurations\Tables;

use App\Models\ExpenseConfiguration as ExpenseRule;
use App\Services\DateFormatService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Util\Filter;

class ExpenseConfigurationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Config Name')
                    ->searchable(),
                // Primary Information: Expense Type + Strategy
                TextColumn::make('expenseType.name')
                    ->label('Expense Configuration')
                    ->description(fn (ExpenseRule $record): string => 'Strategy: '.match ($record->calculation_strategy) {
                        'flat' => 'Flat Amount',
                        'per_km' => 'Per Kilometer',
                        'per_visit' => 'Per Visit',
                        'slab' => 'Slab / Tier Based',
                        'multiplier' => 'Multiplier Based',
                        default => $record->calculation_strategy,
                    })
                    ->searchable()
                    ->sortable(),

                // Financials: Rate or Slab Count
                TextColumn::make('rate')
                    ->label('Rate/Value')
                    ->state(fn (ExpenseRule $record): string => match ($record->calculation_strategy) {
                        'slab' => $record->slabs()->count().' Tiers Defined',
                        default => number_format($record->rate, 2),
                    })
                    ->badge()
                    ->color(fn (ExpenseRule $record) => $record->calculation_strategy === 'slab' ? 'warning' : 'success')
                    ->alignEnd(),

                // Scope: Combined Indicators
                TextColumn::make('scope_summary')
                    ->label('Applicable To')
                    ->state(function (ExpenseRule $record): string {
                        $counts = [];
                        if ($c = $record->roles()->count()) {
                            $counts[] = "$c Roles";
                        }
                        if ($c = $record->positions()->count()) {
                            $counts[] = "$c Pos";
                        }
                        if ($c = $record->territories()->count()) {
                            $counts[] = "$c Terr";
                        }
                        if ($c = $record->grades()->count()) {
                            $counts[] = "$c Grades";
                        }

                        return empty($counts) ? 'Global (All)' : implode(' • ', $counts);
                    })
                    ->color(fn ($state) => $state === 'Global (All)' ? 'gray' : 'info')
                    ->wrap()
                    ->description(fn (ExpenseRule $record): string => $record->conditions()->exists() ? "{$record->conditions()->count()} logic conditions applied" : 'No dynamic conditions'
                    ),

                // Execution Control
                TextColumn::make('priority')
                    ->label('Exec. Order')
                    ->sortable()
                    ->alignCenter()
                    ->badge(),

                // Validity Period
                TextColumn::make('effective_from')
                    ->label('Validity')
                    ->date()
                    ->description(fn (ExpenseRule $record): string => $record->effective_to ? 'Until '.$record->effective_to->format(app(DateFormatService::class)->dateFormat()) : 'Indefinite Duration'
                    )
                    ->sortable(),

                // Binary Flags: Grouped visually
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('requires_approval')
                    ->label('Apprv.')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('requires_attachment')
                    ->label('Rect.')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('allow_manual_override')
                    ->label('Ovr.')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                SelectFilter::make('expense_type_id')
                    ->relationship('expenseType', 'name')
                    ->label('Expense Type')
                    ->preload(),

                SelectFilter::make('calculation_strategy')
                    ->options([
                        'flat' => 'Flat Amount',
                        'per_km' => 'Per KM',
                        'per_visit' => 'Per Visit',
                        'slab' => 'Slab Based',
                        'multiplier' => 'Multiplier',
                    ])
                    ->label('Calculation Strategy'),

                // Filter::make('is_active')
                //     ->label('Active Only')
                //     ->default(true)
                //     ->query(fn (Builder $query) => $query->where('is_active', true)),

                // Filter::make('effective_range')
                //     ->form([
                //         DatePicker::make('effective_from'),
                //         DatePicker::make('effective_to'),
                //     ])
                //     ->query(function (Builder $query, array $data) {
                //         return $query
                //             ->when($data['effective_from'], fn ($q) => $q->where('effective_from', '>=', $data['effective_from']))
                //             ->when($data['effective_to'], fn ($q) => $q->where('effective_to', '<=', $data['effective_to']));
                //     }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
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

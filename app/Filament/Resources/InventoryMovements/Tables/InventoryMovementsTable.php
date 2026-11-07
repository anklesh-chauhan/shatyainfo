<?php

namespace App\Filament\Resources\InventoryMovements\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('movement_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('item.item_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item.item_name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('movement_type')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity_in')
                    ->numeric(3)
                    ->alignEnd()
                    ->color('success'),
                TextColumn::make('quantity_out')
                    ->numeric(3)
                    ->alignEnd()
                    ->color('danger'),
                TextColumn::make('balance_after')
                    ->numeric(3)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('reference_type')
                    ->formatStateUsing(fn (?string $state): string => class_basename((string) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remarks')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('item_master_id')
                    ->relationship('item', 'item_name')
                    ->label('Item')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('location_master_id')
                    ->relationship('location', 'name')
                    ->label('Location')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('movement_type')
                    ->options([
                        'grn_receipt' => 'GRN Receipt',
                        'adjustment_receipt' => 'Adjustment Receipt',
                        'adjustment_issue' => 'Adjustment Issue',
                        'adjustment_increase' => 'Adjustment Increase',
                        'adjustment_decrease' => 'Adjustment Decrease',
                        'transfer_in' => 'Transfer In',
                        'transfer_out' => 'Transfer Out',
                        'audit_variance' => 'Audit Variance',
                    ]),
                Filter::make('movement_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('movement_at', '<=', $date))),
            ])
            ->defaultSort('movement_at', 'desc');
    }
}

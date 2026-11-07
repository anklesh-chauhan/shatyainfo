<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\PurchaseOrderStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_number')
                    ->label('PO Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money('INR')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('expected_delivery_date')
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('approval_status')
                    ->badge()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PurchaseOrderStatus::class),
                SelectFilter::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->label('Supplier')
                    ->searchable()
                    ->preload(),
                Filter::make('order_date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('order_date', '<=', $date))),
            ])
            ->defaultSort('order_date', 'desc');
    }
}

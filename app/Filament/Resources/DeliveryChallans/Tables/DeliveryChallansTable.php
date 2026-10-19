<?php

namespace App\Filament\Resources\DeliveryChallans\Tables;

use App\Enums\DeliveryChallanStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeliveryChallansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('approval.approval_status')->label('Approval')->badge()->placeholder('Not submitted'),
                TextColumn::make('document_number')
                    ->label('Challan Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('delivery_date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('salesInvoice.document_number')
                    ->label('Invoice Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('posted_at')
                    ->dateTime('d M Y, h:i A')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('postedBy.name')
                    ->label('Posted By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DeliveryChallanStatus::class),
                SelectFilter::make('customer_id')
                    ->relationship('customer', 'name')
                    ->label('Customer')
                    ->searchable()
                    ->preload(),
                Filter::make('delivery_date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('delivery_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('delivery_date', '<=', $date))),
            ])
            ->defaultSort('delivery_date', 'desc');
    }
}

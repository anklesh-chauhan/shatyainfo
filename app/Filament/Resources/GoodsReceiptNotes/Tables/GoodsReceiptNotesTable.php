<?php

namespace App\Filament\Resources\GoodsReceiptNotes\Tables;

use App\Enums\GoodsReceiptNoteStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GoodsReceiptNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('approval.approval_status')->label('Approval')->badge()->placeholder('Not submitted'),
                TextColumn::make('document_number')
                    ->label('GRN Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('receipt_date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('purchaseOrder.document_number')
                    ->label('PO Number')
                    ->searchable()
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
                    ->options(GoodsReceiptNoteStatus::class),
                SelectFilter::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->label('Supplier')
                    ->searchable()
                    ->preload(),
                Filter::make('receipt_date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('receipt_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('receipt_date', '<=', $date))),
            ])
            ->defaultSort('receipt_date', 'desc');
    }
}

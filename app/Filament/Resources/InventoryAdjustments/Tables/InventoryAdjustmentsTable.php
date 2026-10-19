<?php

namespace App\Filament\Resources\InventoryAdjustments\Tables;

use App\Enums\InventoryDocumentStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventoryAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('approval.approval_status')->label('Approval')->badge()->placeholder('Not submitted'),
                TextColumn::make('adjustment_number')
                    ->label('Document No.')
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
                TextColumn::make('adjustment_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric(3)
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('posted_at')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(30)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InventoryDocumentStatus::class),
                SelectFilter::make('item_master_id')
                    ->relationship('item', 'item_name')
                    ->label('Item')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

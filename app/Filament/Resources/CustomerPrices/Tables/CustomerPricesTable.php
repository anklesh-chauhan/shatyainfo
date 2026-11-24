<?php

namespace App\Filament\Resources\CustomerPrices\Tables;

use App\Filament\Actions\ApprovalAction;
use App\Models\CustomerPrice;
use App\Services\Field\FieldCustomerPriceService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CustomerPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Customer
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                // Item (shows parent + variant if applicable)
                TextColumn::make('item.item_name')
                    ->label('Item / Variant')
                    ->formatStateUsing(fn (CustomerPrice $record): string => $record->item
                        ? app(FieldCustomerPriceService::class)->itemLabel($record->item) : '-')
                    ->searchable()
                    ->sortable(),

                // Price
                TextColumn::make('price')
                    ->label('Price')
                    ->money('INR')
                    ->sortable(),

                // Discount
                TextColumn::make('discount')
                    ->label('Discount')
                    ->suffix('%')
                    ->sortable(),

                // Created & Updated timestamps
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (CustomerPrice $record): bool => app(FieldCustomerPriceService::class)->canChange(auth()->user(), $record, 'update')),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->using(function (DeleteBulkAction $action, Collection $records): void {
                        $deleted = app(FieldCustomerPriceService::class)->delete(auth()->user(), $records->modelKeys());
                        $action->reportBulkProcessingSuccessfulRecordsCount($deleted);
                    }),
                ]),
            ]);
    }
}

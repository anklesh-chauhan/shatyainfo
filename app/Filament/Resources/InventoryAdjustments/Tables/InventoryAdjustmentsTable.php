<?php

namespace App\Filament\Resources\InventoryAdjustments\Tables;

use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryAdjustment;
use App\Services\Inventory\StockAdjustmentRegister;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(30)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('adjustment_type')->options(InventoryAdjustmentType::class),
                SelectFilter::make('status')
                    ->options(InventoryDocumentStatus::class),
                SelectFilter::make('item_master_id')
                    ->options(fn (): array => app(StockAdjustmentRegister::class)->catalog(auth()->user(), 'items')->pluck('item_name', 'id')->all())
                    ->label('Item')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkAction::make('delete')->label('Delete')->requiresConfirmation()->deselectRecordsAfterCompletion()
                ->visible(fn (): bool => auth()->user()->can('deleteAny', InventoryAdjustment::class))
                ->action(function (Collection $records): void {
                    try {
                        app(StockAdjustmentRegister::class)->deleteMany(auth()->user(), $records->modelKeys());
                    } catch (HttpException $exception) {
                        if ($exception->getStatusCode() !== 409) {
                            throw $exception;
                        } Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Adjustments deleted')->success()->send();
                })])
            ->defaultSort('created_at', 'desc');
    }
}

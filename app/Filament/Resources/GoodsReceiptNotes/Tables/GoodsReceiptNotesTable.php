<?php

namespace App\Filament\Resources\GoodsReceiptNotes\Tables;

use App\Enums\GoodsReceiptNoteStatus;
use App\Models\GoodsReceiptNote;
use App\Services\Inventory\GoodsReceiptRegister;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
                    ->date()
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
                    ->dateTime()
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
                    ->options(fn (): array => app(GoodsReceiptRegister::class)->catalog(auth()->user(), 'suppliers')->pluck('name', 'id')->all())
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
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkAction::make('delete')->label('Delete')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->visible(fn (): bool => auth()->user()->can('deleteAny', GoodsReceiptNote::class))
                    ->action(function (Collection $records): void {
                        try {
                            app(GoodsReceiptRegister::class)->act(auth()->user(), $records->modelKeys(), 'delete', bulk: true);
                        } catch (HttpException $exception) {
                            if ($exception->getStatusCode() !== 409) {
                                throw $exception;
                            }
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Goods receipts deleted')->success()->send();
                    }),
            ])
            ->defaultSort('receipt_date', 'desc');
    }
}

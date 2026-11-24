<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Inventory\PurchaseOrderRegister;
use Filament\Actions\Action;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
                    ->options(fn (): array => app(PurchaseOrderRegister::class)->catalog(auth()->user(), 'suppliers')->pluck('name', 'id')->all())
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
            ->recordActions([
                EditAction::make(),
                Action::make('submit')->label('Send for Approval')->requiresConfirmation()
                    ->visible(fn (PurchaseOrder $record): bool => app(PurchaseOrderRegister::class)->canAct(auth()->user(), $record, 'submit'))
                    ->action(function (PurchaseOrder $record): void {
                        self::act([$record->id], 'submit', app(PurchaseOrderRegister::class)->revision($record));
                    }),
            ])
            ->toolbarActions([
                self::bulk('submit', 'Send for Approval'), self::bulk('delete', 'Delete'),
            ])
            ->defaultSort('order_date', 'desc');
    }

    private static function bulk(string $action, string $label): BulkAction
    {
        return BulkAction::make($action)->label($label)->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->visible(fn (): bool => $action === 'delete' ? auth()->user()->can('deleteAny', PurchaseOrder::class) : auth()->user()->can('Update:PurchaseOrder'))
            ->action(function (Collection $records) use ($action): void {
                self::act($records->modelKeys(), $action, bulk: true);
            });
    }

    private static function act(array $ids, string $action, ?string $revision = null, bool $bulk = false): void
    {
        try {
            app(PurchaseOrderRegister::class)->act(auth()->user(), $ids, $action, $revision, $bulk);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();

            return;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Purchase orders updated')->success()->send();
    }
}

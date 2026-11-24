<?php

namespace App\Filament\Resources\DeliveryChallans\Tables;

use App\Enums\DeliveryChallanStatus;
use App\Models\DeliveryChallan;
use App\Services\Inventory\DeliveryChallanRegister;
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
                    ->date()
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
                    ->dateTime()
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
                    ->options(fn (): array => app(DeliveryChallanRegister::class)->catalog(auth()->user(), 'customers')->pluck('name', 'id')->all())
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
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkAction::make('delete')->label('Delete')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->visible(fn (): bool => auth()->user()->can('deleteAny', DeliveryChallan::class))
                    ->action(function (Collection $records): void {
                        try {
                            app(DeliveryChallanRegister::class)->act(auth()->user(), $records->modelKeys(), 'delete', bulk: true);
                        } catch (HttpException $exception) {
                            if ($exception->getStatusCode() !== 409) {
                                throw $exception;
                            }
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Delivery challans deleted')->success()->send();
                    }),
            ])
            ->defaultSort('delivery_date', 'desc');
    }
}

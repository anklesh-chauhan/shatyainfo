<?php

namespace App\Filament\Resources\DeliveryChallans\Schemas;

use App\Enums\DeliveryChallanStatus;
use App\Models\DeliveryChallan;
use App\Models\NumberSeries;
use App\Services\Inventory\DeliveryChallanRegister;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class DeliveryChallanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('revision'),
                Section::make('Delivery Details')
                    ->disabled(fn (?DeliveryChallan $record): bool => $record !== null && ! $record->isEditable())
                    ->columns(4)
                    ->schema([
                        TextInput::make('document_number')
                            ->label('Challan Number')
                            ->default(fn (): string => NumberSeries::getNextNumber(DeliveryChallan::class))
                            ->disabled()
                            ->dehydrated(false),

                        DatePicker::make('delivery_date')
                            ->label('Delivery Date')
                            ->default(now()->toDateString())
                            ->required(),

                        Select::make('status')
                            ->options(DeliveryChallanStatus::class)
                            ->default(DeliveryChallanStatus::Draft)
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('sales_invoice_id')
                            ->label('Sales Invoice')
                            ->options(fn (): array => app(DeliveryChallanRegister::class)->catalog(auth()->user(), 'sales-invoices')->pluck('document_number', 'id')->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, ?DeliveryChallan $record): void {
                                if (! $state) {
                                    $set('lines', []);

                                    return;
                                }
                                $order = app(DeliveryChallanRegister::class)->orderData(auth()->user(), (int) $state, (int) $record?->sales_invoice_id === (int) $state ? $record?->id : null);
                                $set('customer_id', $order['customer_id']);

                                $set('lines', collect($order['lines'])->map(fn (array $line): array => Arr::except($line, ['item']))->all());
                            }),

                        Select::make('customer_id')
                            ->label('Customer')
                            ->options(fn (): array => app(DeliveryChallanRegister::class)->catalog(auth()->user(), 'customers')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),

                        Select::make('location_master_id')
                            ->label('Dispatch Location')
                            ->options(fn (): array => self::locationOptions())
                            ->searchable()
                            ->required(),

                        Textarea::make('notes')->maxLength(5000)
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Items to Deliver')
                    ->disabled(fn (?DeliveryChallan $record): bool => $record !== null && ! $record->isEditable())
                    ->schema([
                        Repeater::make('lines')
                            ->default([])
                            ->columns(5)
                            ->schema([
                                Hidden::make('id'),
                                Select::make('sales_document_item_id')
                                    ->label('Invoice Line')
                                    ->options(function (Get $get): array {
                                        $salesInvoiceId = $get('../../sales_invoice_id');

                                        if (! $salesInvoiceId) {
                                            return [];
                                        }

                                        return app(DeliveryChallanRegister::class)->orderLineOptions(auth()->user(), (int) $salesInvoiceId);
                                    })
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $orderId = $get('../../sales_invoice_id');
                                        $order = $orderId ? app(DeliveryChallanRegister::class)->salesInvoice(auth()->user(), (int) $orderId) : null;
                                        $line = $order?->items()->find($state);

                                        if ($line === null) {
                                            return;
                                        }

                                        $set('item_master_id', $line->item_master_id);
                                        $set('quantity_delivered', $line->remainingQuantity());
                                        $set('unit_cost', $line->unit_price ?? $line->itemMaster?->selling_price ?? 0);
                                    }),

                                Select::make('item_master_id')
                                    ->label('Item')
                                    ->options(fn (Get $get): array => app(DeliveryChallanRegister::class)->catalog(auth()->user(), 'items', $get('../../sales_invoice_id') ? (int) $get('../../sales_invoice_id') : null)->pluck('item_name', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(2)
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $item = app(DeliveryChallanRegister::class)->catalog(auth()->user(), 'items')->find($state);

                                        if ($item === null) {
                                            return;
                                        }

                                        $set('unit_cost', $item->purchase_price ?? 0);
                                    }),

                                TextInput::make('quantity_delivered')
                                    ->label('Qty Delivered')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->required(),

                                TextInput::make('unit_cost')
                                    ->label('Unit Cost')
                                    ->numeric()
                                    ->required(),

                                TextInput::make('batch_number')
                                    ->label('Batch / Lot')->maxLength(255),

                                Textarea::make('remarks')->maxLength(2000)
                                    ->columnSpanFull()
                                    ->rows(1),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }

    protected static function locationOptions(): array
    {
        return app(DeliveryChallanRegister::class)->catalog(auth()->user(), 'locations')->orderBy('name')->pluck('name', 'id')->all();
    }
}

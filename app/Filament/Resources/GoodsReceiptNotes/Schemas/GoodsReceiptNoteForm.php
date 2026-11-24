<?php

namespace App\Filament\Resources\GoodsReceiptNotes\Schemas;

use App\Enums\GoodsReceiptNoteStatus;
use App\Models\GoodsReceiptNote;
use App\Models\NumberSeries;
use App\Services\Inventory\GoodsReceiptRegister;
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

class GoodsReceiptNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('revision'),
                Section::make('Receipt Details')
                    ->disabled(fn (?GoodsReceiptNote $record): bool => $record !== null && ! $record->isEditable())
                    ->columns(4)
                    ->schema([
                        TextInput::make('document_number')
                            ->label('GRN Number')
                            ->default(fn (): string => NumberSeries::getNextNumber(GoodsReceiptNote::class))
                            ->disabled()
                            ->dehydrated(false),

                        DatePicker::make('receipt_date')
                            ->label('Receipt Date')
                            ->default(now()->toDateString())
                            ->required(),

                        Select::make('status')
                            ->options(GoodsReceiptNoteStatus::class)
                            ->default(GoodsReceiptNoteStatus::Draft)
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('purchase_order_id')
                            ->label('Purchase Order')
                            ->options(fn (): array => app(GoodsReceiptRegister::class)->catalog(auth()->user(), 'purchase-orders')->pluck('document_number', 'id')->all())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! $state) {
                                    $set('lines', []);

                                    return;
                                }
                                $order = app(GoodsReceiptRegister::class)->orderData(auth()->user(), (int) $state);
                                $set('supplier_id', $order['supplier_id']);
                                $set('location_master_id', $order['location_master_id']);
                                $set('lines', $order['lines']);
                            }),

                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(fn (): array => app(GoodsReceiptRegister::class)->catalog(auth()->user(), 'suppliers')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),

                        Select::make('location_master_id')
                            ->label('Receiving Location')
                            ->options(fn (): array => self::locationOptions())
                            ->searchable()
                            ->required(),

                        Textarea::make('notes')->maxLength(5000)
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Received Items')
                    ->disabled(fn (?GoodsReceiptNote $record): bool => $record !== null && ! $record->isEditable())
                    ->schema([
                        Repeater::make('lines')
                            ->default([])
                            ->columns(5)
                            ->schema([
                                Hidden::make('id'),
                                Select::make('purchase_order_line_id')
                                    ->label('PO Line')
                                    ->options(function (Get $get): array {
                                        $purchaseOrderId = $get('../../purchase_order_id');

                                        if (! $purchaseOrderId) {
                                            return [];
                                        }

                                        return app(GoodsReceiptRegister::class)->orderLineOptions(auth()->user(), (int) $purchaseOrderId);
                                    })
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $orderId = $get('../../purchase_order_id');
                                        $order = $orderId ? app(GoodsReceiptRegister::class)->purchaseOrder(auth()->user(), (int) $orderId) : null;
                                        $line = $order?->lines()->find($state);

                                        if ($line === null) {
                                            return;
                                        }

                                        $set('item_master_id', $line->item_master_id);
                                        $set('quantity_received', $line->remainingQuantity());
                                        $set('unit_cost', $line->unit_price);
                                    }),

                                Select::make('item_master_id')
                                    ->label('Item')
                                    ->options(fn (): array => app(GoodsReceiptRegister::class)->catalog(auth()->user(), 'items')->pluck('item_name', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(2)
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $item = app(GoodsReceiptRegister::class)->catalog(auth()->user(), 'items')->find($state);

                                        if ($item === null) {
                                            return;
                                        }

                                        $set('unit_cost', $item->purchase_price ?? 0);
                                    }),

                                TextInput::make('quantity_received')
                                    ->label('Qty Received')
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
        return app(GoodsReceiptRegister::class)->catalog(auth()->user(), 'locations')->orderBy('name')->pluck('name', 'id')->all();
    }
}

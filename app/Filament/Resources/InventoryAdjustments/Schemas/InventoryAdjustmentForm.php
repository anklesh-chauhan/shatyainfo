<?php

namespace App\Filament\Resources\InventoryAdjustments\Schemas;

use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryAdjustment;
use App\Models\NumberSeries;
use App\Services\Inventory\StockAdjustmentRegister;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([Hidden::make('revision'),
                Section::make('Adjustment Details')->disabled(fn (?InventoryAdjustment $record): bool => $record !== null && ! $record->isEditable())
                    ->columns(3)
                    ->schema([
                        TextInput::make('adjustment_number')
                            ->label('Document No.')
                            ->default(fn (): string => NumberSeries::getNextNumber(InventoryAdjustment::class))
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('status')
                            ->options(InventoryDocumentStatus::class)
                            ->default(InventoryDocumentStatus::Draft)
                            ->disabled()
                            ->dehydrated(false),

                        Select::make('adjustment_type')
                            ->options(InventoryAdjustmentType::class)
                            ->required(),

                        Select::make('item_master_id')
                            ->label('Item')
                            ->options(fn (): array => app(StockAdjustmentRegister::class)->catalog(auth()->user(), 'items')->orderBy('item_name')->pluck('item_name', 'id')->all())
                            ->searchable()
                            ->required(),

                        Select::make('location_master_id')
                            ->label('Location')
                            ->options(fn (): array => self::locationOptions())
                            ->searchable()
                            ->required(),

                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(0.001)
                            ->step(0.001)
                            ->required(),

                        TextInput::make('unit_cost')
                            ->label('Unit Cost')->minValue(0)
                            ->numeric(),

                        TextInput::make('reason')->maxLength(255)
                            ->required()
                            ->columnSpan(2),

                        Textarea::make('remarks')->maxLength(5000)
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }

    protected static function locationOptions(): array
    {
        return app(StockAdjustmentRegister::class)->catalog(auth()->user(), 'locations')->orderBy('name')->pluck('name', 'id')->all();
    }
}

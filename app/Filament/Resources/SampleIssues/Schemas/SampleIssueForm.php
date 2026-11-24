<?php

namespace App\Filament\Resources\SampleIssues\Schemas;

use App\Enums\SampleIssueStatus;
use App\Models\ItemMaster;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Models\SampleRequestLine;
use App\Services\Inventory\SampleRequestRegister;
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
use Illuminate\Support\Str;

class SampleIssueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Issue Details')
                    ->columns(4)
                    ->schema([
                        Hidden::make('revision'),
                        TextInput::make('document_number')
                            ->label('Issue Number')
                            ->default(fn (): string => NumberSeries::getNextNumber(SampleIssue::class))
                            ->disabled()
                            ->dehydrated(),

                        DatePicker::make('issue_date')
                            ->default(now()->toDateString())
                            ->required(),

                        Select::make('status')
                            ->options(SampleIssueStatus::class)
                            ->default(SampleIssueStatus::Draft)
                            ->disabled()
                            ->dehydrated(),

                        Select::make('sample_request_id')
                            ->label('Approved Request')
                            ->options(fn (?SampleIssue $record): array => app(SampleRequestRegister::class)->issuable(auth()->user())
                                ->orderByDesc('request_date')
                                ->pluck('document_number', 'id')
                                ->union($record?->sampleRequest === null ? [] : [$record->sampleRequest->id => $record->sampleRequest->document_number])
                                ->all())
                            ->default(fn (): ?int => request()->integer('sample_request_id') ?: null)
                            ->searchable()
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! $state) {
                                    return;
                                }

                                $request = app(SampleRequestRegister::class)->issuable(auth()->user())->find((int) $state);

                                if ($request === null) {
                                    return;
                                }

                                $set('to_location_id', $request->location_master_id);
                                $set('lines', self::requestLines($request));
                            }),

                        Select::make('from_location_id')
                            ->label('Warehouse / Source')
                            ->options(fn (?SampleIssue $record): array => self::locationOptions($record?->fromLocation))
                            ->searchable()
                            ->required()
                            ->different('to_location_id'),

                        Select::make('to_location_id')
                            ->label('Representative Location')
                            ->options(fn (Get $get, ?SampleIssue $record): array => self::destinationOptions($get, $record))
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->different('from_location_id'),

                        Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
                    ])
                    ->disabled(fn (?SampleIssue $record): bool => $record !== null && ! $record->isEditable())
                    ->columnSpanFull(),

                Section::make('Approved Items')
                    ->schema([
                        Repeater::make('lines')
                            ->defaultItems(0)->maxItems(100)
                            ->columns(5)
                            ->schema([
                                Hidden::make('id'),
                                Select::make('sample_request_line_id')
                                    ->label('Request Line')
                                    ->options(function (Get $get, ?SampleIssue $record): array {
                                        $requestId = $get('../../sample_request_id');

                                        if (! $requestId) {
                                            return [];
                                        }

                                        $request = app(SampleRequestRegister::class)->issuable(auth()->user())->find((int) $requestId)
                                            ?? ($record?->sample_request_id === (int) $requestId ? $record?->sampleRequest : null);

                                        return $request?->lines()
                                            ->with('item')
                                            ->get()
                                            ->filter(fn (SampleRequestLine $line): bool => $line->remainingApprovedQuantity() > 0
                                                || ($record !== null && ! $record->isEditable()))
                                            ->mapWithKeys(fn (SampleRequestLine $line): array => [
                                                $line->id => ($line->item?->item_name ?? 'Item').' (Remaining: '.$line->remainingApprovedQuantity().')',
                                            ])
                                            ->all() ?? [];
                                    })
                                    ->searchable()
                                    ->live()
                                    ->required()
                                    ->columnSpan(2)
                                    ->afterStateUpdated(function (?string $state, Set $set): void {
                                        $requestLine = $state ? SampleRequestLine::query()
                                            ->whereIn('sample_request_id', app(SampleRequestRegister::class)->issuable(auth()->user())->select('id'))
                                            ->with('item')->find((int) $state) : null;

                                        if ($requestLine === null) {
                                            return;
                                        }

                                        $set('item_master_id', $requestLine->item_master_id);
                                        $set('quantity', $requestLine->remainingApprovedQuantity());
                                        $set('unit_cost', $requestLine->item?->purchase_price);
                                    }),

                                Select::make('item_master_id')
                                    ->options(fn (?SampleIssue $record): array => ItemMaster::query()->whereIn('id', SampleRequestLine::query()
                                        ->whereIn('sample_request_id', app(SampleRequestRegister::class)->issuable(auth()->user())->select('id'))
                                        ->select('item_master_id'))->pluck('item_name', 'id')->union(
                                            $record?->loadMissing('lines.item')->lines->pluck('item.item_name', 'item_master_id') ?? []
                                        )->all())
                                    ->disabled()
                                    ->dehydrated()
                                    ->required(),

                                TextInput::make('quantity')
                                    ->rule('decimal:0,3')->maxValue(999999999999.999)
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->required(),

                                TextInput::make('unit_cost')
                                    ->minValue(0)->maxValue(99999999999.9999)->rule('decimal:0,4')->numeric(),

                                Textarea::make('remarks')->maxLength(2000)->rows(1)->columnSpanFull(),
                            ])
                            ->disabled(fn (?SampleIssue $record): bool => $record !== null && ! $record->isEditable())
                            ->columnSpanFull(),
                    ])
                    ->disabled(fn (?SampleIssue $record): bool => $record !== null && ! $record->isEditable())
                    ->columnSpanFull(),
            ]);
    }

    public static function requestLines(SampleRequest $request): array
    {
        return $request->loadMissing('lines.item')->lines
            ->filter(fn (SampleRequestLine $line): bool => $line->remainingApprovedQuantity() > 0)
            ->mapWithKeys(fn (SampleRequestLine $line): array => [(string) Str::uuid() => [
                'sample_request_line_id' => $line->id,
                'item_master_id' => $line->item_master_id,
                'quantity' => $line->remainingApprovedQuantity(),
                'unit_cost' => $line->item?->purchase_price,
            ]])->all();
    }

    private static function locationOptions(?LocationMaster $current): array
    {
        $options = app(SampleRequestRegister::class)->catalog(auth()->user(), 'locations')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        if ($current !== null) {
            $options[$current->id] ??= $current->name;
        }

        return $options;
    }

    private static function destinationOptions(Get $get, ?SampleIssue $record): array
    {
        $destination = app(SampleRequestRegister::class)->issuable(auth()->user())
            ->with('destinationLocation')->find((int) $get('sample_request_id'))?->destinationLocation ?? $record?->toLocation;

        return $destination === null ? [] : [$destination->id => $destination->name];
    }
}

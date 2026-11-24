<?php

namespace App\Filament\Resources\SampleRequests\Schemas;

use App\Enums\SampleRequestStatus;
use App\Models\SampleRequest;
use App\Services\Inventory\SampleRequestRegister;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SampleRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Details')
                    ->columns(4)
                    ->schema([
                        Hidden::make('revision'),
                        TextInput::make('document_number')->label('Request Number')->disabled()->visibleOn('edit'),
                        DatePicker::make('request_date')->default(now()->toDateString())->required(),
                        Select::make('status')->options(SampleRequestStatus::class)->default(SampleRequestStatus::Draft)->disabled(),
                        self::selection('employee_id', 'Representative', 'employees', 'first_name', 'employee')->required(),
                        self::selection('territory_id', 'Territory', 'territories', 'name', 'territory'),
                        self::selection('location_master_id', 'Destination Location', 'locations', 'name', 'destinationLocation')->required(),
                        self::selection('campaign_id', 'Marketing Campaign', 'campaigns', 'name', 'campaign'),
                        Textarea::make('purpose')->required()->maxLength(5000)->columnSpanFull(),
                    ])
                    ->disabled(fn (?SampleRequest $record): bool => $record !== null && ! $record->isEditable())
                    ->columnSpanFull(),

                Section::make('Requested Samples')
                    ->schema([
                        Repeater::make('lines')->columns(5)->defaultItems(0)->maxItems(100)->schema([
                            Hidden::make('id'),
                            Select::make('item_master_id')->label('Item Type')
                                ->options(fn (?SampleRequest $record): array => app(SampleRequestRegister::class)->catalog(auth()->user(), 'items')
                                    ->orderBy('item_name')->pluck('item_name', 'id')->union(
                                        $record?->loadMissing('lines.item')->lines->filter(fn ($line) => $line->item !== null)
                                            ->pluck('item.item_name', 'item_master_id') ?? []
                                    )->all())
                                ->searchable()->required()->columnSpan(2),
                            TextInput::make('quantity_requested')->label('Requested')->numeric()
                                ->minValue(0.001)->maxValue(999999999999.999)->step(0.001)->rule('decimal:0,3')->required(),
                            TextInput::make('quantity_approved')->label('Approved')->disabled()->visibleOn('edit'),
                            TextInput::make('quantity_issued')->label('Issued')->disabled()->visibleOn('edit'),
                            Textarea::make('remarks')->rows(1)->maxLength(2000)->columnSpanFull(),
                        ]),
                    ])
                    ->disabled(fn (?SampleRequest $record): bool => $record !== null && ! $record->isEditable())
                    ->columnSpanFull(),
            ]);
    }

    private static function selection(string $field, string $label, string $type, string $column, string $relation): Select
    {
        return Select::make($field)->label($label)->searchable()->options(function (?SampleRequest $record) use ($type, $column, $relation): array {
            $label = $type === 'employees' ? 'full_name' : $column;
            $options = app(SampleRequestRegister::class)->catalog(auth()->user(), $type)
                ->orderBy($column)->get()->pluck($label, 'id');
            $current = $record?->{$relation};
            if ($current !== null && ! $options->has($current->id)) {
                $options->put($current->id, $current->{$label});
            }

            return $options->all();
        });
    }
}

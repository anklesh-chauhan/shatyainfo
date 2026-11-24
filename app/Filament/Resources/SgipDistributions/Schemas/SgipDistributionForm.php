<?php

namespace App\Filament\Resources\SgipDistributions\Schemas;

use App\Enums\SgipStockSource;
use App\Models\SgipDistribution;
use App\Models\VisitPreference;
use App\Services\Marketing\SgipRegister;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SgipDistributionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('revision'),
            Section::make('Distribution Details')->columns(3)->schema([
                self::selection('account_master_id', 'Doctor', 'doctors', 'doctor')->required()
                    ->disabled(fn (?SgipDistribution $record): bool => $record?->visit_id !== null)->dehydrated(),
                DatePicker::make('visit_date')->default(today())->required()
                    ->disabled(fn (?SgipDistribution $record): bool => $record?->visit_id !== null)->dehydrated(),
                TextInput::make('approval_status')->disabled()->default('draft'),
                self::selection('sample_issue_id', 'Sample Issue Stock', 'issues', 'sampleIssue')
                    ->visible(fn (): bool => VisitPreference::current()->sgip_stock_source === SgipStockSource::SampleIssue),
                self::selection('territory_id', 'Territory', 'territories', 'territory')
                    ->requiredWith('marketing_campaign_id')
                    ->disabled(fn (?SgipDistribution $record): bool => $record?->visit_id !== null)->dehydrated(),
                self::selection('marketing_campaign_id', 'Marketing Campaign', 'campaigns', 'marketingCampaign')->live(),
            ])->disabled(fn (?SgipDistribution $record): bool => $record !== null && ! $record->isEditable())->columnSpanFull(),
            Section::make('Samples / Gifts / Inputs')->schema([
                Repeater::make('items')->defaultItems(0)->maxItems(100)->columns(3)->schema([
                    Select::make('item_master_id')->label('Item')->searchable()->required()
                        ->options(fn (?SgipDistribution $record): array => app(SgipRegister::class)->catalog(auth()->user(), 'items', $record)
                            ->orderBy('item_name')->pluck('item_name', 'id')->union(
                                $record?->loadMissing('items.item')->items->pluck('item.item_name', 'item_master_id') ?? []
                            )->all()),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->maxValue(1000000)->required(),
                    TextInput::make('unit_value')->numeric()->minValue(0)->maxValue(999999.99)->rule('decimal:0,2')->required(),
                ]),
            ])->disabled(fn (?SgipDistribution $record): bool => $record !== null && ! $record->isEditable())->columnSpanFull(),
        ]);
    }

    private static function selection(string $field, string $label, string $type, string $relation): Select
    {
        return Select::make($field)->label($label)->searchable()->options(function (?SgipDistribution $record) use ($type, $relation): array {
            $column = $type === 'issues' ? 'document_number' : 'name';
            $options = app(SgipRegister::class)->catalog(auth()->user(), $type, $record)->orderBy($column)->pluck($column, 'id');
            $current = $record?->{$relation};
            if ($current !== null) {
                $options->put($current->id, $current->{$column});
            }

            return $options->all();
        });
    }
}

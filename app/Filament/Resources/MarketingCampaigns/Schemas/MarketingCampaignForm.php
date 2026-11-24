<?php

namespace App\Filament\Resources\MarketingCampaigns\Schemas;

use App\Enums\MarketingCampaignStatus;
use App\Models\MarketingCampaign;
use App\Models\NumberSeries;
use App\Services\Marketing\MarketingCampaignRegister;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketingCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('revision'),
                Section::make('Campaign Details')
                    ->columns(3)
                    ->schema([
                        TextInput::make('campaign_number')
                            ->label('Campaign Number')
                            ->default(fn (): string => NumberSeries::getNextNumber(MarketingCampaign::class))
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('status')
                            ->options(MarketingCampaignStatus::class)
                            ->default(MarketingCampaignStatus::Draft)
                            ->required(),

                        Select::make('promotional_scheme_id')
                            ->label('Linked Promotional Scheme')
                            ->options(fn (): array => self::options('schemes', 'name'))
                            ->searchable(),

                        DatePicker::make('start_date')
                            ->required(),

                        DatePicker::make('end_date')
                            ->required()
                            ->after('start_date'),

                        TextInput::make('total_budget')
                            ->numeric()
                            ->prefix('₹'),

                        Textarea::make('description')
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Campaign Items')
                    ->description('Sample and promotional items included in this campaign.')
                    ->schema([
                        Repeater::make('items')
                            ->columns(3)
                            ->schema([
                                Select::make('item_master_id')
                                    ->label('Item')
                                    ->options(fn (): array => self::options('items', 'item_name'))
                                    ->searchable()
                                    ->required()
                                    ->distinct(),

                                TextInput::make('total_quota')
                                    ->label('Total Quota')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),

                                TextInput::make('unit_value')
                                    ->label('Unit Value')
                                    ->numeric()
                                    ->prefix('₹'),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Territory Distribution Quotas')
                    ->description('Campaign-level planning and quota tracking across territories.')
                    ->schema([
                        Repeater::make('territory_quotas')
                            ->columns(4)
                            ->schema([
                                Select::make('territory_id')
                                    ->options(fn (): array => self::options('territories', 'name'))
                                    ->searchable()
                                    ->required(),

                                Select::make('item_master_id')
                                    ->label('Item')
                                    ->options(fn (): array => self::options('items', 'item_name'))
                                    ->searchable()
                                    ->required(),

                                TextInput::make('quota_quantity')
                                    ->label('Quota')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),

                                TextInput::make('used_quantity')
                                    ->label('Used')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->default(0),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }

    private static function options(string $type, string $label): array
    {
        return app(MarketingCampaignRegister::class)->catalog(auth()->user(), $type)
            ->orderBy($label)->pluck($label, 'id')->all();
    }
}

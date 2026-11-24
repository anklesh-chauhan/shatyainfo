<?php

namespace App\Filament\Resources\PromotionalSchemes\Schemas;

use App\Enums\PromotionalBenefitType;
use App\Enums\PromotionalSchemeAppliesTo;
use App\Enums\PromotionalSchemeStatus;
use App\Enums\PromotionalSchemeType;
use App\Services\Marketing\PromotionalSchemeRegister;
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

class PromotionalSchemeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('revision'),
                Section::make('Scheme Details')
                    ->columns(3)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('scheme_type')
                            ->options(PromotionalSchemeType::class)
                            ->required()
                            ->live(),

                        Select::make('status')
                            ->options(PromotionalSchemeStatus::class)
                            ->default(PromotionalSchemeStatus::Draft)
                            ->required(),

                        DatePicker::make('valid_from'),
                        DatePicker::make('valid_to'),

                        TextInput::make('min_order_value')
                            ->label('Minimum Order Value')
                            ->numeric()
                            ->prefix('₹'),

                        Textarea::make('description')
                            ->columnSpanFull(),
                    ])->columnSpanFull(),

                Section::make('Applicability')
                    ->columns(2)
                    ->schema([
                        Select::make('applies_to')
                            ->options(PromotionalSchemeAppliesTo::class)
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::appliesToValue($state))
                            ->default(PromotionalSchemeAppliesTo::Global->value)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('applies_to_id', null);
                            }),

                        Select::make('applies_to_id')
                            ->label('Target')
                            ->options(fn (Get $get): array => self::options(self::appliesToValue($get('applies_to'))))
                            ->searchable()
                            ->visible(fn (Get $get): bool => self::hasSpecificTarget($get('applies_to')))
                            ->required(fn (Get $get): bool => self::hasSpecificTarget($get('applies_to'))),
                    ])->columnSpanFull(),

                Section::make('Benefits')
                    ->description('Structured promotional benefits beyond line-level discounts.')
                    ->schema([
                        Repeater::make('benefits')
                            ->reorderable(false)
                            ->columns(4)
                            ->schema([
                                Hidden::make('id'),
                                Select::make('benefit_type')
                                    ->options(PromotionalBenefitType::class)
                                    ->required(),

                                Select::make('item_master_id')
                                    ->label('Item')
                                    ->options(fn (): array => self::options('item'))
                                    ->searchable(),

                                TextInput::make('buy_quantity')
                                    ->numeric()
                                    ->minValue(0),

                                TextInput::make('get_quantity')
                                    ->numeric()
                                    ->minValue(0),

                                TextInput::make('discount_value')
                                    ->numeric()
                                    ->minValue(0),

                                TextInput::make('min_quantity')
                                    ->numeric()
                                    ->minValue(0),

                                TextInput::make('max_quantity')
                                    ->numeric()
                                    ->minValue(0),

                                Textarea::make('remarks')
                                    ->rows(1)
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }

    private static function options(?string $type): array
    {
        if (! in_array($type, ['customer', 'territory', 'item'], true)) {
            return [];
        }
        $label = $type === 'item' ? 'item_name' : 'name';

        return app(PromotionalSchemeRegister::class)->catalog(auth()->user(), $type)
            ->orderBy($label)->pluck($label, 'id')->all();
    }

    private static function appliesToValue(mixed $appliesTo): ?string
    {
        if ($appliesTo instanceof PromotionalSchemeAppliesTo) {
            return $appliesTo->value;
        }

        return is_string($appliesTo) ? $appliesTo : null;
    }

    private static function hasSpecificTarget(mixed $appliesTo): bool
    {
        $appliesTo = self::appliesToValue($appliesTo);

        return $appliesTo !== null && $appliesTo !== PromotionalSchemeAppliesTo::Global->value;
    }
}

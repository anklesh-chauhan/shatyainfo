<?php

namespace App\Filament\Resources\ExpenseConfigurations\Schemas;

use App\Enums\ExpenseConfigurationConditionKey;
use App\Models\CityClass;
use App\Models\VisitPurpose;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;

// use Filament\Forms\Components\Component;
// use Filament\Forms\Components\Select;
// use Filament\Forms\Components\TextInput;

class ExpenseConfigurationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    // ==================== STEP 1: BASIC INFORMATION ====================
                    Wizard\Step::make('Basic Information')
                        ->description('Define the expense type and calculation method')
                        ->schema([
                            Section::make('Expense Details')
                                ->description('Core information about this expense rule')
                                ->schema([
                                    Grid::make(3)->schema([
                                        TextInput::make('name')
                                            ->required(),

                                        Select::make('expense_type_id')
                                            ->relationship('expenseType', 'name')
                                            ->preload()
                                            ->required()
                                            ->searchable()
                                            ->label('Expense Type'),

                                        Select::make('calculation_strategy')
                                            ->options([
                                                'flat' => 'Flat Amount',
                                                'per_km' => 'Per Kilometer',
                                                'per_visit' => 'Per Visit',
                                                'slab' => 'Slab / Tier Based',
                                                'multiplier' => 'Multiplier Based',
                                            ])
                                            ->required()
                                            ->live()
                                            ->label('Calculation Strategy'),
                                    ]),

                                    Grid::make(4)->schema([
                                        TextInput::make('rate')
                                            ->numeric()
                                            ->label(fn (callable $get) => match ($get('calculation_strategy')) {
                                                'flat' => 'Flat Amount',
                                                'per_km' => 'Rate per KM',
                                                'per_visit' => 'Rate per Visit',
                                                'multiplier' => 'Multiplier Value',
                                                default => 'Rate',
                                            })
                                            ->visible(fn (callable $get) => in_array($get('calculation_strategy'), ['flat', 'per_km', 'per_visit', 'multiplier'])
                                            )
                                            ->required(fn (callable $get) => in_array($get('calculation_strategy'), ['flat', 'per_km', 'per_visit', 'multiplier'])),

                                        TextInput::make('priority')
                                            ->numeric()
                                            ->default(0)
                                            ->label('Priority')
                                            ->helperText('Higher number = executed first (lower numbers run later)'),

                                        DatePicker::make('effective_from')
                                            ->required()
                                            ->label('Effective From'),

                                        DatePicker::make('effective_to')
                                            ->label('Effective Until (optional)'),
                                    ]),
                                ])
                                ->columns(1),
                        ]),

                    // ==================== STEP 2: SCOPE ====================
                    Wizard\Step::make('Scope')
                        ->description('Who and where this rule applies to')
                        ->schema([
                            Section::make('Apply To')
                                ->description('Leave empty to apply globally, or select specific scopes')
                                ->schema([
                                    Grid::make(2)->schema([
                                        Select::make('roles')
                                            ->multiple()
                                            ->relationship('roles', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->label('Roles'),

                                        Select::make('positions')
                                            ->multiple()
                                            ->relationship('positions', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->label('Positions'),
                                    ]),

                                    Grid::make(2)->schema([
                                        Select::make('territories')
                                            ->multiple()
                                            ->relationship('territories', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->label('Territories'),

                                        Select::make('transportModes')
                                            ->multiple()
                                            ->relationship('transportModes', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->label('Transport Modes'),
                                    ]),

                                    Select::make('grades')
                                        ->multiple()
                                        ->relationship('grades', 'grade_name')
                                        ->searchable()
                                        ->preload()
                                        ->label('Grades / Levels')
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    // ==================== STEP 3: CONDITIONS ====================
                    Wizard\Step::make('Conditions')
                        ->description('Dynamic rules (distance, travel type, city class, etc.)')
                        ->schema([
                            Section::make('Condition Rules')
                                ->description('The expense rule will only apply if ALL conditions are met')
                                ->schema([
                                    Repeater::make('conditions')
                                        ->relationship()
                                        ->label('Conditions')
                                        ->defaultItems(0)
                                        ->collapsible()
                                        ->cloneable()
                                        ->itemLabel(function (array $state): ?string {
                                            $rawKey = $state['condition_key'] ?? null;

                                            if (! $rawKey) {
                                                return null;
                                            }

                                            // Handle both the Enum object (from Model) and the string (from Live state)
                                            $enum = $rawKey instanceof ExpenseConfigurationConditionKey
                                                ? $rawKey
                                                : ExpenseConfigurationConditionKey::tryFrom($rawKey);

                                            $label = $enum ? $enum->getLabel() : $rawKey;
                                            $operator = $state['operator'] ?? '';
                                            $value = $state['value'] ?? '';

                                            if ($value === false) {
                                                $value = 'No';
                                            } else {
                                                $value = 'Yes';
                                            }

                                            return trim("{$label} {$operator} {$value}");
                                        })
                                        ->schema([
                                            Grid::make(3)->schema([

                                                Select::make('condition_key')
                                                    ->label('Condition Type')
                                                    ->options(ExpenseConfigurationConditionKey::class) // Automatically uses cases and labels
                                                    ->required()
                                                    ->live()
                                                    ->searchable()
                                                    ->placeholder('Select a condition key'),

                                                Select::make('operator')
                                                    ->options([
                                                        '=' => '= (equals)',
                                                        '!=' => '!= (not equals)',
                                                        '>' => '> (greater than)',
                                                        '<' => '< (less than)',
                                                        '>=' => '>= (greater or equal)',
                                                        '<=' => '<= (less or equal)',
                                                    ])
                                                    ->required()
                                                    ->label('Operator'),

                                                Group::make()
                                                    ->key('dynamic_value')           // ← Very Important
                                                    ->schema(function (Get $get): array {
                                                        $conditionKey = $get('condition_key');

                                                        // Convert to string safely (handles Enum or string)
                                                        $keyValue = is_object($conditionKey) ? $conditionKey->value ?? (string) $conditionKey : (string) $conditionKey;

                                                        if ($keyValue === 'travel_type') {
                                                            return [
                                                                Select::make('value')
                                                                    ->label('Visit Purpose')
                                                                    ->options(fn (): array => VisitPurpose::query()->pluck('name', 'code')->all())
                                                                    ->searchable()
                                                                    ->required()
                                                                    ->placeholder('Select Visit Purpose'),
                                                            ];
                                                        }

                                                        if ($keyValue === 'city_class') {
                                                            return [
                                                                Select::make('value')
                                                                    ->label('City Class')
                                                                    ->options(CityClass::pluck('name', 'code')->toArray())
                                                                    ->searchable()
                                                                    ->required()
                                                                    ->placeholder('Select City Class')
                                                                    ->columnSpan(1),
                                                            ];
                                                        }

                                                        if ($keyValue === 'is_hill_station') {
                                                            return [
                                                                Toggle::make('value')
                                                                    ->label('Hill Station ?')
                                                                    ->columnSpan(1),
                                                            ];
                                                        }

                                                        return [
                                                            TextInput::make('value')
                                                                ->label('Value')
                                                                ->placeholder('e.g. 200, A, 8')
                                                                ->required()
                                                                ->string(),
                                                        ];
                                                    })
                                                    ->columnSpan(1),
                                            ]),
                                        ])
                                        ->columns(1),
                                ]),
                        ]),

                    // ==================== STEP 4: SLABS (Conditional) ====================
                    Wizard\Step::make('Slabs')
                        ->description('Tiered rates (only for Slab strategy)')
                        ->visible(fn (callable $get) => $get('calculation_strategy') === 'slab')
                        ->schema([
                            Section::make('Slab Configuration')
                                ->description('Define tiers for distance, amount, or any value-based logic')
                                ->schema([
                                    Repeater::make('slabs')
                                        ->relationship()
                                        ->label('Slab Tiers')
                                        ->minItems(1)
                                        ->cloneable()
                                        ->reorderable()
                                        ->itemLabel(fn (array $state): ?string => isset($state['min_value'], $state['max_value'])
                                                ? "From {$state['min_value']} → {$state['max_value']}"
                                                : null
                                        )
                                        ->schema([
                                            Grid::make(4)->schema([
                                                TextInput::make('min_value')
                                                    ->numeric()
                                                    ->placeholder('Min value')
                                                    ->label('Minimum'),

                                                TextInput::make('max_value')
                                                    ->numeric()
                                                    ->placeholder('Max value (leave empty for unlimited)')
                                                    ->label('Maximum'),

                                                TextInput::make('rate')
                                                    ->numeric()
                                                    ->placeholder('Rate per unit')
                                                    ->label('Rate'),

                                                TextInput::make('flat_amount')
                                                    ->numeric()
                                                    ->placeholder('Flat amount')
                                                    ->label('Flat Amount'),
                                            ]),
                                        ]),
                                ]),
                        ]),

                    // ==================== STEP 5: CONTROLS ====================
                    Wizard\Step::make('Controls & Flags')
                        ->description('Additional settings and permissions')
                        ->schema([
                            Fieldset::make('Behavior Controls')
                                ->schema([
                                    Grid::make(2)->schema([
                                        Toggle::make('requires_attachment')
                                            ->label('Requires Attachment')
                                            ->onColor('success'),

                                        Toggle::make('requires_approval')
                                            ->label('Requires Approval')
                                            ->onColor('success'),
                                    ]),

                                    Grid::make(2)->schema([
                                        Toggle::make('allow_manual_override')
                                            ->label('Allow Manual Override')
                                            ->default(true)
                                            ->onColor('success'),

                                        Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true)
                                            ->onColor('success'),
                                    ]),
                                ]),
                        ]),
                ])
                    ->columnSpanFull()
                    ->submitAction('Save Expense Rule'), // You can customize this

            ]);
    }
}

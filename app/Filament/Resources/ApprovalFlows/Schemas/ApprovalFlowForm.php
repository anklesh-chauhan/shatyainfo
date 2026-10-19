<?php

namespace App\Filament\Resources\ApprovalFlows\Schemas;

use App\Models\ApprovalSetting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApprovalFlowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Definition')
                    ->description('Approval steps require a human decision. Job seniority does not skip steps, and a flow with no actionable steps cannot auto-approve.')
                    ->schema([
                        Select::make('module')
                            ->label('Module')
                            ->options(ApprovalSetting::approvedModuleOptions())
                            ->required()
                            ->preload()
                            ->searchable()
                            ->live(),

                        Select::make('condition_type')
                            ->options([
                                'amount' => 'Amount range',
                                'unconditional' => 'Unconditional',
                            ])
                            ->default('amount')
                            ->required(),

                        TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->required(),

                        TextInput::make('version')
                            ->numeric()
                            ->default(1)
                            ->required(),

                        Toggle::make('active')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Matching')
                    ->schema([
                        Select::make('territory_id')
                            ->relationship('territory', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        TextInput::make('min_amount')
                            ->numeric()
                            ->nullable(),

                        TextInput::make('max_amount')
                            ->numeric()
                            ->nullable(),

                        DatePicker::make('effective_from')
                            ->nullable(),

                        DatePicker::make('effective_to')
                            ->rules(['nullable', 'date', 'after_or_equal:effective_from'])
                            ->nullable(),
                    ])
                    ->columns(2),
            ]);
    }
}

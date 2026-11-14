<?php

namespace App\Filament\Resources\ApprovalFlows\Schemas;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\JobRole;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ApprovalFlowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Flow name')->required()->maxLength(255),
            Select::make('modules')->multiple()->required()->options(fn (): array => ApprovalSetting::approvedModuleOptions())->searchable(),
            Select::make('route_type')->label('Approval route')->options([
                'reporting_manager' => 'Reporting manager', 'custom' => 'Custom approval',
            ])->default('reporting_manager')->required()->live(),
            Toggle::make('active')->default(true),
            Repeater::make('levels')->label('Approval levels')->defaultItems(1)->minItems(1)
                ->addActionLabel('Add approval level')->visible(fn (Get $get): bool => $get('route_type') === 'custom')
                ->columnSpanFull()->columns(2)->schema([
                    Select::make('routing_type')->label('Approver')
                        ->options(fn (Get $get, Select $component): array => array_filter(
                            ApprovalFlowStep::routingOptions(),
                            fn (string $type): bool => $type !== 'previous_manager' || array_key_first($get('../../levels') ?? []) !== $component->getContainer()->getStatePath(false),
                            ARRAY_FILTER_USE_KEY,
                        ))
                        ->default('reporting_manager')->required()->live(),
                    Select::make('job_role_id')->label('Job role')->options(fn () => JobRole::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get): bool => $get('routing_type') === 'job_role')->required()->searchable(),
                    Select::make('approver_employee_id')->label('Employee')->options(fn () => self::employeeOptions(true))
                        ->visible(fn (Get $get): bool => in_array($get('routing_type'), ['specific_employee', 'job_role'], true))
                        ->required(fn (Get $get): bool => $get('routing_type') === 'specific_employee')->searchable(),
                    Select::make('territory_scope')->options(['all' => 'Any territory', 'self' => 'Selected territory', 'children' => 'Selected territory and descendants'])
                        ->visible(fn (Get $get): bool => $get('routing_type') === 'job_role')->default('all')->required(),
                    Toggle::make('can_skip')->label('Optional level')->visible(fn (Get $get): bool => $get('routing_type') === 'job_role' && $get('../../allow_optional_role_levels')),
                ]),
            Section::make('Advanced settings')->collapsed()->columnSpanFull()->columns(2)->schema([
                Select::make('territory_id')->relationship('territory', 'name')->searchable()->placeholder('All territories'),
                Select::make('condition_type')->label('Amount condition')->options(['unconditional' => 'Unconditional', 'amount' => 'Amount range'])
                    ->default('unconditional')->required()->live(),
                TextInput::make('min_amount')->numeric()->visible(fn (Get $get): bool => $get('condition_type') === 'amount'),
                TextInput::make('max_amount')->numeric()->visible(fn (Get $get): bool => $get('condition_type') === 'amount'),
                DatePicker::make('effective_from'),
                DatePicker::make('effective_to'),
                Toggle::make('allow_optional_role_levels')->label('Allow optional job role levels')->live(),
                TextInput::make('deadline_hours')->label('Approval deadline (hours per level)')->integer()->minValue(1)->default(24)->required(),
            ]),
        ]);
    }

    public static function employeeOptions(bool $requireLogin = false): array
    {
        return Employee::query()->where('is_active', true)->when($requireLogin, fn ($query) => $query->whereHas('user'))
            ->orderBy('first_name')->orderBy('last_name')->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->full_name.' ('.$employee->employee_id.')'])->all();
    }
}

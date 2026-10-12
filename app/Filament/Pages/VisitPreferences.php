<?php

namespace App\Filament\Pages;

use App\Enums\SgipStockSource;
use App\Filament\Clusters\GlobalConfiguration\SalesMarketingConfigurationCluster;
use App\Models\LocationMaster;
use App\Models\VisitPreference;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class VisitPreferences extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.visit-preferences';

    protected static ?string $navigationLabel = 'Visit Preferences';

    protected static ?string $title = 'Visit Preferences';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $cluster = SalesMarketingConfigurationCluster::class;

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    /* ===============================
     | Load singleton
     =============================== */
    public function mount(): void
    {
        $preference = VisitPreference::current();

        $data = $preference->toArray();

        $data['field_rules'] = $this->convertDbToRepeater(
            $preference->field_rules ?? []
        );

        $this->data = $data;

        $this->form->fill($this->data);

    }

    /* ===============================
     | Form definition
     =============================== */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Sales Tour Plan')
                    ->schema([
                        Forms\Components\TextInput::make('tour_plan_future_months')
                            ->label('Months a salesperson can plan ahead')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(24)
                            ->required()
                            ->default(12)
                            ->helperText('Includes the current month. 3 allows this month and the next two.'),
                    ]),

                Section::make('DCR reporting')
                    ->description('How far back a salesperson can still open, edit, and submit a daily call report.')
                    ->schema([
                        Toggle::make('allow_backdated_dcr')
                            ->label('Allow backdated DCR')
                            ->live()
                            ->helperText('Today stays open either way. Turn this on to accept earlier days.'),
                        Forms\Components\TextInput::make('dcr_backdate_days')
                            ->label('Days back')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required(fn ($get): bool => (bool) $get('allow_backdated_dcr'))
                            ->visible(fn ($get): bool => (bool) $get('allow_backdated_dcr'))
                            ->helperText('3 allows today and the previous three days.'),
                    ])
                    ->columns(2),

                Section::make('Visit Flow')
                    ->schema([
                        Toggle::make('enable_check_in')
                            ->label('Enable Check-in'),
                        Toggle::make('enable_check_out')
                            ->label('Enable Check-out'),
                        Toggle::make('enforce_check_in_before_check_out')
                            ->label('Enforce Check-in before Check-out'),
                        Toggle::make('allow_manual_time_edit')
                            ->label('Allow Manual Time Edit'),
                        Toggle::make('enable_auto_checkout')
                            ->label('Auto Checkout')
                            ->live(),
                        Forms\Components\TimePicker::make('auto_checkout_time')
                            ->inlineLabel('Auto Checkout Time')
                            ->seconds(false)
                            ->visible(fn ($get) => $get('enable_auto_checkout')),
                    ])
                    ->columns(2),

                Section::make('Proof & Compliance')
                    ->schema([
                        Group::make()
                            ->schema([
                                Toggle::make('require_gps')
                                    ->label('Require GPS Location')
                                    ->live(),
                                Forms\Components\TextInput::make('geo_fence_radius_meters')
                                    ->label('Geo-fence Radius (meters)')
                                    ->numeric()
                                    ->inlineLabel()
                                    ->columnSpan(2)
                                    ->visible(fn ($get) => $get('require_gps')),
                            ])
                            ->columns(3),

                        Group::make()
                            ->schema([
                                Toggle::make('require_check_in_image')
                                    ->label('Require Check-in Image'),
                                Toggle::make('require_check_out_image')
                                    ->label('Require Check-out Image'),
                                Toggle::make('require_general_visit_image')
                                    ->label('Require General Visit Image'),
                            ])
                            ->columns(3)
                            ->visible(fn ($get) => $get('require_gps')),

                        Group::make()
                            ->schema([
                                Toggle::make('enforce_minimum_duration')
                                    ->label('Enforce Minimum Visit Duration')
                                    ->live(),
                                Forms\Components\TextInput::make('minimum_duration_minutes')
                                    ->label('Minimum Duration (minutes)')
                                    ->numeric()
                                    ->inlineLabel()
                                    ->columnSpan(2)
                                    ->visible(fn ($get) => $get('enforce_minimum_duration')),
                            ])
                            ->columns(3),
                    ])
                    ->columnSpanFull(),

                Section::make('Visit Field Rules')
                    ->description('Control visibility, requirement and editability')
                    ->schema([
                        Repeater::make('field_rules')
                            ->label('')
                            ->schema([
                                Select::make('field')
                                    ->hiddenLabel()
                                    ->options($this->visitFieldOptions())
                                    ->disabled()
                                    ->required(),

                                Toggle::make('visible')->default(true),
                                Toggle::make('required')->default(false),
                                Toggle::make('editable')->default(true),
                            ])
                            ->columns(4)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->collapsible(),

                Section::make('Other Rules')
                    ->schema([
                        Toggle::make('allow_rescheduling'),
                        Toggle::make('allow_cancellation'),
                    ])
                    ->columns(2),

                Section::make('SGIP Inventory')
                    ->schema([
                        Select::make('sgip_stock_source')
                            ->label('Visit Distribution Stock Source')
                            ->options(SgipStockSource::class)
                            ->default(SgipStockSource::SampleIssue)
                            ->live()
                            ->required(),

                        Select::make('sgip_hq_location_id')
                            ->label('HQ Stock Location')
                            ->options(fn (): array => LocationMaster::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(fn ($get): bool => $get('sgip_stock_source') === SgipStockSource::Headquarters->value),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /* ===============================
     | Save
     =============================== */
    public function save(): void
    {
        $preference = VisitPreference::current();

        $state = $this->data;

        // Convert repeater back to DB structure
        $state['field_rules'] = $this->convertRepeaterToDb(
            $state['field_rules'] ?? []
        );

        $preference->update($state);

        // Reload clean state
        $this->mount();

        Notification::make()
            ->title('Visit preferences saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [

            Action::make('reset')
                ->label('Reset to Default')
                ->color('gray')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Reset Visit Preferences')
                ->modalDescription('This will restore the original default configuration.')
                ->action(fn () => $this->resetToDefault()),

            Action::make('pharma')
                ->label('Pharma Preset')
                ->color('danger')
                ->icon('heroicon-o-beaker')
                ->requiresConfirmation()
                ->action(fn () => $this->applyPreset('pharma')),

            Action::make('fmcg')
                ->label('FMCG Preset')
                ->color('success')
                ->icon('heroicon-o-shopping-cart')
                ->requiresConfirmation()
                ->action(fn () => $this->applyPreset('fmcg')),

            Action::make('machinery')
                ->label('Machinery Preset')
                ->color('warning')
                ->icon('heroicon-o-cog-6-tooth')
                ->requiresConfirmation()
                ->action(fn () => $this->applyPreset('machinery')),
        ];
    }

    public function applyPreset(string $industry): void
    {
        $preset = $this->industryPresets()[$industry] ?? null;

        if (! $preset) {
            return;
        }

        $preference = VisitPreference::current();
        $preference->update($preset);

        // Refill form so UI updates immediately
        $this->form->fill($preference->fresh()->toArray());

        Notification::make()
            ->title(ucfirst($industry).' preset applied')
            ->success()
            ->send();
    }

    protected function visitFieldOptions(): array
    {
        return [
            'purpose' => 'Purpose',
            'outcome' => 'Outcome',
            'remarks' => 'Remarks',
            'next_follow_up_date' => 'Next Follow-up Date',
            'competitor_info' => 'Competitor Info',
            'order_value' => 'Order Value',
        ];
    }

    protected function convertDbToRepeater(array $dbRules): array
    {
        $fields = $this->visitFieldOptions();

        return collect($fields)
            ->map(function ($label, $field) use ($dbRules) {
                return [
                    'field' => $field,
                    'visible' => $dbRules[$field]['visible'] ?? true,
                    'required' => $dbRules[$field]['required'] ?? false,
                    'editable' => $dbRules[$field]['editable'] ?? true,
                ];
            })
            ->values()
            ->toArray();
    }

    protected function convertRepeaterToDb(array $repeaterState): array
    {
        return collect($repeaterState)
            ->filter(fn ($row) => isset($row['field']))
            ->mapWithKeys(function ($row) {
                return [
                    $row['field'] => [
                        'visible' => $row['visible'] ?? true,
                        'required' => $row['required'] ?? false,
                        'editable' => $row['editable'] ?? true,
                    ],
                ];
            })
            ->toArray();
    }

    protected function industryPresets(): array
    {
        return [
            'pharma' => [
                'enable_check_in' => true,
                'enable_check_out' => true,
                'enforce_check_in_before_check_out' => true,
                'allow_manual_time_edit' => false,
                'enable_auto_checkout' => false,
                'auto_checkout_time' => null,

                'require_gps' => true,
                'geo_fence_radius_meters' => 150,

                'require_check_in_image' => true,
                'require_check_out_image' => true,
                'require_customer_image' => false,

                'enforce_minimum_duration' => true,
                'minimum_duration_minutes' => 5,

                'allow_rescheduling' => false,
                'allow_cancellation' => false,
                'require_visit_outcome' => true,
                'allow_backdated_dcr' => true,
                'dcr_backdate_days' => 3,

                'field_rules' => [
                    'purpose' => ['visible' => true, 'required' => true, 'editable' => true],
                    'outcome' => ['visible' => true, 'required' => true, 'editable' => true],
                    'remarks' => ['visible' => true, 'required' => false, 'editable' => true],
                    'next_follow_up_date' => ['visible' => true, 'required' => true, 'editable' => true],
                    'competitor_info' => ['visible' => false, 'required' => false, 'editable' => false],
                ],
            ],

            'fmcg' => [
                'enable_check_in' => true,
                'enable_check_out' => true,
                'enforce_check_in_before_check_out' => false,
                'allow_manual_time_edit' => true,
                'enable_auto_checkout' => false,
                'auto_checkout_time' => null,

                'require_gps' => true,
                'geo_fence_radius_meters' => 300,

                'require_check_in_image' => false,
                'require_check_out_image' => false,
                'require_customer_image' => false,

                'enforce_minimum_duration' => false,

                'allow_rescheduling' => true,
                'allow_cancellation' => true,
                'require_visit_outcome' => false,
                'allow_backdated_dcr' => true,
                'dcr_backdate_days' => 1,

                'field_rules' => [
                    'purpose' => ['visible' => true, 'required' => false, 'editable' => true],
                    'outcome' => ['visible' => true, 'required' => false, 'editable' => true],
                    'remarks' => ['visible' => true, 'required' => false, 'editable' => true],
                    'next_follow_up_date' => ['visible' => false, 'required' => false, 'editable' => false],
                    'competitor_info' => ['visible' => true, 'required' => false, 'editable' => true],
                ],
            ],

            'machinery' => [
                'enable_check_in' => true,
                'enable_check_out' => true,
                'enforce_check_in_before_check_out' => false,
                'allow_manual_time_edit' => true,
                'enable_auto_checkout' => false,
                'auto_checkout_time' => null,

                'require_gps' => false,

                'require_check_in_image' => true,
                'require_check_out_image' => false,
                'require_customer_image' => true,

                'enforce_minimum_duration' => false,

                'allow_rescheduling' => true,
                'allow_cancellation' => true,
                'require_visit_outcome' => true,
                'allow_backdated_dcr' => true,
                'dcr_backdate_days' => 7,

                'field_rules' => [
                    'purpose' => ['visible' => true, 'required' => true, 'editable' => true],
                    'outcome' => ['visible' => true, 'required' => true, 'editable' => true],
                    'remarks' => ['visible' => true, 'required' => false, 'editable' => true],
                    'next_follow_up_date' => ['visible' => true, 'required' => false, 'editable' => true],
                    'competitor_info' => ['visible' => true, 'required' => false, 'editable' => true],
                ],
            ],
        ];
    }

    public function resetToDefault(): void
    {
        $preference = VisitPreference::current();

        $preference->update(
            $this->defaultConfiguration()
        );

        $this->form->fill(
            $preference->fresh()->toArray()
        );

        Notification::make()
            ->title('Visit preferences reset to default')
            ->success()
            ->send();
    }

    protected function defaultConfiguration(): array
    {
        return [
            /* Visit Flow */
            'enable_check_in' => true,
            'enable_check_out' => true,
            'enforce_check_in_before_check_out' => true,
            'allow_manual_time_edit' => false,
            'enable_auto_checkout' => false,
            'auto_checkout_time' => null,

            /* Compliance */
            'require_gps' => true,
            'geo_fence_radius_meters' => 200,
            'require_check_in_image' => false,
            'require_check_out_image' => false,
            'require_customer_image' => false,

            /* Duration */
            'enforce_minimum_duration' => false,
            'minimum_duration_minutes' => null,

            /* Other */
            'allow_rescheduling' => true,
            'allow_cancellation' => true,
            'require_visit_outcome' => false,
            'sgip_stock_source' => SgipStockSource::SampleIssue,
            'sgip_hq_location_id' => null,
            'tour_plan_future_months' => 12,
            'allow_backdated_dcr' => true,
            'dcr_backdate_days' => 3,

            /* Field Rules */
            'field_rules' => collect($this->visitFieldOptions())
                ->map(fn () => [
                    'visible' => true,
                    'required' => false,
                    'editable' => true,
                ])
                ->toArray(),
        ];
    }
}

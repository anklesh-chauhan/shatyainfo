<?php

namespace App\Filament\Pages;

use App\Enums\FieldPlanningMode;
use App\Models\FieldPlanningRevision;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class FieldPlanningPreferences extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.field-planning-preferences';

    protected static ?string $navigationLabel = 'Field planning';

    protected static ?string $title = 'Field planning proposals';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    protected static ?int $navigationSort = 24;

    public ?array $data = [];

    #[Locked]
    public int $version = 0;

    public static function canAccess(): bool
    {
        return FieldPlanningSettingsService::canManage(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $service = app(FieldPlanningSettingsService::class);
        $latest = $service->latest(auth()->user());
        $this->version = $latest?->version ?? 0;
        $this->form->fill([
            ...($latest?->proposal() ?? $service->initialProposal()),
            'reason' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $toggles = [];
        foreach (FieldPlanningSettingsService::ruleLabels() as $key => $label) {
            $toggles[] = Toggle::make('rules.'.$key)->label($label)->required();
        }

        return $schema->components([
            Section::make('Proposed operating mode')
                ->description('Current field operations remain unchanged. Save a proposal, then activate its saved revision. Flexible planning uses optional daily/weekly customer intentions.')
                ->schema([
                    Select::make('mode')->label('Planning mode')
                        ->options(FieldPlanningSettingsService::modeOptions())->required()
                        ->helperText('Changing the mode keeps your draft rules. Use a preset button to review and replace them.'),
                    DatePicker::make('proposed_effective_on')->label('Requested start date')
                        ->minDate(today()->addDay())->nullable()
                        ->helperText('Optional. Activation uses this date; leave empty to activate immediately.'),
                ])->columns(2),
            Section::make('Proposed rules')
                ->description('MTP and DCR approval configuration remains separate. Requiring an approved plan does not change approval routing.')
                ->schema([
                    ...$toggles,
                    Select::make('rules.unplanned_calls')->label('Unplanned calls')
                        ->options(self::unplannedOptions())->required(),
                ])->columns(2),
            Section::make('Reason for change')
                ->schema([
                    Textarea::make('reason')->label('Why are these settings proposed?')
                        ->required()->maxLength(2000)->rows(3),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            app(FieldPlanningSettingsService::class)->saveProposal(
                auth()->user(), $this->form->getState(), $this->version,
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn ($messages, $key): array => [
                    str_starts_with($key, 'data.') ? $key : 'data.'.$key => $messages,
                ])->all(),
            );
        }
        $this->mount();
        Notification::make()->title('Planning proposal saved')->body('Current field operations are unchanged.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        foreach (FieldPlanningSettingsService::modeOptions() as $value => $label) {
            $mode = FieldPlanningMode::from($value);
            $rules = app(FieldPlanningService::class)->preset($mode);
            $summary = [];
            foreach (FieldPlanningSettingsService::ruleLabels() as $key => $ruleLabel) {
                $summary[] = $ruleLabel.': '.($rules[$key] ? 'Yes' : 'No');
            }
            $summary[] = 'Unplanned calls: '.self::unplannedOptions()[$rules['unplanned_calls']];
            $actions[] = Action::make('preset_'.$value)->label($label.' preset')
                ->color('gray')->requiresConfirmation()
                ->modalHeading('Review '.$label.' preset')
                ->modalDescription(implode('. ', $summary).'. This replaces the mode and rules in your unsaved draft. The reason and requested date are kept.')
                ->modalSubmitActionLabel('Use in draft')
                ->action(function () use ($value, $rules): void {
                    abort_unless(static::canAccess(), 403);
                    $this->form->fill([
                        ...($this->data ?? []),
                        'mode' => $value,
                        'rules' => $rules,
                    ]);
                });
        }

        $actions[] = Action::make('activate')->label('Activate saved proposal')->requiresConfirmation()
            ->modalDescription('Activates the latest saved revision, using its requested start date or today. Unsaved form changes are not included.')
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);
                $service = app(FieldPlanningSettingsService::class);
                $revision = $service->latest(auth()->user());
                abort_unless($revision, 422, 'Save a proposal first.');
                $service->activate(auth()->user(), $revision->id, $this->version);
                Notification::make()->title('Saved planning policy activated')->success()->send();
            });

        return $actions;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'activePolicy' => app(FieldPlanningService::class)->active(),
            'revisions' => FieldPlanningRevision::query()->with('creator')->orderByDesc('version')->limit(10)->get(),
            'modeLabels' => FieldPlanningSettingsService::modeOptions(),
            'ruleLabels' => FieldPlanningSettingsService::ruleLabels(),
            'unplannedLabels' => self::unplannedOptions(),
        ];
    }

    /** @return array<string, string> */
    private static function unplannedOptions(): array
    {
        return ['allow' => 'Allowed', 'allow_with_reason' => 'Allowed with a reason', 'block' => 'Blocked'];
    }
}

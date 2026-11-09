<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\LocationMaster;
use App\Models\User;
use App\Services\Field\FieldFlexiblePlanningService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldTeamOnboardingService;
use App\Services\Field\FieldVisitService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class FlexiblePlanning extends Page implements HasForms
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'Field Operations';

    use InteractsWithForms;

    protected string $view = 'filament.pages.flexible-planning';

    protected static ?string $title = 'Daily and weekly intentions';

    protected static ?string $navigationLabel = 'Flexible Planning';

    public ?array $data = [];

    public string $weekStart = '';

    public int $userId;

    public ?int $patchFilter = null;

    public ?int $hqFilter = null;

    public string $statusFilter = '';

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && app(FieldPlanningService::class)->capabilities()['supports_flexible_plans'];
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:AccountMaster') || FieldTeamOnboardingService::canManage(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->weekStart = today()->startOfWeek()->toDateString();
        $this->userId = auth()->id();
        $this->resetDraft();
    }

    public function resetDraft(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['client_plan_id' => (string) Str::uuid(), 'expected_version' => 0, 'date' => today()->toDateString(), 'status' => 'active']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('client_plan_id'), Hidden::make('expected_version'), Hidden::make('status'),
            Select::make('account_id')->label('Customer')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => AccountMaster::query()->applyVisibility('AccountMaster')->where('name', 'like', '%'.$search.'%')->limit(100)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value): ?string => AccountMaster::query()->applyVisibility('AccountMaster')->find($value)?->name)->live(),
            DatePicker::make('date')->label('Intended visit date')->required()->minDate(today())->maxDate(today()->addDays(90)),
            Select::make('patch_id')->label('Patch (optional)')->searchable()
                ->options(fn ($get): array => AccountMaster::query()->applyVisibility('AccountMaster')->find($get('account_id'))?->patches()->pluck('patches.name', 'patches.id')->all() ?? []),
            Textarea::make('notes')->maxLength(2000),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        app(FieldFlexiblePlanningService::class)->save(auth()->user(), $this->form->getState());
        $this->resetDraft();
        Notification::make()->title('Intention saved')->success()->send();
    }

    public function editIntention(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $service = app(FieldFlexiblePlanningService::class);
        $entry = $service->findOwned(auth()->user(), $id);
        abort_unless($service->resource($entry)['editable'], 422, 'This intention is read-only.');
        $this->form->fill($entry->proposal());
    }

    public function cancelIntention(int $id, int $version): void
    {
        abort_unless(static::canAccess(), 403);
        $service = app(FieldFlexiblePlanningService::class);
        $entry = $service->findOwned(auth()->user(), $id);
        $service->save(auth()->user(), [...$entry->proposal(), 'expected_version' => $version, 'status' => 'cancelled']);
        Notification::make()->title('Intention cancelled')->success()->send();
    }

    public function startVisit(int $id, int $version): void
    {
        abort_unless(static::canAccess(), 403);
        $entry = app(FieldFlexiblePlanningService::class)->findOwned(auth()->user(), $id);
        $visit = app(FieldVisitService::class)->openDirect(auth()->user(), [
            'client_call_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'flexible-plan-call|'.$entry->client_plan_id),
            'company_id' => $entry->account_master_id, 'date' => $entry->date->toDateString(),
            'flexible_plan_entry_id' => $entry->id, 'flexible_plan_version' => $version,
        ]);
        $this->redirect(VisitResource::getUrl('edit', ['record' => $visit]));
    }

    public function updatedHqFilter(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->patchFilter = null;
        if (FieldTeamOnboardingService::canManage(auth()->user()) && $this->hqFilter) {
            $this->userId = (int) (FieldUserHq::query()->where('location_master_id', $this->hqFilter)->value('user_id') ?? auth()->id());
        }
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['report' => app(FieldFlexiblePlanningService::class)->week(auth()->user(), $this->weekStart, $this->userId),
            'enabled' => app(FieldPlanningService::class)->capabilities()['supports_flexible_plans'],
            'users' => FieldTeamOnboardingService::canManage(auth()->user()) ? User::query()->when($this->hqFilter, fn ($q) => $q->whereIn('id', FieldUserHq::query()->where('location_master_id', $this->hqFilter)->select('user_id')))->orderBy('name')->get(['id', 'name']) : collect([auth()->user()]),
            'hqOptions' => LocationMaster::query()->whereIn('id', FieldUserHq::query()->when(! FieldTeamOnboardingService::canManage(auth()->user()), fn ($q) => $q->where('user_id', auth()->id()))->select('location_master_id'))->orderBy('name')->get(['id', 'name'])];
    }
}

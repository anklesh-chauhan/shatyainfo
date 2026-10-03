<?php

namespace App\Filament\Pages;

use App\Enums\SalespersonAiFeature;
use App\Models\User;
use App\Services\SalespersonAi\DayRouteOrderService;
use App\Services\SalespersonAi\TodayPriorityService;
use App\Services\SalespersonAi\TourPlanDraftService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class SalesAssistant extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Sales Assistant';

    protected static ?string $title = 'Sales Assistant';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.sales-assistant';

    /**
     * @var array<string, mixed>
     */
    public array $priorities = [];

    /**
     * @var array<string, mixed>
     */
    public array $route = [];

    /**
     * @var array<string, mixed>
     */
    public array $tourDraft = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $features = [];

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->priorities = app(TodayPriorityService::class)->forUser($user, today());
        $this->route = app(DayRouteOrderService::class)->forUser($user, today());
        $this->tourDraft = app(TourPlanDraftService::class)->forUser($user, now()->addMonth()->format('Y-m'));
        $this->features = SalespersonAiFeature::roadmap();
    }
}

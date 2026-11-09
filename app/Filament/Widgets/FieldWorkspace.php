<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\FieldCoverage;
use App\Filament\Pages\FlexiblePlanning;
use App\Filament\Pages\MyApprovals;
use App\Filament\Pages\Onboarding;
use App\Filament\Resources\SalesDcrs\SalesDcrResource;
use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\ApprovalStep;
use App\Services\CompanyLaunchService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldTeamOnboardingService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class FieldWorkspace extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.field-workspace';

    #[Locked]
    public string $workspace = 'personal';

    public function switchWorkspace(string $workspace): void
    {
        abort_unless(in_array($workspace, ['personal', 'team'], true), 422);
        $this->workspace = $workspace;
    }

    protected function getViewData(): array
    {
        return ['canSwitchWorkspace' => $this->personalStats !== [] && $this->teamStats !== []];
    }

    protected function getStats(): array
    {
        $personal = $this->personalStats;
        $team = $this->teamStats;

        return $this->workspace === 'team' ? ($team ?: $personal) : ($personal ?: $team);
    }

    /** @return list<Stat> */
    #[Computed]
    public function teamStats(): array
    {
        $stats = [];
        $canManage = FieldTeamOnboardingService::canManage(auth()->user());
        if (Onboarding::canAccess()) {
            $review = app(CompanyLaunchService::class)->readiness();
            $stats[] = Stat::make('Company launch', $review['launched_count'].' MRs launched')
                ->description($review['ready_count'].' pass setup checks · Continue setup or add another HQ')
                ->url(Onboarding::getUrl(panel: 'admin'));
        }
        if (MyApprovals::canAccess() && ($canManage || ApprovalStep::query()->where('assigned_user_id', auth()->id())->where('status', 'pending')->exists())) {
            $stats[] = Stat::make('Team review', 'My approvals')->description('Review reports waiting for your decision')
                ->url(MyApprovals::getUrl(panel: 'admin'));
        }
        if ($canManage && FieldCoverage::canAccess()) {
            $stats[] = Stat::make('Team coverage', 'Coverage')->description('Review team coverage and visit frequency')
                ->url(FieldCoverage::getUrl(panel: 'admin'));
        }

        return $stats;
    }

    /** @return list<Stat> */
    #[Computed]
    public function personalStats(): array
    {
        $stats = [];
        $planning = app(FieldPlanningService::class)->capabilities();
        if ($planning['supports_direct_calls'] && VisitResource::canCreate()) {
            $stats[] = Stat::make('My field work', 'Start visit')->description('Report a visit to an assigned customer')
                ->url(VisitResource::getUrl('create', panel: 'admin'));
        } elseif (! $planning['supports_direct_calls'] && SalesTourPlanResource::canViewAny()) {
            $stats[] = Stat::make('My field work', 'Open monthly plans')->description('Check your plan and its approval status')
                ->url(SalesTourPlanResource::getUrl(panel: 'admin'));
        }
        if ($planning['supports_flexible_plans'] && FlexiblePlanning::canAccess()) {
            $stats[] = Stat::make('Optional planning', 'Plan visits')->description('Prepare daily or weekly intentions')
                ->url(FlexiblePlanning::getUrl(panel: 'admin'));
        }
        if (SalesDcrResource::canViewAny()) {
            $stats[] = Stat::make('Daily reporting', 'My DCRs')->description('Complete and submit your daily report')
                ->url(SalesDcrResource::getUrl(panel: 'admin'));
        }
        if (FieldCoverage::canAccess() && ! FieldTeamOnboardingService::canManage(auth()->user())) {
            $stats[] = Stat::make('Customer follow-up', 'Coverage')->description('Review coverage and visit frequency')
                ->url(FieldCoverage::getUrl(panel: 'admin'));
        }

        return $stats;
    }
}

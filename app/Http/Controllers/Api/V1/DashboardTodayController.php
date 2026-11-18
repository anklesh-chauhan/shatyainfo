<?php

namespace App\Http\Controllers\Api\V1;

use App\Filament\Pages\Onboarding;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateDashboardPreferencesRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use App\Services\Field\FieldTeamOnboardingService;
use App\Services\OptionalModuleService;
use App\Services\PositionService;
use App\Services\Reports\DashboardMetrics;
use App\Services\Reports\FieldVisualReport;
use App\Support\DashboardLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardTodayController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): JsonResponse
    {
        abort_unless($request->user()->canAccessBusinessPage('View:Dashboard'), 403);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $counts = $metrics->forUser($user);

        return response()->json([
            'data' => [
                'visits_today' => $counts->visitsToday,
                'pending_dcrs' => $counts->pendingDcrs,
                'pending_approvals' => $counts->pendingApprovals,
                'punched_in_today' => $counts->punchedInToday,
            ],
        ]);
    }

    public function overview(Request $request, DashboardLayout $layout, FieldVisualReport $reports, CompanyOnboardingService $onboarding): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBusinessPage('View:Dashboard'), 403);

        $key = $request->query('period', 'last_7');
        abort_unless(is_string($key) && array_key_exists($key, FieldVisualReport::PERIODS), 422, 'Choose a Dashboard period.');
        $period = $reports->period($key);
        $charts = [];
        foreach ($layout->selected($user) as $widget) {
            $data = match ($widget) {
                'calls_by_day' => $reports->callsByDay($user, $key),
                'call_status' => $reports->callStatus($user, $key),
                'dcr_status' => $reports->dcrStatus($user, $key),
                'plan_and_calls' => $reports->planAndCalls($user, $key),
                'expenses' => $reports->expensesByType($user, $key),
                'calls_by_person' => $reports->callsByEmployee($user, $key),
                'average_calls' => $reports->averageCalls($user, $key),
            };

            $charts[$widget] = $widget === 'calls_by_day'
                ? ['labels' => $data['labels'], 'series' => [
                    ['label' => 'Completed', 'values' => $data['completed']],
                    ['label' => 'Open', 'values' => $data['open']],
                    ['label' => 'Cancelled', 'values' => $data['cancelled']],
                ]]
                : ['labels' => $data['labels'], 'series' => [
                    ['label' => $layout->options()[$widget], 'values' => $data['data']],
                ], ...array_intersect_key($data, array_flip(['total', 'team_average']))];
        }

        $reminder = null;
        if ($onboarding->shouldShowReminder($user)) {
            $domain = Tenant::current()->domain;
            $reminder = ['setup_url' => filled($domain)
                ? $request->getScheme().'://'.$domain.Onboarding::getUrl(isAbsolute: false, panel: 'admin')
                : null];
        }

        return response()->json(['data' => [
            'setup_reminder' => $reminder,
            'period' => ['key' => $key, 'from' => $period['from']->toDateString(), 'until' => $period['until']->toDateString()],
            'periods' => FieldVisualReport::PERIODS,
            'catalog' => $layout->options(),
            'selected' => $layout->selected($user),
            'charts' => (object) $charts,
        ]]);
    }

    public function dismissSetupReminder(Request $request, CompanyOnboardingService $onboarding): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBusinessPage('View:Dashboard'), 403);
        $onboarding->dismissReminder($user);

        return response()->json(['data' => ['setup_reminder' => null]]);
    }

    public function workspace(Request $request, DashboardMetrics $metrics, OptionalModuleService $modules): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canAccessBusinessPage('View:Dashboard'), 403);

        $view = $request->query('view', 'personal');
        abort_unless(in_array($view, ['personal', 'team'], true), 422, 'Choose a Dashboard workspace.');
        $canManage = FieldTeamOnboardingService::canManage($user);
        $approvals = $user->canAccessBusinessPage('View:MyApprovals')
            ? $metrics->pendingApprovalStepsQuery($user)->availableOnMobile()->count()
            : null;
        $canViewTeam = $canManage || ($approvals ?? 0) > 0;
        abort_if($view === 'team' && ! $canViewTeam, 403);

        $userIds = $view === 'personal' ? [$user->id] : ($metrics->userHasFullAccess($user)
            ? null : PositionService::getVisibleUserIdsFor($user)->values()->all());
        $counts = [];
        if ($view === 'personal' || $canManage) {
            if ($user->can(['ViewAny:Visit', 'View:Visit'])) {
                $counts['visits_today'] = $metrics->visitsToday($user, $userIds);
            }
            if ($user->can(['ViewAny:SalesDcr', 'View:SalesDcr'])) {
                $counts['pending_dcrs'] = $metrics->pendingDcrs($user, $userIds);
            }
            if ($modules->navigationEnabled('DailyAttendance') && ($view === 'personal'
                ? $user->canAccessBusinessPage('View:PunchIn')
                : $user->can('ViewAny:DailyAttendance'))) {
                $counts['punched_in_today'] = $metrics->punchedInToday($user, $userIds);
            }
        }
        if ($view === 'team' && $approvals !== null) {
            $counts['pending_approvals'] = $approvals;
        }

        return response()->json(['data' => [
            'view' => $view,
            'can_view_team' => $canViewTeam,
            'counts' => (object) $counts,
        ]]);
    }

    public function updatePreferences(UpdateDashboardPreferencesRequest $request, DashboardLayout $layout): JsonResponse
    {
        $user = $request->user();
        $widgets = $request->validated('widgets');
        $user->forceFill(['dashboard_widgets' => $widgets === null ? null : $layout->sanitize($widgets)])->save();

        return response()->json(['data' => ['selected' => $layout->selected($user)]]);
    }
}

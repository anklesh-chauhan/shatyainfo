<?php

namespace App\Services\Reports;

use App\Models\ApprovalStep;
use App\Models\DailyAttendance;
use App\Models\SalesDcr;
use App\Models\User;
use App\Models\Visit;
use App\Services\PositionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DashboardMetrics
{
    public function forUser(?User $user): DashboardMetricsResult
    {
        if (! $user) {
            return DashboardMetricsResult::empty();
        }

        if (Auth::id() !== $user->id) {
            Auth::setUser($user);
        }

        return new DashboardMetricsResult(
            visitsToday: $this->visitsToday($user),
            pendingDcrs: $this->pendingDcrs($user),
            pendingApprovals: $this->pendingApprovals($user),
            punchedInToday: $this->punchedInToday($user),
        );
    }

    /** @param list<int>|null $userIds */
    public function visitsToday(User $user, ?array $userIds = null): int
    {
        return Visit::query()
            ->applyVisibility('Visit', $user)
            ->when($userIds !== null, fn (Builder $query): Builder => $query->whereIn('employee_id', $userIds))
            ->whereDate('visit_date', today())
            ->where('visit_status', '!=', 'cancelled')
            ->count();
    }

    /** @param list<int>|null $userIds */
    public function pendingDcrs(User $user, ?array $userIds = null): int
    {
        return SalesDcr::query()
            ->applyVisibility('SalesDcr', $user)
            ->when($userIds !== null, fn (Builder $query): Builder => $query->whereIn('user_id', $userIds))
            ->whereIn('approval_status', ['draft', 'pending'])
            ->count();
    }

    public function pendingApprovals(User $user): int
    {
        return $this->pendingApprovalStepsQuery($user)->count();
    }

    public function pendingApprovalStepsQuery(User $user): Builder
    {
        return ApprovalStep::query()->currentFor($user->id);
    }

    /** @param list<int>|null $userIds */
    public function punchedInToday(User $user, ?array $userIds = null): int
    {
        $query = DailyAttendance::query()
            ->whereDate('attendance_date', today())
            ->whereNotNull('first_punch_in');

        if ($userIds === null && ! $this->userHasFullAccess($user)) {
            $userIds = PositionService::getVisibleUserIdsFor($user)->all();
        }

        if ($userIds !== null) {
            $query->whereHas(
                'employee',
                fn (Builder $employeeQuery): Builder => $employeeQuery->whereIn('login_id', $userIds)
            );
        }

        return $query->count();
    }

    public function userHasFullAccess(User $user): bool
    {
        return $user->hasRole('super_admin')
            || $user->hasRole('administration_admin')
            || $user->can('AccessAllRecords');
    }
}

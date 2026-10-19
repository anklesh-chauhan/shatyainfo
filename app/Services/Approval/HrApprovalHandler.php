<?php

namespace App\Services\Approval;

use App\Domains\Approval\Contracts\ApprovalHandler;
use App\Models\Approval;
use App\Models\HrChangeRequest;
use App\Models\LeaveAdjustment;
use App\Models\LeaveCancellation;
use App\Models\LeaveEncashment;
use App\Services\HR\HrChangeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class HrApprovalHandler implements ApprovalHandler
{
    public static function supports(): array
    {
        return [LeaveAdjustment::class, LeaveEncashment::class, LeaveCancellation::class, HrChangeRequest::class];
    }

    public function handle(Model $model, Approval $approval): void
    {
        $model->refresh();
        if ($model->approval_status === $approval->approval_status) {
            return;
        }
        if ($approval->approval_status === 'approved') {
            $this->apply($model);
        }
        $model->forceFill(['approval_status' => $approval->approval_status])->saveQuietly();
        if (isset($model->leave_type_id)) {
            Cache::forget("leave_balance:{$model->employee_id}:{$model->leave_type_id}");
        }
    }

    public function apply(Model $model): void
    {
        if ($model instanceof HrChangeRequest) {
            app(HrChangeService::class)->apply($model);
        }
        if ($model instanceof LeaveCancellation && ! $model->applied_at) {
            $leave = $model->leaveApplication()->lockForUpdate()->firstOrFail();
            if ($leave->payroll_locked || $leave->approval_status !== 'approved') {
                throw ValidationException::withMessages(['leave' => 'Only unlocked, approved leave can be cancelled.']);
            }
            $leave->forceFill(['approval_status' => 'cancelled', 'revoked_at' => now()])->saveQuietly();
            $leave->instances()->update(['approval_status' => 'cancelled']);
            $model->forceFill(['applied_at' => now()])->saveQuietly();
            Cache::forget("leave_balance:{$leave->employee_id}:{$leave->leave_type_id}");
        }
    }
}

<?php

namespace App\Services\Attendance;

use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\LeaveApplication;
use App\Services\Approval\ApprovalService;
use Illuminate\Support\Facades\DB;

class LeaveWorkflowService
{
    /**
     * Start approval workflow for leave application.
     *
     * @param  array  $ruleResult  Result from LeaveRuleEvaluatorService
     */
    public function start(
        LeaveApplication $leave,
        array $ruleResult
    ): void {
        DB::transaction(function () use ($leave, $ruleResult) {

            $leave->forceFill(['applied_at' => now()])->saveQuietly();
            if (ApprovalSetting::moduleRequiresApproval(LeaveApplication::class)) {
                app(ApprovalService::class)->start(
                    approvable: $leave,
                    module: 'LeaveApplication',
                    territoryId: $leave->employee?->territory_id,
                );
                $leave->forceFill(['approval_status' => 'pending'])->saveQuietly();
            } else {
                $leave->forceFill(['approval_status' => 'approved'])->saveQuietly();
                $leave->instances()->update(['approval_status' => 'approved']);
            }

            // ✅ Notifications MUST be rule-driven
            app(LeaveNotificationService::class)
                ->dispatch(
                    event: 'LEAVE_APPLIED',
                    leave: $leave,
                    ruleResult: $ruleResult
                );
        });
    }

    /**
     * Approve via email (signed URL).
     */
    public function approveFromEmail(int $approvalStepId): void
    {
        DB::transaction(function () use ($approvalStepId) {

            $step = ApprovalStep::findOrFail($approvalStepId);
            $approval = $step->approval;

            app(ApprovalService::class)
                ->approve($approval, $step->assigned_user_id);

            // ❌ No notifications here
            // Approval handlers + orchestrators decide notifications
        });
    }

    /**
     * Reject via email (signed URL).
     */
    public function rejectFromEmail(int $approvalStepId): void
    {
        DB::transaction(function () use ($approvalStepId) {

            $step = ApprovalStep::findOrFail($approvalStepId);
            $approval = $step->approval;

            app(ApprovalService::class)
                ->reject($approval, $step->assigned_user_id);

            // ❌ No notifications here
        });
    }
}

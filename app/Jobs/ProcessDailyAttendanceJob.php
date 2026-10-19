<?php

namespace App\Jobs;

use App\Models\ApprovalSetting;
use App\Models\AttendancePunch;
use App\Models\DailyAttendance;
use App\Models\HrChangeRequest;
use App\Models\PayrollLeaveSnapshot;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\PunchNormalizationService;
use App\Services\HR\HrChangeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\Multitenancy\Jobs\TenantAware;

class ProcessDailyAttendanceJob implements ShouldQueue, TenantAware
{
    public function __construct(
        public int $attendanceId
    ) {}

    public function handle(
        PunchNormalizationService $normalizer,
        AttendanceCalculationService $calculator,
        AttendanceStatusResolver $statusResolver
    ): void {
        $attendance = DailyAttendance::find($this->attendanceId);

        if (! $attendance) {
            logger()->warning('DailyAttendance not found', [
                'attendance_id' => $this->attendanceId,
            ]);

            return;
        }

        if (PayrollLeaveSnapshot::query()->where('employee_id', $attendance->employee_id)->whereDate('processed_till', '>=', $attendance->attendance_date)->exists()) {
            return;
        }

        $shift = $attendance->shift;

        $rawPunches = AttendancePunch::where('employee_id', $attendance->employee_id)
            ->whereDate('punch_date', $attendance->attendance_date)
            ->orderBy('punch_time')
            ->get()
            ->map(fn ($p) => [
                'time' => $p->punch_time,
                'type' => $p->punch_type,
            ])
            ->toArray();

        $normalized = $normalizer->normalize($shift, $rawPunches);

        $result = $calculator->calculate(
            $shift,
            $normalized,
            [
                'is_weekly_off' => $attendance->is_weekly_off,
                'is_paid_holiday' => $attendance->is_paid_holiday,
            ]
        );

        $attendance->calculated_ot_minutes = $result['ot_minutes'] ?? 0;
        $attendance->calculated_comp_off_days = $result['comp_off_days'] ?? 0;
        if (ApprovalSetting::moduleRequiresApproval(HrChangeRequest::class)) {
            $result['ot_minutes'] = $attendance->ot_minutes;
            $result['comp_off_days'] = $attendance->comp_off_days;
        }
        $attendance->fill(array_merge($normalized, $result));

        $attendance->assignStatus([
            'final_working_minutes' => $attendance->final_working_minutes,
        ]);

        $approvedChanges = HrChangeRequest::query()
            ->where('target_type', DailyAttendance::class)
            ->where('target_id', $attendance->id)
            ->where('approval_status', 'approved')
            ->whereNotNull('applied_at')
            ->orderBy('id')->get();
        foreach ($approvedChanges as $change) {
            $attendance->fill($change->proposed_values);
        }

        HrChangeService::authorizedUpdate(fn () => $attendance->save());
    }
}

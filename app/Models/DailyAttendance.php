<?php

namespace App\Models;

use App\Services\Attendance\AttendanceStatusResolver;
use App\Traits\RequiresHrChangeApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyAttendance extends BaseModel
{
    use RequiresHrChangeApproval;

    protected $table = 'daily_attendances';

    protected $fillable = [
        'employee_id',
        'shift_master_id',
        'attendance_date',

        'first_punch_in',
        'last_punch_out',

        'actual_working_minutes',
        'late_in_minutes',
        'early_out_minutes',
        'early_in_minutes',
        'late_out_minutes',

        'final_working_minutes',
        'late_mark_count',

        'is_half_day',
        'is_absent',

        'ot_minutes',
        'comp_off_days',

        'is_weekly_off',
        'is_paid_holiday',

        'status_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',

        // show only HH:mm
        'first_punch_in' => 'datetime:H:i',
        'last_punch_out' => 'datetime:H:i',

        'is_half_day' => 'boolean',
        'is_absent' => 'boolean',
        'is_weekly_off' => 'boolean',
        'is_paid_holiday' => 'boolean',
    ];

    /* ================= RELATIONSHIPS ================= */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftMaster::class, 'shift_master_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendanceStatus::class, 'status_id');
    }

    /**
     * Raw punches for this employee on this date
     */
    public function punches(): HasMany
    {
        return $this->hasMany(
            AttendancePunch::class,
            'employee_id',
            'employee_id'
        )->whereDate('punch_date', $this->attendance_date);
    }

    /* ================= HELPERS ================= */

    /**
     * Convert attendance into input for calculation services
     */
    public function toPunchData(): array
    {
        return [
            'actual_working_minutes' => $this->actual_working_minutes,
            'late_in_minutes' => $this->late_in_minutes,
            'early_out_minutes' => $this->early_out_minutes,
            'early_in_minutes' => $this->early_in_minutes,
            'late_out_minutes' => $this->late_out_minutes,

            'is_weekly_off' => $this->is_weekly_off,
            'is_paid_holiday' => $this->is_paid_holiday,
        ];
    }

    /**
     * Assign status_id using AttendanceStatusResolver
     */
    public function assignStatus(array $context = []): void
    {
        $status = app(AttendanceStatusResolver::class)
            ->resolve(array_merge([
                'final_working_minutes' => $this->final_working_minutes,
                'is_weekly_off' => $this->is_weekly_off,
                'is_paid_holiday' => $this->is_paid_holiday,
                'is_absent' => $this->is_absent,
                'is_half_day' => $this->is_half_day,
                'single_punch' => $this->punches()->count() === 1,
            ], $context));

        $this->status_id = $status->id;
    }

    public function scopeOwnedBy($query, $user)
    {
        return $query->where('employee_id', $user->employee->id);
    }

    public function isOwnedBy($user): bool
    {
        return $this->employee_id === $user->employee->id;
    }
}

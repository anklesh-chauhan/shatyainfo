<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class LeaveApplication extends BaseModel
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $leave): void {
            if ($leave->approval()->whereIn('approval_status', ['pending', 'approved'])->exists() || $leave->payroll_locked) {
                throw ValidationException::withMessages(['leave' => 'Use the leave workflow to change submitted or payroll-locked leave.']);
            }
        });
        static::deleting(function (self $leave): void {
            if (in_array($leave->approval_status, ['pending', 'approved'], true) || $leave->payroll_locked) {
                throw ValidationException::withMessages(['leave' => 'Cancel submitted leave through its workflow.']);
            }
        });
    }

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'total_days',
        'is_half_day',
        'half_day_type',
        'substitute_user_id',
        'approval_status',
        'reason',
        'payroll_locked',
        'payroll_lock_till',
        'applied_at',
        'revoked_at',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'is_half_day' => 'boolean',
        'payroll_locked' => 'boolean',
    ];

    // 🔗 Polymorphic approval
    public function approval()
    {
        return $this->morphOne(Approval::class, 'approvable')->latestOfMany();
    }

    public function latestCancellation(): HasOne
    {
        return $this->hasOne(LeaveCancellation::class)->latestOfMany();
    }

    // 🔗 Date-wise instances
    public function instances()
    {
        return $this->hasMany(LeaveInstance::class);
    }

    // 🔗 Employee
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // 🔗 Leave Type
    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    // 🔗 Substitute
    public function substitute()
    {
        return $this->belongsTo(User::class, 'substitute_user_id');
    }
}

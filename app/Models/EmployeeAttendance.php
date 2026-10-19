<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use App\Traits\RequiresHrChangeApproval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendance extends BaseModel
{
    use HasApprovalWorkflow;
    use RequiresHrChangeApproval;

    protected $fillable = [
        'employee_id',
        'attendance_date',
        'check_in',
        'check_out',
        'total_hours',
        'status_id',
        'check_in_ip',
        'check_out_ip',
        'check_in_latitude',
        'check_in_longitude',
        'check_out_latitude',
        'check_out_longitude',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in' => 'datetime:H:i',
        'check_out' => 'datetime:H:i',
    ];

    /** 🔗 Employee Relationship */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** 🔗 Status Relationship */
    public function status(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendanceStatus::class, 'status_id');
    }

    /** ⏱️ Auto Calculate Hours */
    public function calculateTotalHours(): void
    {
        if ($this->check_in && $this->check_out) {
            $start = Carbon::parse($this->check_in);
            $end = Carbon::parse($this->check_out);
            $this->total_hours = round($start->floatDiffInHours($end), 2);
        }
    }

    /** 🔧 Model Events (Auto updating total hours) */
    protected static function booted()
    {
        static::saving(function ($attendance) {
            $attendance->calculateTotalHours();
        });
    }
}

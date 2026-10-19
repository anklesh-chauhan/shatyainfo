<?php

namespace App\Models;

use App\Traits\HasHrApproval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveEncashment extends BaseModel
{
    use HasFactory, HasHrApproval;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'days',
        'amount',
        'encashed_on',
        'payroll_month',
    ];

    protected $casts = [
        'days' => 'decimal:2',
        'amount' => 'decimal:2',
        'encashed_on' => 'date',
        'payroll_month' => 'date',
    ];

    /* Scopes */
    public function scopeForEmployee(
        Builder $q,
        int $employeeId,
        int $leaveTypeId
    ): Builder {
        return $q->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId);
    }

    public function scopeAfter(Builder $q, $date): Builder
    {
        return $q->whereDate('encashed_on', '>', $date);
    }

    /* Relations */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}

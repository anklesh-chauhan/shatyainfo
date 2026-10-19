<?php

namespace App\Models;

use App\Traits\HasHrApproval;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCancellation extends BaseModel
{
    use HasHrApproval, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = ['leave_application_id', 'employee_id', 'reason'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

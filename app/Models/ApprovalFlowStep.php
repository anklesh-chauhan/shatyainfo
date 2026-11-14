<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalFlowStep extends BaseModel
{
    protected $fillable = [
        'approval_flow_id',
        'step_order',
        'routing_type',
        'approver_employee_id',
        'job_role_id',
        'approver_user_id',
        'territory_scope',
        'can_skip',
        'sla_hours',
    ];

    public static function routingOptions(): array
    {
        return [
            'reporting_manager' => 'Reporting manager',
            'previous_manager' => 'Previous approver’s reporting manager',
            'specific_employee' => 'Specific employee',
            'job_role' => 'Job role',
        ];
    }

    protected function casts(): array
    {
        return [
            'can_skip' => 'boolean',
            'sla_hours' => 'integer',
        ];
    }

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class);
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class);
    }

    public function selectedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}

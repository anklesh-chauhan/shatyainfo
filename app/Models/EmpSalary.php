<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use App\Traits\RequiresHrChangeApproval;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmpSalary extends BaseModel
{
    use HasApprovalWorkflow, SoftDeletes;
    use RequiresHrChangeApproval;

    protected $fillable = [
        'employee_id', 'basic_salary', 'hra', 'conveyance', 'special_allowance',
        'medical_allowance', 'other_allowances', 'gross_salary', 'pf_deduction',
        'esic_deduction', 'professional_tax_deduction', 'net_salary', 'salary_frequency',
        'salary_status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'hra' => 'decimal:2',
        'conveyance' => 'decimal:2',
        'special_allowance' => 'decimal:2',
        'medical_allowance' => 'decimal:2',
        'other_allowances' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'pf_deduction' => 'decimal:2',
        'esic_deduction' => 'decimal:2',
        'professional_tax_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'salary_frequency' => 'string',
        'salary_status' => 'string',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

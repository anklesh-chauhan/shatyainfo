<?php

namespace App\Models;

use App\Services\EmployeeReportingService;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmploymentDetail extends BaseModel
{
    use HasApprovalWorkflow, SoftDeletes;

    protected $fillable = [
        'employee_id', 'ticket_no', 'department_id', 'job_title_id', 'grade_id',
        'division_ou_id', 'organizational_unit_id', 'hire_date', 'employment_type',
        'employment_status', 'resign_offer_date', 'last_working_date', 'probation_date',
        'confirm_date', 'fnf_retiring_date', 'last_increment_date', 'work_location_id', 'remarks',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'resign_offer_date' => 'date',
        'last_working_date' => 'date',
        'probation_date' => 'date',
        'confirm_date' => 'date',
        'fnf_retiring_date' => 'date',
        'last_increment_date' => 'date',
        'employment_type' => 'string',
        'employment_status' => 'string',
    ];

    protected $guarded = [
        'reporting_manager_id',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function department()
    {
        return $this->belongsTo(EmpDepartment::class, 'department_id');
    }

    public function jobTitle()
    {
        return $this->belongsTo(EmpJobTitle::class, 'job_title_id');
    }

    public function grade()
    {
        return $this->belongsTo(EmpGrade::class, 'grade_id');
    }

    public function division()
    {
        return $this->belongsTo(
            OrganizationalUnit::class,
            'division_ou_id'
        );
    }

    public function organizationalUnits()
    {
        return $this->belongsToMany(
            OrganizationalUnit::class,
            'employment_detail_ou_pivot',
            'employment_detail_id',
            'organizational_unit_id',
        )->withPivot([
            'is_primary',
            'role',
            'effective_from',
            'effective_to',
        ]);
    }

    public function workLocation()
    {
        return $this->belongsTo(LocationMaster::class, 'work_location_id');
    }

    public function reportingManager()
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    protected static function booted(): void
    {
        static::saving(function ($model) {

            /* ===============================
            | Validate Division / OU (existing)
            =============================== */
            if (
                $model->division_ou_id &&
                $model->relationLoaded('organizationalUnits') &&
                $model->organizationalUnits->isNotEmpty()
            ) {
                foreach ($model->organizationalUnits as $ou) {
                    if ($ou->parent_id !== $model->division_ou_id) {
                        throw new \InvalidArgumentException(
                            'Selected Organizational Unit does not belong to the selected Division.'
                        );
                    }
                }
            }

            $employee = $model->employee;
            $model->reporting_manager_id = $employee
                ? app(EmployeeReportingService::class)->resolve($employee)['manager']
                : null;
        });
    }
}

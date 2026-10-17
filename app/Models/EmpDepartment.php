<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmpDepartment extends BaseModel
{
    use HasApprovalWorkflow, SoftDeletes;

    protected $table = 'emp_departments';

    protected $fillable = [
        'department_name', 'department_code', 'description', 'organizational_unit_id',
        'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id',
        'is_active', 'is_deleted', 'remark', 'department_head_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function organizationalUnit()
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    public function head()
    {
        return $this->belongsTo(Employee::class, 'department_head_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function grades()
    {
        return $this->hasMany(EmpGrade::class, 'department_id');
    }

    public function jobTitles()
    {
        return $this->hasMany(EmpJobTitle::class, 'department_id');
    }

    public function employmentDetails()
    {
        return $this->hasMany(EmploymentDetail::class, 'department_id');
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Services\HR\EmployeeRegisterFields;
use App\Services\HR\HrChangeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return ['sort' => ['sometimes', Rule::in(['employee_id', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'is_active', 'created_at', 'updated_at'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'trash' => ['nullable', Rule::in(['all', 'active', 'trashed'])],
                'active' => ['nullable', Rule::in(['0', '1'])], 'gender' => ['nullable', Rule::in(['Male', 'Female', 'Other'])], 'employment_status' => ['nullable', Rule::in(['Active', 'Inactive', 'Terminated', 'Retired', 'On Leave'])],
                'employment_type' => ['nullable', Rule::in(['Permanent', 'Contract', 'Part-Time', 'Intern', 'Temporary', 'Consultant'])],
                'department_id' => ['nullable', 'integer', 'min:1'], 'job_title_id' => ['nullable', 'integer', 'min:1'], 'grade_id' => ['nullable', 'integer', 'min:1'],
                'division_ou_id' => ['nullable', 'integer', 'min:1'], 'country_id' => ['nullable', 'integer', 'min:1'], 'org_unit_ids' => ['nullable', 'array', 'max:100'], 'org_unit_ids.*' => ['integer', 'min:1'],
                'hire_from' => ['nullable', 'date_format:Y-m-d'], 'hire_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_from'],
                'increment_from' => ['nullable', 'date_format:Y-m-d'], 'increment_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:increment_from'], 'employee_id' => ['nullable', 'integer', 'min:1']];
        }
        if ($this->route('createKind') === 'grades') {
            return ['grade_name' => ['required', 'string', 'max:50']];
        }
        $revision = ['revision' => ['required', 'string', 'size:64']];
        if ($this->route('rowAction')) {
            return $revision + ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct', 'min:1']];
        }
        if ($this->route('action')) {
            return $this->route('employee') ? $revision : ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct', 'min:1']];
        }
        if ($this->route('changeType')) {
            return $revision + ['reason' => ['required', 'string', 'max:2000'], 'values' => ['required', 'array'],
                ...collect(HrChangeService::rules($this->route('changeType')))->mapWithKeys(fn (mixed $rule, string $key): array => ['values.'.$key => $rule])->all()];
        }
        if ($this->isMethod('DELETE')) {
            return $revision;
        }

        return ($this->route('employee') ? $revision : ['revision' => ['prohibited']]) + EmployeeRegisterFields::rules($this->route('section') ?? 'employee');
    }

    public function messages(): array
    {
        return ['revision.required' => 'Reload the employee before making changes.', 'employee_id.required' => 'Enter the employee code.', 'reason.required' => 'Enter a reason for the HR change.'];
    }
}

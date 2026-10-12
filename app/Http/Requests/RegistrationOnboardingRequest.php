<?php

namespace App\Http\Requests;

use App\Services\CompanyOnboardingService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegistrationOnboardingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->hasValidSignature();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'skip' => ['nullable', 'boolean'],
            'organization.name' => ['required_unless:skip,1', 'nullable', 'string', 'max:255'],
            'organization.email' => ['required_unless:skip,1', 'nullable', 'email', 'max:255'],
            'organization.phone' => ['required_unless:skip,1', 'nullable', 'string', 'max:30'],
            'employees' => ['nullable', 'array', 'max:500'],
            'employees.*.employee_id' => ['required', 'string', 'max:20', 'distinct:ignore_case'],
            'employees.*.first_name' => ['required', 'string', 'max:50'],
            'employees.*.last_name' => ['required', 'string', 'max:50'],
            'employees.*.email' => ['nullable', 'email', 'max:100', 'distinct:ignore_case'],
            'employees.*.mobile_number' => ['required', 'string', 'max:100', 'distinct'],
            'employee_csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('skip')) {
            $this->merge(['organization' => [], 'employees' => []]);

            return;
        }
        $rows = $this->input('employees', []);
        if ($this->hasFile('employee_csv')) {
            $rows = app(CompanyOnboardingService::class)->readCsv($this->file('employee_csv')->getRealPath());
        }
        $rows = array_values(array_filter((array) $rows, fn ($row) => is_array($row) && collect($row)->contains(fn ($value) => trim((string) $value) !== '')));
        foreach ($rows as &$row) {
            $row = array_map(fn ($value) => trim((string) $value), $row);
            $row['email'] = empty($row['email']) ? null : strtolower($row['email']);
        }
        unset($row);
        $this->merge(['employees' => $rows]);
    }
}

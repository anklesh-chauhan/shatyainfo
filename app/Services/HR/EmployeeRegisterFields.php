<?php

namespace App\Services\HR;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeRegisterFields
{
    public const LABELS = ['employee' => 'General', 'employment' => 'Employment', 'professional-tax' => 'Professional Tax', 'statutory' => 'Statutory IDs',
        'qualifications' => 'Qualifications', 'skills' => 'Skills', 'shifts' => 'Shift Assignments', 'week-offs' => 'Week Offs', 'banks' => 'Bank Details', 'positions' => 'Assigned Positions'];

    public static function fields(string $section): array
    {
        $specs = match ($section) {
            'employee' => [
                'employee_id' => ['text', 'Employee code', true, 20], 'first_name' => ['text', 'First name', true, 50], 'middle_name' => ['text', 'Middle name', false, 50],
                'last_name' => ['text', 'Last name', true, 50], 'email' => ['email', 'Email', true, 100], 'mobile_number' => ['text', 'Mobile number', true, 100],
                'phone_number' => ['text', 'Phone number', false, 15], 'date_of_birth' => ['date', 'Date of birth'],
                'gender' => ['select', 'Gender', false, ['Male', 'Female', 'Other']], 'marital_status' => ['select', 'Marital status', false, ['Single', 'Married', 'Divorced']],
                'blood_group' => ['select', 'Blood group', false, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']],
                'country_id' => ['picker', 'Nationality', false, 'countries'], 'contact_details' => ['textarea', 'Contact details', false, 10000],
                'emergency_contact_name' => ['text', 'Emergency contact name', false, 100], 'emergency_contact_number' => ['text', 'Emergency contact number', false, 15],
                'login_id' => ['picker', 'Login user', false, 'users'], 'explicit_reporting_manager_id' => ['picker', 'Reporting employee override', false, 'managers'],
                'is_active' => ['bool', 'Active', true], 'provision_login' => ['bool', 'Create login and send credentials if unlinked', true],
            ],
            'employment' => [
                'department_id' => ['picker', 'Department', false, 'departments'], 'job_title_id' => ['picker', 'Job title', false, 'job-titles'], 'grade_id' => ['picker', 'Grade', false, 'grades'],
                'division_ou_id' => ['picker', 'Division', false, 'divisions'], 'org_unit_ids' => ['multi', 'Organizational units', false, 'org-units'],
                'hire_date' => ['date', 'Hire date'], 'employment_type' => ['select', 'Employment type', false, ['Permanent', 'Contract', 'Part-Time', 'Intern', 'Temporary', 'Consultant']],
                'employment_status' => ['select', 'Employment status', false, ['Active', 'Inactive', 'Terminated', 'Retired', 'On Leave']],
                'resign_offer_date' => ['date', 'Resign offer date'], 'last_working_date' => ['date', 'Last working date'], 'probation_date' => ['date', 'Probation date'],
                'confirm_date' => ['date', 'Confirmation date'], 'fnf_retiring_date' => ['date', 'FNF/retiring date'], 'last_increment_date' => ['date', 'Last increment date'],
                'work_location_id' => ['picker', 'Work location', false, 'locations'], 'remarks' => ['textarea', 'Remarks', false, 2000],
            ],
            'professional-tax' => [
                'pt_flag' => ['bool', 'Professional tax enabled', true], 'pt_no' => ['text', 'PT number', false, 100], 'pt_amount' => ['money', 'PT amount'], 'pt_join_date' => ['date', 'PT join date'],
                ...self::texts(['pt_state', 'pt_city', 'pt_zone', 'pt_code', 'pt_jv_code', 'pt_jv_code_cr', 'pt_jv_code_dr']),
                'pt_remarks' => ['textarea', 'Remarks', false, 10000], 'pt_jv_code_remarks' => ['textarea', 'JV code remarks', false, 10000],
            ],
            'statutory' => [
                ...self::texts(['pan', 'uan_no', 'gratuity_code', 'pran', 'aadhar_number', 'tax_code', 'tax_exemption', 'tax_exemption_reason']),
                'group_join_date' => ['date', 'Group join date'], 'tax_exemption_validity' => ['date', 'Tax exemption validity'], 'tax_exemption_remarks' => ['textarea', 'Tax exemption remarks', false, 10000],
            ],
            'qualifications' => ['degree' => ['text', 'Degree', false, 255], 'institution' => ['text', 'Institution', false, 255], 'year_of_completion' => ['year', 'Completion year'],
                'certification' => ['text', 'Certification', false, 255], 'grade' => ['text', 'Grade', false, 50], 'percentage' => ['percent', 'Percentage'], 'remarks' => ['textarea', 'Remarks', false, 10000]],
            'skills' => ['skill_name' => ['text', 'Skill', true, 255], 'proficiency_level' => ['text', 'Proficiency', false, 255], 'remarks' => ['textarea', 'Remarks', false, 10000]],
            'shifts' => ['shift_master_id' => ['picker', 'Shift', true, 'shifts'], 'effective_from' => ['date', 'Effective from', true], 'effective_to' => ['date', 'Effective to'], 'is_current' => ['bool', 'Current', true]],
            'week-offs' => ['day_of_week' => ['select', 'Day of week', true, ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday']], 'is_active' => ['bool', 'Active', true]],
            'banks' => ['bank_name' => ['text', 'Bank name', true, 255], 'bank_account_number' => ['text', 'Account number', true, 255],
                ...self::texts(['bank_account_name', 'bank_account_ifsc_code', 'bank_account_branch', 'bank_account_swift_code', 'bank_account_iban', 'bank_account_bic', 'bank_account_phone',
                    'bank_account_address', 'bank_account_city', 'bank_account_state', 'bank_account_country', 'bank_account_zip', 'bank_account_tax_id', 'bank_account_micr_code',
                    'bank_account_rtgs_code', 'bank_account_ecs_code', 'bank_account_code', 'bank_account_type', 'bank_account_currency'], 255),
                'bank_account_country' => ['text', 'Bank account country', true, 255], 'bank_account_currency' => ['text', 'Bank account currency', true, 255],
                'bank_account_email' => ['email', 'Bank account email', false, 255], 'bank_account_status' => ['select', 'Account status', true, ['active', 'inactive']], 'remark' => ['textarea', 'Remark', false, 10000]],
            'positions' => ['position_id' => ['picker', 'Position', true, 'positions'], 'is_primary' => ['bool', 'Primary position', true]],
        };
        $fields = [];
        foreach ($specs as $key => $spec) {
            [$type, $label] = $spec;
            $fields[$key] = ['key' => $key, 'type' => $type, 'label' => $label, 'required' => $spec[2] ?? false];
            if (in_array($key, ['bank_account_country', 'bank_account_currency', 'bank_account_status'], true)) {
                $fields[$key]['default'] = match ($key) {
                    'bank_account_country' => 'India', 'bank_account_currency' => 'INR', default => 'active'
                };
            }
            if (in_array($type, ['picker', 'multi'], true)) {
                $fields[$key]['kind'] = $spec[3];
            } elseif ($type === 'select') {
                $fields[$key]['options'] = $key === 'day_of_week' ? $spec[3] : array_combine($spec[3], $spec[3]);
            } elseif (isset($spec[3])) {
                $fields[$key]['max_length'] = $spec[3];
            }
        }

        return $fields;
    }

    private static function texts(array $names, int $max = 100): array
    {
        return self::labelTexts(array_fill_keys($names, ['text', '', false, $max]));
    }

    private static function labelTexts(array $fields): array
    {
        foreach ($fields as $name => &$field) {
            $field[1] = Str::headline($name);
        }

        return $fields;
    }

    public static function rules(string $section): array
    {
        $rules = [];
        foreach (self::fields($section) as $key => $field) {
            $rules[$key] = [$field['required'] ? 'required' : 'nullable', ...match ($field['type']) {
                'bool' => ['boolean'], 'date' => ['date_format:Y-m-d'], 'picker' => ['integer', 'min:1'],
                'multi' => ['array', 'max:100'], 'select' => [Rule::in(array_keys($field['options']))],
                'money' => ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'], 'percent' => ['numeric', 'between:0,100'],
                'year' => ['integer', 'between:1900,2200'],
                default => ['string', 'max:'.$field['max_length'], ...($field['type'] === 'email' ? ['email'] : [])],
            }];
            if ($field['type'] === 'multi') {
                $rules[$key.'.*'] = ['integer', 'distinct', 'min:1'];
            }
        }
        if ($section === 'employee') {
            $rules['date_of_birth'][] = 'before_or_equal:today';
            $rules += ['image_base64' => ['nullable', 'string', 'max:1398104'], 'remove_image' => ['sometimes', 'boolean']];
        }
        if ($section === 'shifts') {
            $rules['effective_to'][] = 'after_or_equal:effective_from';
        }

        return $rules;
    }
}

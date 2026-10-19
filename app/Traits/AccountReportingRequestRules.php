<?php

namespace App\Traits;

use Illuminate\Validation\Rule;

trait AccountReportingRequestRules
{
    protected function accountReportingRules(): array
    {
        return [
            'specialty_id' => ['nullable', 'integer', Rule::exists('account_reporting_masters', 'id')->where('kind', 'specialty')->where('is_active', true)],
            'therapy_area_id' => ['nullable', 'integer', Rule::exists('account_reporting_masters', 'id')->where('kind', 'therapy_area')->where('is_active', true)],
            'institution_account_id' => ['nullable', 'integer', Rule::exists('account_masters', 'id')->whereNull('deleted_at')],
            'supplier_account_id' => ['nullable', 'integer', Rule::exists('account_masters', 'id')->whereNull('deleted_at')],
            'division_profiles' => ['sometimes', 'array', 'max:50'],
            'division_profiles.*.id' => ['sometimes', 'integer'],
            'division_profiles.*.division_ou_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'division_profiles.*.rating_type_id' => ['required', 'integer', Rule::exists('rating_types', 'id')->where('is_lifecycle', false)->whereNull('deleted_at')],
            'division_profiles.*.effective_from' => ['required', 'date'],
            'division_profiles.*.effective_to' => ['nullable', 'date'],
            'division_profiles.*.potential_value' => ['nullable', 'numeric', 'min:0'],
            'division_profiles.*.potential_unit_id' => ['nullable', 'integer', Rule::exists('account_reporting_masters', 'id')->where('kind', 'potential_unit')->where('is_active', true)],
            'division_profiles.*.potential_period' => ['nullable', Rule::in(['monthly', 'quarterly', 'annual'])],
            'division_profiles.*.currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'division_profiles.*.assessed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'call_targets' => ['sometimes', 'array', 'max:50'],
            'call_targets.*.id' => ['sometimes', 'integer'],
            'call_targets.*.calls_per_month' => ['required', 'integer', 'between:0,1000'],
            'call_targets.*.effective_from' => ['required', 'date'],
            'call_targets.*.effective_to' => ['nullable', 'date'],
        ];
    }
}

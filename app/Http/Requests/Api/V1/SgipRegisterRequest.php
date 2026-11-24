<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SgipRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'search' => ['nullable', 'string', 'max:100'],
                'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'cancelled'])],
                'trash' => ['nullable', Rule::in(['active', 'trashed', 'all'])],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
                'record_id' => ['nullable', 'integer', 'min:1'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ];
        }
        if ($this->route('action') !== null) {
            return [
                'ids' => ['required', 'array', 'min:1', $this->route('sgipDistribution') ? 'size:1' : 'max:100'],
                'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
                'revision' => [$this->route('sgipDistribution') ? 'required' : 'prohibited', 'string', 'size:64'],
            ];
        }

        return [
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'string', 'size:64'],
            'account_master_id' => ['required', 'integer', 'min:1'],
            'territory_id' => ['nullable', 'integer', 'min:1', 'required_with:marketing_campaign_id'],
            'sample_issue_id' => ['nullable', 'integer', 'min:1'],
            'marketing_campaign_id' => ['nullable', 'integer', 'min:1'],
            'visit_date' => ['required', 'date_format:Y-m-d'],
            'items' => ['present', 'array', 'list', 'max:100'],
            'items.*' => ['array:item_master_id,quantity,unit_value'],
            'items.*.item_master_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_value' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.present' => 'Include the SGIP items, or an empty list to save an unfinished draft.',
            'items.*.quantity.integer' => 'Enter a whole number of samples, gifts or inputs.',
            'items.*.unit_value.decimal' => 'Use up to two decimal places for unit values.',
            'territory_id.required_with' => 'Choose a territory when using a campaign.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('action') && $this->route('sgipDistribution')) {
            $this->merge(['ids' => [(int) $this->route('sgipDistribution')]]);
        }
    }
}

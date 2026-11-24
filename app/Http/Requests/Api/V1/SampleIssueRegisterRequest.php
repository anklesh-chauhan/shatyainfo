<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SampleIssueStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SampleIssueRegisterRequest extends FormRequest
{
    public const QUANTITY_RULES = ['required', 'numeric', 'min:0.001', 'max:999999999999.999', 'decimal:0,3'];

    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'search' => ['nullable', 'string', 'max:100'],
                'status' => ['nullable', Rule::enum(SampleIssueStatus::class)],
                'trash' => ['nullable', Rule::in(['active', 'trashed', 'all'])],
                'page' => ['sometimes', 'integer', 'min:1'],
            ];
        }

        $action = $this->route('action');
        if ($action !== null) {
            return [
                'ids' => ['required', 'array', 'min:1', $this->route('sampleIssue') ? 'size:1' : 'max:100'],
                'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
                'revision' => [$this->route('sampleIssue') ? 'required' : 'prohibited', 'string', 'size:64'],
            ];
        }

        return [
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'string', 'size:64'],
            'sample_request_id' => ['required', 'integer', 'min:1'],
            'from_location_id' => ['required', 'integer', 'min:1'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['present', 'array', 'list', 'max:100'],
            'lines.*' => ['array:id,sample_request_line_id,item_master_id,quantity,unit_cost,remarks'],
            'lines.*.id' => [$this->isMethod('PATCH') ? 'nullable' : 'prohibited', 'integer', 'distinct', 'min:1'],
            'lines.*.item_master_id' => ['required', 'integer', 'min:1'],
            'lines.*.sample_request_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => self::QUANTITY_RULES,
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.present' => 'Include the issue lines, or an empty list to save an unfinished draft.',
            'lines.*.quantity.decimal' => 'Use up to three decimal places for issue quantities.',
            'lines.*.unit_cost.decimal' => 'Use up to four decimal places for unit costs.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('action') && $this->route('sampleIssue')) {
            $this->merge(['ids' => [(int) $this->route('sampleIssue')]]);
        }
    }
}

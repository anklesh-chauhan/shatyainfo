<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SampleRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SampleRequestRegisterRequest extends FormRequest
{
    public const QUANTITY_RULES = ['required', 'numeric', 'min:0.001', 'max:999999999999.999', 'decimal:0,3'];

    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'search' => ['nullable', 'string', 'max:100'],
                'status' => ['nullable', Rule::enum(SampleRequestStatus::class)],
                'trash' => ['nullable', Rule::in(['active', 'trashed', 'all'])],
                'page' => ['sometimes', 'integer', 'min:1'],
            ];
        }

        $action = $this->route('action');
        if ($action !== null) {
            return [
                'ids' => ['required', 'array', 'min:1', $this->route('sampleRequest') ? 'size:1' : 'max:100'],
                'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
                'revision' => [$this->route('sampleRequest') ? 'required' : 'prohibited', 'string', 'size:64'],
                'approved' => [$action === 'approve' ? 'required' : 'prohibited', 'array', 'max:100'],
                'approved.*' => self::QUANTITY_RULES,
            ];
        }

        return [
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'string', 'size:64'],
            'employee_id' => ['required', 'integer', 'min:1'],
            'location_master_id' => ['required', 'integer', 'min:1'],
            'territory_id' => ['nullable', 'integer', 'min:1'],
            'campaign_id' => ['nullable', 'integer', 'min:1'],
            'request_date' => ['required', 'date_format:Y-m-d'],
            'purpose' => ['required', 'string', 'max:5000'],
            'lines' => ['present', 'array', 'list', 'max:100'],
            'lines.*' => ['array:id,item_master_id,quantity_requested,remarks'],
            'lines.*.id' => [$this->isMethod('PATCH') ? 'nullable' : 'prohibited', 'integer', 'distinct', 'min:1'],
            'lines.*.item_master_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_requested' => self::QUANTITY_RULES,
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.present' => 'Include the requested items, or an empty list to save an unfinished draft.',
            'lines.*.quantity_requested.decimal' => 'Use up to three decimal places for requested quantities.',
            'approved.required' => 'Enter an approved quantity for every request line.',
            'approved.*.decimal' => 'Use up to three decimal places for approved quantities.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('action') && $this->route('sampleRequest')) {
            $this->merge(['ids' => [(int) $this->route('sampleRequest')]]);
        }
    }
}

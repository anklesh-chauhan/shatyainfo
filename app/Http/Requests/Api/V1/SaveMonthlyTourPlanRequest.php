<?php

namespace App\Http\Requests\Api\V1;

use App\Models\VisitPreference;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SaveMonthlyTourPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->isMethod('POST') || ! is_string($value)) {
                    return;
                }

                if (! VisitPreference::current()->allowsTourPlanMonth($value)) {
                    $fail('This month is outside the allowed planning window.');
                }
            }],
            'revision' => [$this->isMethod('POST') ? 'nullable' : 'required', 'string', 'size:64'],
            'details' => ['required', 'array', 'min:1', 'max:31'],
            'details.*' => ['array:date,territory_id,patch_ids,visit_type_id,visit_purpose_ids,joint_with,remarks'],
            'details.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'details.*.territory_id' => ['present', 'nullable', 'integer'],
            'details.*.patch_ids' => ['present', 'array'],
            'details.*.patch_ids.*' => ['integer'],
            'details.*.visit_type_id' => ['present', 'nullable', 'integer'],
            'details.*.visit_purpose_ids' => ['present', 'array'],
            'details.*.visit_purpose_ids.*' => ['integer'],
            'details.*.joint_with' => ['present', 'array'],
            'details.*.joint_with.*' => ['integer'],
            'details.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['details.*.date.distinct' => 'Each date can appear only once.', 'revision.required' => 'Reload the plan before saving.'];
    }
}

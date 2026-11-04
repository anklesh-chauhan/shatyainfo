<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class StoreFieldExpenseEventRequest extends StoreDcrExpenseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'event_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'captured_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->header('Idempotency-Key') !== $this->input('event_id')) {
                $validator->errors()->add('event_id', 'Use the original expense UUID as the Idempotency-Key header.');
            }
        });
    }

    public function messages(): array
    {
        return ['captured_at.required' => 'Preserve the original expense capture time.', 'date.required' => 'Choose the original reporting date.'];
    }
}

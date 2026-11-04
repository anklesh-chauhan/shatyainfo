<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFieldDcrEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in(['meeting', 'training', 'administration', 'travel', 'leave', 'fieldwork'])],
            'remarks' => ['required', 'string', 'max:2000'],
            'captured_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->header('Idempotency-Key') !== $this->input('event_id')) {
                $validator->errors()->add('event_id', 'Use the event UUID as the Idempotency-Key header.');
            }
        });
    }
}

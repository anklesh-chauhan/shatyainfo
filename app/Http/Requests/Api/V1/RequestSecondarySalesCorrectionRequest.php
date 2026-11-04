<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestSecondarySalesCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Create:SecondarySales') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:reversal,replacement'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'reviewer_id' => ['required', 'integer', 'exists:users,id'],
            'file' => ['required_if:kind,replacement', 'prohibited_if:kind,reversal', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Explain why the approved statement must be reversed or replaced.', 'file.required_if' => 'Attach the corrected statement for a replacement.',
            'file.prohibited_if' => 'A reversal does not take a corrected file; request a replacement instead.', 'reviewer_id.required' => 'Select an authorized reviewer.'];
    }
}

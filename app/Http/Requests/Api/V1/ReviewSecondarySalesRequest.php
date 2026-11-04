<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReviewSecondarySalesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Review:SecondarySales') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['required', 'string', 'max:2000'],
            'physically_verified_line_ids' => ['sometimes', 'array'],
            'physically_verified_line_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Record the reason for the statement decision.', 'physically_verified_line_ids.*.distinct' => 'List each physically verified adjustment only once.'];
    }
}

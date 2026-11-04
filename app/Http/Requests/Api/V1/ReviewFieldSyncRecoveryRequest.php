<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReviewFieldSyncRecoveryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Review:FieldSyncRecovery') ?? false;
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
            'note' => ['required', 'string', 'min:5', 'max:2000'],
            'confirmed_no_pending_obligation' => ['sometimes', 'boolean'],
            'confirmed_replacement' => ['sometimes', 'boolean'],
            'review_token' => ['sometimes', 'nullable', 'string', 'size:64'],
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Record the reason for approving or rejecting this late request.'];
    }
}

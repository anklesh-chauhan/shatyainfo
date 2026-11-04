<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestFieldSyncRecoveryRequest extends FormRequest
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
            'reviewer_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'resolution_mode' => ['sometimes', 'in:retry_original,keep_server,replacement'],
            'confirmed_no_pending_obligation' => ['sometimes', 'boolean'],
            'confirmed_replacement' => ['sometimes', 'boolean'],
            'replacement' => ['required_if:resolution_mode,replacement', 'array:items,sample_issue_id,change_description,description,change_delivery_date,delivery_date'],
            'replacement.items' => ['sometimes', 'array', 'max:100'],
            'replacement.items.*' => ['array:item_master_id,quantity'],
            'replacement.items.*.item_master_id' => ['required', 'integer', 'distinct', 'exists:item_masters,id'],
            'replacement.items.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'replacement.sample_issue_id' => ['nullable', 'integer'],
            'replacement.change_description' => ['sometimes', 'boolean'],
            'replacement.description' => ['nullable', 'string', 'max:2000'],
            'replacement.change_delivery_date' => ['sometimes', 'boolean'],
            'replacement.delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Explain why the original captured work needs late synchronization.', 'reviewer_id.required' => 'Select an authorized manager.'];
    }
}

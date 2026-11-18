<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Approval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApprovalRegisterLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Approval::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['delete', 'restore', 'forceDelete'])],
            'ids' => ['required', 'list', 'array', 'min:1', 'max:25'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['ids.required' => 'Choose at least one approval.'];
    }
}

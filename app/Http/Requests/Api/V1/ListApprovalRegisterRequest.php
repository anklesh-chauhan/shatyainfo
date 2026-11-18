<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ApprovalStatus;
use App\Models\Approval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListApprovalRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Approval::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'trash' => ['sometimes', Rule::in(['active', 'with', 'only'])],
            'status' => ['sometimes', Rule::enum(ApprovalStatus::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['trash.in' => 'Choose active, trashed or all approvals.'];
    }
}

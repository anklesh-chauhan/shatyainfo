<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideMyApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessBusinessPage('View:MyApprovals') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comments' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['comments.required_if' => 'Enter a reason for rejecting this request.'];
    }
}

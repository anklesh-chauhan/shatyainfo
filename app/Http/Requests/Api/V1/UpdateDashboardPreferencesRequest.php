<?php

namespace App\Http\Requests\Api\V1;

use App\Support\DashboardLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessBusinessPage('View:Dashboard') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'widgets' => ['present', 'nullable', 'array', 'list'],
            'widgets.*' => ['required', 'string', 'distinct', Rule::in(array_keys(app(DashboardLayout::class)->catalog()))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'widgets.present' => 'Send the selected charts or restore all charts.',
            'widgets.*.in' => 'Choose a chart from the Dashboard catalog.',
        ];
    }
}

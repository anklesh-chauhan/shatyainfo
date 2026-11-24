<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PromotionalSchemeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionalSchemeRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->route('action') !== null) {
            return [
                'ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
                'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            ];
        }

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PromotionalSchemeStatus::class)],
            'trash' => ['sometimes', Rule::in(['active', 'trashed', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.*.distinct' => 'Select each scheme once.',
            'search.max' => 'Search using up to 100 characters.',
        ];
    }
}

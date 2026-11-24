<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;

class DeleteDealsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Deal::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one deal.',
            'ids.max' => 'Select at most 50 deals.',
            'ids.*.distinct' => 'Select each deal only once.',
        ];
    }
}

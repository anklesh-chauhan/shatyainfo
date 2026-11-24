<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerPriceRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(['ViewAny:CustomerPrice', 'View:CustomerPrice']) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'item_master_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Use up to 100 characters to search prices.',
            'per_page.between' => 'Request between 1 and 100 records per page.',
        ];
    }
}

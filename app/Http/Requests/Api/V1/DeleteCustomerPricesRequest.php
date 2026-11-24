<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCustomerPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(['ViewAny:CustomerPrice', 'View:CustomerPrice', 'Delete:CustomerPrice']) ?? false;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'list', 'between:1,50'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['ids.between' => 'Select between 1 and 50 prices.'];
    }
}

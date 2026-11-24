<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SaveCustomerPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = ['ViewAny:CustomerPrice', 'View:CustomerPrice'];
        $permissions[] = $this->isMethod('POST') ? 'Create:CustomerPrice' : 'Update:CustomerPrice';

        return $this->user()?->can($permissions) ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'customer_id' => [$required, 'required', 'integer', 'min:1'],
            'item_master_id' => [$required, 'required', 'integer', 'min:1'],
            'price' => [$required, 'required', 'numeric', 'decimal:0,2', 'between:0,9999999999999.99'],
            'discount' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Select a customer.',
            'item_master_id.required' => 'Select an item or variant.',
            'price.required' => 'Enter the customer price.',
            'price.between' => 'Enter a price between zero and 9,999,999,999,999.99.',
            'price.decimal' => 'Use at most two decimal places for the price.',
            'discount.between' => 'Enter a discount between 0 and 100 percent.',
            'discount.decimal' => 'Use at most two decimal places for the discount.',
        ];
    }
}

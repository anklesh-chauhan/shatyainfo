<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'item_master_id' => ['nullable', 'integer', 'min:1'],
            'location_master_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['item_code', 'item_name', 'location', 'quantity_on_hand', 'quantity_reserved', 'quantity_available', 'average_cost', 'last_movement_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['sort.in' => 'Choose a stock column to sort by.', 'direction.in' => 'Choose ascending or descending order.'];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockLedgerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'item_master_id' => ['nullable', 'integer', 'min:1'],
            'location_master_id' => ['nullable', 'integer', 'min:1'],
            'movement_type' => ['nullable', Rule::in(array_keys(StockLedger::TYPES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', Rule::in(['item_code', 'item_name', 'location', 'movement_at', 'movement_type', 'quantity_in', 'quantity_out', 'balance_after', 'unit_cost', 'total_value'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['sort.in' => 'Choose a ledger column to sort by.', 'until.after_or_equal' => 'The end date must be on or after the start date.', 'movement_type.in' => 'Choose a supported movement type.', 'direction.in' => 'Choose ascending or descending order.'];
    }
}

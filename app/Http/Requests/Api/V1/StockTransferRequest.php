<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InventoryDocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockTransferRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
                'item_master_id' => ['nullable', 'integer', 'min:1'], 'from_location_master_id' => ['nullable', 'integer', 'min:1'],
                'to_location_master_id' => ['nullable', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1']];
        }
        if ($this->route('action')) {
            return ['revision' => ['required', 'string', 'size:64']];
        }

        return self::saveRules($this->isMethod('PATCH'));
    }

    public static function saveRules(bool $updating): array
    {
        return ['revision' => [$updating ? 'required' : 'prohibited', 'nullable', 'string', 'size:64'],
            'item_master_id' => ['required', 'integer', 'min:1'],
            'from_location_master_id' => ['required', 'integer', 'min:1'],
            'to_location_master_id' => ['required', 'integer', 'min:1', 'different:from_location_master_id'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999999.999', 'decimal:0,3'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
            'remarks' => ['nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['to_location_master_id.different' => 'Choose different source and destination locations.',
            'quantity.decimal' => 'Use up to three decimal places for transfer quantities.',
            'unit_cost.decimal' => 'Use up to four decimal places for unit costs.'];
    }
}

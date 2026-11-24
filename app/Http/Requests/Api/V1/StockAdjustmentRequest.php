<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryDocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(InventoryDocumentStatus::class)],
                'item_master_id' => ['nullable', 'integer', 'min:1'], 'location_master_id' => ['nullable', 'integer', 'min:1'],
                'adjustment_type' => ['nullable', Rule::enum(InventoryAdjustmentType::class)], 'page' => ['sometimes', 'integer', 'min:1']];
        }
        if ($this->route('action')) {
            return $this->route('adjustment') ? ['revision' => ['required', 'string', 'size:64']] : ['ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']];
        }

        return self::saveRules($this->isMethod('PATCH'));
    }

    public static function saveRules(bool $updating): array
    {
        return ['revision' => [$updating ? 'required' : 'prohibited', 'nullable', 'string', 'size:64'],
            'item_master_id' => ['required', 'integer', 'min:1'],
            'location_master_id' => ['required', 'integer', 'min:1'],
            'adjustment_type' => ['required', Rule::enum(InventoryAdjustmentType::class)],
            'reason' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999999.999', 'decimal:0,3'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
            'remarks' => ['nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Enter the reason for this stock adjustment.',
            'quantity.decimal' => 'Use up to three decimal places for adjustment quantities.',
            'unit_cost.decimal' => 'Use up to four decimal places for unit costs.'];
    }
}

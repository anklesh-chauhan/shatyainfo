<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\GoodsReceiptNoteStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoodsReceiptRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(GoodsReceiptNoteStatus::class)],
                'supplier_id' => ['nullable', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'],
                'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'],
            ];
        }
        if ($this->route('action') !== null) {
            return $this->route('goodsReceipt') !== null
                ? ['revision' => ['required', 'string', 'size:64']]
                : ['ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']];
        }

        return self::saveRules($this->isMethod('PATCH'));
    }

    public static function saveRules(bool $updating): array
    {
        return [
            'revision' => [$updating ? 'required' : 'prohibited', 'nullable', 'string', 'size:64'],
            'purchase_order_id' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['required', 'integer', 'min:1'], 'location_master_id' => ['required', 'integer', 'min:1'],
            'receipt_date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['present', 'array', 'max:100'],
            'lines.*' => ['array:id,purchase_order_line_id,item_master_id,quantity_received,unit_cost,batch_number,remarks'],
            'lines.*.id' => [$updating ? 'nullable' : 'prohibited', 'nullable', 'integer', 'distinct', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.item_master_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0.001', 'max:999999999999.999', 'decimal:0,3'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999999.9999', 'decimal:0,4'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:255'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['lines.*.quantity_received.decimal' => 'Use up to three decimal places for received quantities.',
            'lines.*.unit_cost.decimal' => 'Use up to four decimal places for unit costs.'];
    }
}

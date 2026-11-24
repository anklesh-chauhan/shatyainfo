<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
                'supplier_id' => ['nullable', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'],
                'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d'],
            ];
        }
        if ($this->route('action') !== null) {
            return $this->route('purchaseOrder') !== null
                ? ['revision' => ['required', 'string', 'size:64']]
                : ['ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']];
        }

        return self::saveRules($this->isMethod('PATCH'));
    }

    public static function saveRules(bool $updating): array
    {
        return [
            'revision' => [$updating ? 'required' : 'prohibited', 'nullable', 'string', 'size:64'],
            'supplier_id' => ['required', 'integer', 'min:1'], 'location_master_id' => ['required', 'integer', 'min:1'],
            'payment_term_id' => ['nullable', 'integer', 'min:1'],
            'order_date' => ['required', 'date_format:Y-m-d'], 'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['present', 'array', 'max:100'],
            'lines.*' => ['array:id,item_master_id,quantity_ordered,unit_price,tax_rate,description'],
            'lines.*.id' => [$updating ? 'nullable' : 'prohibited', 'nullable', 'integer', 'distinct', 'min:1'],
            'lines.*.item_master_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_ordered' => ['required', 'numeric', 'min:0.001', 'max:999999.999', 'decimal:0,3'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.9999', 'decimal:0,4'],
            'lines.*.tax_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['currency.regex' => 'Use a three-letter uppercase currency code.',
            'lines.*.quantity_ordered.decimal' => 'Use up to three decimal places for quantities.',
            'lines.*.unit_price.decimal' => 'Use up to four decimal places for prices.'];
    }
}

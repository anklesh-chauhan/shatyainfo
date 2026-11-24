<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRegisterRequest extends FormRequest
{
    public static function itemRules(bool $updating = false): array
    {
        $rules = [
            'revision' => [$updating ? 'required' : 'prohibited', 'string', 'size:64'],
            'item_name' => ['required', 'string', 'max:255'],
            'item_type' => ['nullable', Rule::enum(ItemType::class)],
            'category_id' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:10000'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'tax_ids' => ['present', 'array', 'max:100'],
            'tax_ids.*' => ['integer', 'distinct', 'min:1'],
            'discount' => ['nullable', 'integer', 'between:0,100'],
        ];
        foreach (['sku', 'variant_name', 'hsn_code', 'barcode'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }
        foreach (['item_brand_id', 'unit_of_measurement_id', 'packaging_type_id'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:1'];
        }
        foreach (['opening_stock', 'minimum_stock_level', 'reorder_quantity', 'lead_time', 'per_packing_qty'] as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:0', 'max:2147483647'];
        }
        foreach (['purchase_price', 'selling_price'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'];
        }

        return $rules;
    }

    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return ['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
                'type' => ['nullable', Rule::enum(ItemType::class)], 'expired' => ['nullable', 'boolean'],
                'trash' => ['nullable', Rule::in(['active', 'trashed', 'all'])]];
        }
        if ($this->route('action') !== null) {
            return ['revision' => [$this->route('item') ? 'required' : 'prohibited', 'string', 'size:64'],
                'ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct', 'min:1'],
                'quantity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
                'price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2']];
        }

        return self::itemRules($this->isMethod('PATCH'));
    }

    public function messages(): array
    {
        return ['category_id.required' => 'Choose an item category.', 'tax_ids.present' => 'Include applicable taxes or an empty list.',
            'revision.required' => 'Reload the item before saving or applying this action.'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('action') && $this->route('item') && ! $this->route('relation')) {
            $this->merge(['ids' => [(int) $this->route('item')]]);
        }
    }
}

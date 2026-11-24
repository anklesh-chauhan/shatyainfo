<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SalesDocumentKind;
use App\Services\Field\FieldSalesDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $module = SalesDocumentKind::forRequest($this)->module();

        return $this->user()?->can(["ViewAny:{$module}", "View:{$module}"]) ?? false;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $kind = SalesDocumentKind::forRequest($this);
        $required = $creating ? 'required' : 'sometimes';

        return [
            'account_master_id' => [$required, 'required', 'integer', 'min:1'],
            'contact_detail_id' => [$required, 'required', 'integer', 'min:1'],
            'billing_address_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'shipping_address_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'date' => [$required, 'required', 'date_format:Y-m-d'],
            ...match ($kind) {
                SalesDocumentKind::Order => [
                    'delivery_date' => ['nullable', 'date_format:Y-m-d'],
                    'order_confirmation_at' => ['nullable', 'date_format:Y-m-d'],
                    'other_ref' => ['nullable', 'string', 'max:255'],
                ],
                SalesDocumentKind::Invoice => [
                    'due_date' => ['nullable', 'date_format:Y-m-d'],
                    'paid_at' => ['nullable', 'date_format:Y-m-d'],
                    'payment_status' => ['nullable', Rule::in(['Unpaid', 'Partially Paid', 'Paid'])],
                    'other_ref' => ['nullable', 'string', 'max:255'],
                ],
                SalesDocumentKind::Quote => [
                    'expiration_date' => ['nullable', 'date_format:Y-m-d'],
                    'accepted_at' => ['nullable', 'date_format:Y-m-d'],
                ],
            },
            'status' => ['sometimes', 'required', Rule::in(FieldSalesDocumentService::STATUSES)],
            'description' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:20000'],
            'currency' => ['sometimes', 'required', 'regex:/^[A-Z]{3}$/'],
            'sales_person_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id,deleted_at,NULL'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id,deleted_at,NULL'],
            'shipping_method_id' => ['nullable', 'integer', 'exists:shipping_methods,id,deleted_at,NULL'],
            'discount_type' => ['nullable', Rule::in(['percentage', 'amount'])],
            'discount_value' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999'],
            'shipping_cost' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999'],
            'packing_forwarding' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999'],
            'insurance_charges' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999'],
            'other_charges' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999'],
            'round_off' => ['nullable', 'numeric', 'decimal:0,2', 'between:-0.99,0.99'],
            'items' => [$required, 'array', 'list', 'max:100', ...($kind === SalesDocumentKind::Quote ? [] : ['min:1'])],
            'items.*' => ['array:id,item_master_id,quantity,description'],
            'items.*.id' => ['sometimes', $creating ? 'prohibited' : 'integer', 'min:1', 'distinct'],
            'items.*.item_master_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'between:0.001,1000000'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_master_id.required' => 'Select a customer.',
            'contact_detail_id.required' => 'Select a customer contact.',
            'items.*.quantity.between' => 'Enter a quantity between 0.001 and 1,000,000.',
            'items.*.quantity.decimal' => 'Enter a quantity with at most three decimal places.',
        ];
    }
}

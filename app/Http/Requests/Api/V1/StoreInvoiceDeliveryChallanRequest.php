<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Inventory\InvoiceDeliveryHandoff;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceDeliveryChallanRequest extends FormRequest
{
    public function authorize(InvoiceDeliveryHandoff $handoff): bool
    {
        return $handoff->canCreate($this->user());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['operation_id' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
            'location_master_id' => ['required', 'integer', 'min:1'],
            'delivery_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'list', 'between:1,100'],
            'lines.*' => ['array:sales_document_item_id,quantity_delivered'],
            'lines.*.sales_document_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity_delivered' => ['required', 'numeric', 'decimal:0,3', 'between:0.001,999999999999.999'],
        ];
    }

    public function messages(): array
    {
        return [
            'operation_id.required' => 'Provide an Idempotency-Key to create a delivery draft.',
            'location_master_id.required' => 'Select a dispatch location.',
            'delivery_date.required' => 'Select a delivery date.',
            'lines.required' => 'Add at least one invoice line to deliver.',
            'lines.*.quantity_delivered.decimal' => 'Use at most three decimal places.',
        ];
    }
}

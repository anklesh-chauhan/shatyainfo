<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Lead::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_date' => ['nullable', 'date'],
            'status_id' => ['required', 'integer', 'exists:lead_statuses,id'],
            'lead_source_id' => ['required', 'integer', 'exists:lead_sources,id'],
            'annual_revenue' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'account_master_id' => ['nullable', 'integer', 'exists:account_masters,id'],
            'contact_detail_id' => ['nullable', 'integer', 'exists:contact_details,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'territory_id' => ['nullable', 'integer', 'exists:territories,id'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leadId = (int) $this->route('lead');
        $lead = Lead::query()->find($leadId);

        if (! $lead) {
            return false;
        }

        return $this->user()?->can('update', $lead) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_date' => ['sometimes', 'date'],
            'status_id' => ['sometimes', 'integer', 'exists:lead_statuses,id'],
            'lead_source_id' => ['sometimes', 'integer', 'exists:lead_sources,id'],
            'annual_revenue' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'account_master_id' => ['nullable', 'integer', 'exists:account_masters,id'],
            'contact_detail_id' => ['nullable', 'integer', 'exists:contact_details,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'territory_id' => ['nullable', 'integer', 'exists:territories,id'],
        ];
    }
}

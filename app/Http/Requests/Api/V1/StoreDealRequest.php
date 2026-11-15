<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Deal::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deal_name' => ['required', 'string', 'max:255'],
            'transaction_date' => ['nullable', 'date'],
            'status_id' => ['required', 'integer', 'exists:deal_stages,id'],
            'type_id' => [
                'required',
                'integer',
                Rule::exists('type_masters', 'id')->where('typeable_type', Deal::class),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
            'expected_revenue' => ['required', 'numeric', 'min:0'],
            'expected_close_date' => ['required', 'date'],
            'account_master_id' => ['nullable', 'integer', 'exists:account_masters,id'],
            'contact_detail_id' => ['nullable', 'integer', 'exists:contact_details,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'description' => ['nullable', 'string'],
        ];
    }
}

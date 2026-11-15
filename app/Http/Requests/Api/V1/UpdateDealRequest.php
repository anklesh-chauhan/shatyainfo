<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dealId = (int) $this->route('deal');
        $deal = Deal::query()->find($dealId);

        if (! $deal) {
            return false;
        }

        return $this->user()?->can('update', $deal) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deal_name' => ['sometimes', 'string', 'max:255'],
            'transaction_date' => ['sometimes', 'date'],
            'status_id' => ['sometimes', 'integer', 'exists:deal_stages,id'],
            'type_id' => [
                'sometimes',
                'integer',
                Rule::exists('type_masters', 'id')->where('typeable_type', Deal::class),
            ],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'expected_revenue' => ['sometimes', 'numeric', 'min:0'],
            'expected_close_date' => ['sometimes', 'date'],
            'account_master_id' => ['nullable', 'integer', 'exists:account_masters,id'],
            'contact_detail_id' => ['nullable', 'integer', 'exists:contact_details,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'description' => ['nullable', 'string'],
        ];
    }
}

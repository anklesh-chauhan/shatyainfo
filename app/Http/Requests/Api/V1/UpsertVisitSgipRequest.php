<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpsertVisitSgipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
            'sample_issue_id' => ['nullable', 'integer', 'exists:sample_issues,id'],
            'marketing_campaign_id' => ['nullable', 'integer', 'exists:marketing_campaigns,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_master_id' => ['required', 'integer', 'exists:item_masters,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

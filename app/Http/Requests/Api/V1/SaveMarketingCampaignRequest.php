<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MarketingCampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMarketingCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::configurationRules($this->isMethod('PATCH'));
    }

    public static function configurationRules(bool $editing): array
    {
        return [
            'revision' => [$editing ? 'required' : 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(MarketingCampaignStatus::class)],
            'promotional_scheme_id' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'total_budget' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'description' => ['nullable', 'string'],
            'items' => ['present', 'array'],
            'items.*' => ['array:item_master_id,total_quota,unit_value'],
            'items.*.item_master_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.total_quota' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'items.*.unit_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'territory_quotas' => ['present', 'array'],
            'territory_quotas.*' => ['array:territory_id,item_master_id,quota_quantity'],
            'territory_quotas.*.territory_id' => ['required', 'integer', 'min:1'],
            'territory_quotas.*.item_master_id' => ['required', 'integer', 'min:1'],
            'territory_quotas.*.quota_quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision.required' => 'Reload the campaign before saving.',
            'end_date.after' => 'Choose an end date after the start date.',
            'items.*.item_master_id.distinct' => 'Choose each campaign item once.',
            'territory_quotas.*.array' => 'Only territory, item and planned quantity can be submitted for a quota.',
        ];
    }
}

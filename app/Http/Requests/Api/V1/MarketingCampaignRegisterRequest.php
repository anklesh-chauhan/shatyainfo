<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MarketingCampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketingCampaignRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(MarketingCampaignStatus::class)],
            'trash' => ['sometimes', Rule::in(['active', 'trashed', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['search.max' => 'Search using up to 100 characters.'];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PromotionalBenefitType;
use App\Enums\PromotionalSchemeAppliesTo;
use App\Enums\PromotionalSchemeStatus;
use App\Enums\PromotionalSchemeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromotionalSchemeRequest extends FormRequest
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
        $quantity = ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'];

        return [
            'revision' => [$editing ? 'required' : 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'scheme_type' => ['required', Rule::enum(PromotionalSchemeType::class)],
            'status' => ['required', Rule::enum(PromotionalSchemeStatus::class)],
            'applies_to' => ['required', Rule::enum(PromotionalSchemeAppliesTo::class)],
            'applies_to_id' => ['nullable', 'required_unless:applies_to,global', 'prohibited_if:applies_to,global', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'min_order_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'description' => ['nullable', 'string'],
            'benefits' => ['present', 'array'],
            'benefits.*' => ['array:id,benefit_type,item_master_id,buy_quantity,get_quantity,discount_value,min_quantity,max_quantity,remarks'],
            'benefits.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'benefits.*.benefit_type' => ['required', Rule::enum(PromotionalBenefitType::class)],
            'benefits.*.item_master_id' => ['nullable', 'integer', 'min:1'],
            'benefits.*.buy_quantity' => $quantity,
            'benefits.*.get_quantity' => $quantity,
            'benefits.*.min_quantity' => $quantity,
            'benefits.*.max_quantity' => $quantity,
            'benefits.*.discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'benefits.*.remarks' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision.required' => 'Reload the scheme before saving.',
            'applies_to_id.required_unless' => 'Select a target for this applicability.',
            'applies_to_id.prohibited_if' => 'A global scheme does not use a target.',
            'benefits.*.id.distinct' => 'Submit each benefit once.',
        ];
    }
}

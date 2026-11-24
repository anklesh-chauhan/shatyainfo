<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTerritorySalesAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Manage:SalesTargets') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'territory_id' => ['required', 'integer', 'exists:territories,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'territory_id.required' => 'Choose a territory.',
            'user_id.required' => 'Choose a representative.',
            'effective_from.date_format' => 'Enter an effective date in YYYY-MM-DD format.',
            'reason.required' => 'Enter an assignment reason.',
        ];
    }
}

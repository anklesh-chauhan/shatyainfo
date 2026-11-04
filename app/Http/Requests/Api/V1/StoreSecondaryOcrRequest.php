<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSecondaryOcrRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('Create:SecondarySales') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:pdf,png,jpg,jpeg', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
            'stockist_id' => ['required', 'integer', 'exists:account_masters,id'],
            'territory_id' => ['required', 'integer', 'exists:territories,id'],
            'reviewer_id' => ['required', 'integer', 'exists:users,id'],
            'period' => ['required', 'date_format:Y-m'],
        ];
    }
}

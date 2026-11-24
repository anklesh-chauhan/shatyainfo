<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;

class SaveContactRequest extends SaveAccountContactRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        if ($this->isMethod('PATCH')) {
            foreach (['first_name', 'mobile_number', 'email'] as $field) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        return [
            ...$rules,
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'birthday' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'designation_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'linkedin' => ['nullable', 'url:http,https', 'max:255'],
            'facebook' => ['nullable', 'url:http,https', 'max:255'],
            'twitter' => ['nullable', 'url:http,https', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}

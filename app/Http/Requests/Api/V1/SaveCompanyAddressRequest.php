<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;

class SaveCompanyAddressRequest extends SaveContactAddressRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['type_master_id'] = ['prohibited'];
        unset($rules['sort']);

        return $rules;
    }
}

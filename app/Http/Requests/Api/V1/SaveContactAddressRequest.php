<?php

namespace App\Http\Requests\Api\V1;

class SaveContactAddressRequest extends StoreAccountAddressRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = array_diff_key(parent::rules(), array_flip(['city_id', 'state_id', 'country_id']));
        $rules['area_town_id'][0] = 'nullable';
        $rules['pin_code'] = ['nullable', 'digits_between:1,9'];
        if ($this->isMethod('PATCH')) {
            $rules['type_master_id'][0] = 'nullable';
            $rules['street'][0] = 'sometimes';
        }

        if ($this->isMethod('POST')) {
            $rules['pin_code'][] = 'required_without:area_town_id';
        }

        return $rules;
    }
}

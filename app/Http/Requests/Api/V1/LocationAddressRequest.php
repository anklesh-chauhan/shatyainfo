<?php

namespace App\Http\Requests\Api\V1;

class LocationAddressRequest extends SaveContactAddressRequest
{
    public function rules(): array
    {
        return ['revision' => ['required', 'string', 'size:64']] + ($this->isMethod('DELETE') ? [] : parent::rules());
    }

    public function messages(): array
    {
        return ['revision.required' => 'Reload the location before changing addresses.', 'street.required' => 'Enter a street.', 'type_master_id.required' => 'Choose an address type.'];
    }
}

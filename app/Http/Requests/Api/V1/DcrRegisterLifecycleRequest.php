<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DcrRegisterLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:personal,team'],
            'action' => ['required', 'in:delete,restore,forceDelete'],
            'ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['ids.*.distinct' => 'Select each report only once.'];
    }
}

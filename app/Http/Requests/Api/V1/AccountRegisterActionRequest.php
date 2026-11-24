<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AccountRegisterActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $related = $this->routeIs('accounts.related-actions');

        return [
            'action' => ['required', $related ? 'in:detach,delete' : 'in:delete,restore,forceDelete'],
            'group' => [$related ? 'required' : 'prohibited', 'in:contacts,addresses,bank,credit,gst,statutory'],
            'ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['ids.*.distinct' => 'Select each account only once.'];
    }
}

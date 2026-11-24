<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PatchActionRequest extends FormRequest
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
        return [
            'action' => ['required', 'in:attach,attach_all,detach,sequence,trash'],
            'ids' => ['required_if:action,attach,detach,sequence,trash', 'array', 'list', 'min:1', 'max:50'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'sequence_no' => ['present_if:action,sequence', 'nullable', 'integer', 'between:1,100000'],
        ];
    }

    public function messages(): array
    {
        return ['ids.*.distinct' => 'Select each record only once.'];
    }
}

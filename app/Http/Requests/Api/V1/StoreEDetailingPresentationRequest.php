<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreEDetailingPresentationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'material_id' => ['required', 'integer', 'min:1'],
            'content_version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['content_version.required' => 'Reopen the material before recording the presentation.', 'notes.max' => 'Keep notes within 2,000 characters.'];
    }
}

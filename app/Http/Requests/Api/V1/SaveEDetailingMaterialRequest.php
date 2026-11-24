<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SaveEDetailingMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'item_master_id' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['required', 'boolean'],
            'revision' => [$this->route('material') === null ? 'nullable' : 'required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'file' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'file', 'extensions:pdf,jpg,jpeg,png', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision.required' => 'Reload this material before saving.',
            'file.required' => 'Choose a presentation file.',
            'file.mimes' => 'Use a PDF, JPG or PNG file.',
            'file.max' => 'Choose a file up to 10 MB.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SaveLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(['ViewAny:LeadActivity', 'View:LeadActivity']) ?? false;
    }

    public function rules(): array
    {
        $nested = $this->route('lead') !== null;
        $selector = $nested ? ['missing'] : [
            ...($this->isMethod('POST') ? [] : ['sometimes']),
            'required', 'integer', 'min:1',
        ];

        return [
            'lead_id' => $selector,
            'user_id' => $selector,
            'activity_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'lead_id.required' => 'Select a lead.',
            'user_id.required' => 'Select a user.',
            'activity_type.required' => 'Enter an activity type.',
            'activity_type.max' => 'Activity type can contain up to 255 characters.',
            'description.required' => 'Enter a description.',
        ];
    }
}

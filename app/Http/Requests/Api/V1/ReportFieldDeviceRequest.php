<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReportFieldDeviceRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'platform' => ['nullable', 'string', 'max:40'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'pending' => ['present', 'array', 'max:400'],
            'pending.*' => ['array:date,count,oldest_captured_at'],
            'pending.*.date' => ['required', 'date_format:Y-m-d'],
            'pending.*.count' => ['required', 'integer', 'min:1', 'max:100000'],
            'pending.*.oldest_captured_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Name this device so reviewers can identify it.', 'pending.present' => 'Report the dates that still hold unsynchronized work, or an empty list.'];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Models\VisitPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisitPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'max:10240'],
            'tag' => ['required', 'string', Rule::in(['check-in', 'check-out', 'general-visit'])],
            'latitude' => [VisitPreference::current()->require_gps ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [VisitPreference::current()->require_gps ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'captured_at' => ['nullable', 'date'],
        ];
    }
}

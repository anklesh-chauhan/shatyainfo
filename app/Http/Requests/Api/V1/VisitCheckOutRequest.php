<?php

namespace App\Http\Requests\Api\V1;

use App\Models\VisitPreference;
use Illuminate\Foundation\Http\FormRequest;

class VisitCheckOutRequest extends FormRequest
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
            'recorded_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'latitude' => [VisitPreference::current()->require_gps ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [VisitPreference::current()->require_gps ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
        ];
    }
}

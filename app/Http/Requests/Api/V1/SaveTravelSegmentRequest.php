<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SaveTravelSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sales_dcr_id' => ['required', 'integer', 'min:1'],
            'visit_id' => ['nullable', 'integer', 'min:1'],
            'sales_tour_plan_detail_id' => ['nullable', 'integer', 'min:1'],
            'patch_id' => ['nullable', 'integer', 'min:1'],
            'from_account_id' => ['nullable', 'integer', 'min:1'],
            'to_account_id' => ['nullable', 'integer', 'min:1'],
            'from_area_town_id' => ['nullable', 'integer', 'min:1'],
            'to_area_town_id' => ['nullable', 'integer', 'min:1'],
            'transport_mode_id' => ['nullable', 'integer', 'min:1'],
            'distance_km' => ['required', 'numeric', 'decimal:0,2', 'between:0,99999999.99'],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'string', 'size:64'],
            'gps_distance_km' => ['prohibited'],
            'distance_source' => ['prohibited'],
            'is_auto_generated' => ['prohibited'],
            'import_reference' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'distance_km.decimal' => 'Enter the distance with up to two decimal places.',
            'revision.required' => 'Reload the segment before saving.',
        ];
    }
}

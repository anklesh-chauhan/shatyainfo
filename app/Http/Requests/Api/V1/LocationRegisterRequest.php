<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRegisterRequest extends FormRequest
{
    public static function saveRules(bool $updating = false): array
    {
        return [
            'revision' => [$updating ? 'required' : 'prohibited', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\\s*$/u'],
            'description' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'typeable_id' => ['required', 'integer', 'min:1'],
            'contact_id' => ['nullable', 'integer', 'min:1'],
            'territory_ids' => ['present', 'array', 'max:100'],
            'territory_ids.*' => ['integer', 'distinct', 'min:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'decimal:0,7'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'decimal:0,7'],
            'is_active' => ['required', 'boolean'],
            'image_base64' => ['nullable', 'string', 'max:2796204'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            return [
                'search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
                'active' => ['nullable', Rule::in(['1', '0'])], 'location_id' => ['nullable', 'integer', 'min:1'],
                'parent_type_id' => ['nullable', 'integer', 'min:1'],
            ];
        }
        if ($this->route('action')) {
            return ['revision' => [$this->route('location') ? 'required' : 'prohibited', 'string', 'size:64'],
                'ids' => [$this->route('location') ? 'prohibited' : 'required', 'array', 'min:1', 'max:100'],
                'ids.*' => ['integer', 'distinct', 'min:1']];
        }

        return self::saveRules($this->isMethod('PATCH'));
    }

    public function messages(): array
    {
        return ['revision.required' => 'Reload the location before making changes.', 'name.required' => 'Enter a location name.',
            'typeable_id.required' => 'Choose a location type or subtype.', 'territory_ids.present' => 'Include territories or an empty list.'];
    }
}

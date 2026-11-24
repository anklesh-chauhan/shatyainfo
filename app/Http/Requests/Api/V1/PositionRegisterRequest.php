<?php

namespace App\Http\Requests\Api\V1;

use App\Services\HR\PositionRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PositionRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            $rules = ['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'position_id' => ['nullable', 'integer', 'min:1'],
                'status' => ['nullable', Rule::in(['active', 'inactive', 'vacant'])], 'sort' => ['sometimes', Rule::in(['name', 'code', 'level', 'status', 'created_at', 'updated_at'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])]];
            foreach (PositionRegister::SELECTORS as $key => $kind) {
                $rules[$key] = isset(PositionRegister::RELATIONS[$key]) ? ['nullable', 'array', 'max:100'] : ['nullable', 'integer', 'min:1'];
                if (isset(PositionRegister::RELATIONS[$key])) {
                    $rules[$key.'.*'] = ['integer', 'min:1', 'distinct'];
                }
            }

            return $rules;
        }
        if ($this->route('action')) {
            return $this->route('position') ? ['revision' => ['required', 'string', 'size:64']] : ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'min:1', 'distinct']];
        }

        return self::saveRules($this->route('position') !== null);
    }

    public static function saveRules(bool $editing): array
    {
        $rules = ['name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:255'], 'status' => ['required', Rule::in(['active', 'inactive', 'vacant'])],
            'level' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:65535'], 'revision' => $editing ? ['required', 'string', 'size:64'] : ['prohibited']];
        foreach (PositionRegister::SELECTORS as $key => $kind) {
            $required = in_array($key, ['job_role_id', 'territory_ids', 'hq_territory_id'], true);
            $rules[$key] = isset(PositionRegister::RELATIONS[$key]) ? [$required ? 'required' : 'present', 'array', $required ? 'min:1' : 'min:0', 'max:100'] : [$required ? 'required' : 'nullable', 'integer', 'min:1'];
            if (isset(PositionRegister::RELATIONS[$key])) {
                $rules[$key.'.*'] = ['integer', 'min:1', 'distinct'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['revision.required' => 'Reload the position before making changes.', 'territory_ids.required' => 'Choose at least one territory.', 'hq_territory_id.required' => 'Choose the HQ territory.', 'job_role_id.required' => 'Choose the job role.'];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Field\SalesPerformanceService;
use Illuminate\Foundation\Http\FormRequest;

class TerritorySalesAssignmentRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return SalesPerformanceService::canViewTerritories($this->user());
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['search.max' => 'Search can contain up to 100 characters.'];
    }
}

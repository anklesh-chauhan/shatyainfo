<?php

namespace App\Http\Requests\Api\V1;

use App\Models\LocationMaster;
use App\Services\Field\FieldTeamOnboardingService;
use Illuminate\Foundation\Http\FormRequest;

class CreateFieldTeamHqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return FieldTeamOnboardingService::canManage($this->user()) && $this->user()->can('create', LocationMaster::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Enter an HQ name.'];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Field\FieldTeamOnboardingService;
use Illuminate\Foundation\Http\FormRequest;

class SaveFieldTeamAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return FieldTeamOnboardingService::canManage($this->user());
    }

    public function rules(FieldTeamOnboardingService $service): array
    {
        return $service->assignmentRules();
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Choose a representative.',
            'location_master_id.required' => 'Choose an HQ.',
            'account_ids.max' => 'Select at most 500 customers.',
            'calls_per_month.between' => 'Enter a monthly call target from 0 to 1000.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FollowUp;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFollowUpRequest extends FormRequest
{
    /**
     * Hidden follow-ups return 404 before validation runs.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('viewAny', FollowUp::class)) {
            return false;
        }

        $followUp = FollowUp::query()
            ->applyVisibility('FollowUp', $user)
            ->find((int) $this->route('followUp'));

        abort_if($followUp === null, 404, 'Follow-up not found.');

        return $user->can('update', $followUp);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'follow_up_date' => ['sometimes', 'required', 'date'],
            'next_follow_up_date' => ['nullable', 'date'],
            'to_whom' => ['nullable', 'integer', 'exists:contact_details,id'],
            'interaction' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'follow_up_media_id' => ['nullable', 'integer', 'exists:follow_up_media,id'],
            'follow_up_result_id' => ['nullable', 'integer', 'exists:follow_up_results,id'],
            'follow_up_priority_id' => ['nullable', 'integer', 'exists:follow_up_priorities,id'],
            'follow_up_status_id' => ['sometimes', 'required', 'integer', 'exists:follow_up_statuses,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'follow_up_date.required' => 'Enter the follow-up date.',
            'follow_up_status_id.required' => 'Select a follow-up status.',
        ];
    }
}

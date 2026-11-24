<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Services\Field\FieldFollowUpService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FollowUp
 */
class FollowUpResource extends JsonResource
{
    /**
     * Only the lead id and reference are exposed: a follow-up can be visible
     * to its assignee while the lead itself is not.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $lead = $this->followupable_type === Lead::class && $this->relationLoaded('followupable')
            ? $this->followupable
            : null;

        return [
            'id' => $this->id,
            'followupable_type' => class_basename((string) $this->followupable_type),
            'lead_id' => $this->followupable_type === Lead::class ? (int) $this->followupable_id : null,
            'lead_reference_code' => $lead?->reference_code,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'to_whom' => $this->to_whom,
            'contact_name' => $this->whenLoaded('contactDetail', fn (): ?string => $this->contactDetail?->full_name),
            'follow_up_date' => $this->asDateTimeString($this->follow_up_date),
            'next_follow_up_date' => $this->asDateTimeString($this->next_follow_up_date),
            'interaction' => $this->interaction,
            'outcome' => $this->outcome,
            'follow_up_media_id' => $this->follow_up_media_id,
            'media_name' => $this->whenLoaded('media', fn (): ?string => $this->media?->name),
            'follow_up_result_id' => $this->follow_up_result_id,
            'result_name' => $this->whenLoaded('result', fn (): ?string => $this->result?->name),
            'follow_up_status_id' => $this->follow_up_status_id,
            'status_name' => $this->whenLoaded('status', fn (): ?string => $this->status?->name),
            'follow_up_priority_id' => $this->follow_up_priority_id,
            'priority_name' => $this->whenLoaded('priority', fn (): ?string => $this->priority?->name),
            'can_update' => $user !== null
                ? app(FieldFollowUpService::class)->canUpdate($user, $this->resource)
                : false,
            'can_delete' => $user !== null
                && app(FieldFollowUpService::class)->canDelete($user, $this->resource),
        ];
    }

    private function asDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateTimeString();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}

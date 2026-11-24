<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeadActivity;
use App\Services\Field\FieldLeadActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeadActivity
 */
class LeadActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasLeadContext = $this->relationLoaded('lead');
        $lead = $hasLeadContext ? $this->lead : null;
        $activities = app(FieldLeadActivityService::class);
        $actor = $request->user();

        return [
            'id' => $this->id,
            'lead_id' => $hasLeadContext ? $lead?->id : (int) $this->lead_id,
            ...($hasLeadContext ? [
                'lead_reference_code' => $lead?->reference_code,
                'lead_name' => $lead?->accountMaster?->name ?? $lead?->contactDetail?->full_name,
            ] : []),
            'activity_type' => $this->activity_type,
            'description' => $this->description,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'created_at' => $this->created_at?->toDateTimeString(),
            'can_update' => $lead !== null && $activities->canChange($actor, $this->resource, 'update'),
            'can_delete' => $lead !== null && $activities->canChange($actor, $this->resource, 'delete'),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeadActivity;
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
        return [
            'id' => $this->id,
            'lead_id' => (int) $this->lead_id,
            'activity_type' => $this->activity_type,
            'description' => $this->description,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}

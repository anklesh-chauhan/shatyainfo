<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Attachment;
use App\Models\LeadNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Attachment names and types only; files are not served through the API.
 *
 * @mixin LeadNote
 */
class LeadNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => (int) $this->lead_id,
            'note' => $this->note,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'attachments' => $this->whenLoaded(
                'attachments',
                fn (): array => $this->attachments
                    ->map(fn (Attachment $attachment): array => [
                        'id' => $attachment->id,
                        'file_name' => $attachment->file_name,
                        'file_type' => $attachment->file_type,
                    ])
                    ->values()
                    ->all(),
            ),
        ];
    }
}

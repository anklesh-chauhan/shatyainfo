<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ContactDetail;
use App\Services\Field\FieldContactService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactDetail
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $register = $actor !== null && $this->relationLoaded('approval');
        $contacts = app(FieldContactService::class);
        $reference = fn (?Model $record): ?array => $record ? ['id' => $record->id, 'label' => $record->name] : null;

        return [
            'id' => $this->id,
            'salutation' => $this->salutation,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'mobile_number' => $this->mobile_number,
            'email' => $this->email,
            'account_master_id' => $this->account_master_id,
            ...($register ? [
                'company' => $reference($this->company),
                'birthday' => $this->birthday === null ? null : substr($this->birthday, 0, 10),
                'can_view' => $actor->can('view', $this->resource),
                'can_edit' => $contacts->canWrite($actor, $this->resource, 'update'),
                'can_delete' => $contacts->canWrite($actor, $this->resource, 'delete'),
            ] : []),
            ...($this->relationLoaded('addresses') ? [
                'alternate_phone' => $this->alternate_phone, 'whatsapp_number' => $this->whatsapp_number,
                'linkedin' => $this->linkedin, 'facebook' => $this->facebook, 'twitter' => $this->twitter, 'website' => $this->website,
                'notes' => $this->notes, 'approval_status' => $this->approval?->approval_status ?? $this->approval_status,
                'designation' => $reference($this->designation), 'department' => $reference($this->department),
                'addresses' => AddressResource::collection($this->addresses),
            ] : []),
        ];
    }
}

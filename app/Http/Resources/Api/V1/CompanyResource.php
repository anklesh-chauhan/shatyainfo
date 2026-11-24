<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use App\Services\Field\FieldCompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $canView = $actor->can('view', $this->resource);
        $writable = $canView && ! app(FieldCompanyService::class)->isLocked($actor, $this->resource);

        return [
            'id' => $this->id, 'name' => $this->name, 'phone_number' => $this->phone_number,
            'email' => $this->email, 'secondary_email' => $this->secondary_email, 'website' => $this->website,
            'no_of_employees' => $this->no_of_employees, 'twitter' => $this->twitter, 'linked_in' => $this->linked_in,
            'description' => $this->whenLoaded('addresses', fn () => $this->description),
            'account_linked' => $this->account_master_id !== null,
            'account' => $this->whenLoaded('accountMaster', fn () => $this->accountMaster ? ['id' => $this->accountMaster->id, 'name' => $this->accountMaster->name] : null),
            'industry' => $this->whenLoaded('industryType', fn () => $this->industryType ? ['id' => $this->industryType->id, 'label' => $this->industryType->name] : null),
            'addresses' => $this->whenLoaded('addresses', fn () => AddressResource::collection($this->addresses)),
            'approval_status' => $this->approval?->approval_status ?? $this->approval_status,
            'can_view' => $canView, 'can_edit' => $writable && $actor->can('update', $this->resource),
            'can_delete' => $writable && $this->account_master_id === null && $actor->can('delete', $this->resource),
        ];
    }
}

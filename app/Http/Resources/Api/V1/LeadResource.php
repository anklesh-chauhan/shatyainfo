<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Lead;
use App\Services\Field\FieldLeadService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'reference_code' => $this->reference_code,
            'transaction_date' => $this->asDateString($this->transaction_date),
            'owner_id' => $this->owner_id,
            'owner_name' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'account_master_id' => $this->account_master_id,
            'account_name' => $this->whenLoaded('accountMaster', fn (): ?string => $this->accountMaster?->name),
            'contact_detail_id' => $this->contact_detail_id,
            'contact_name' => $this->whenLoaded('contactDetail', fn (): ?string => $this->contactDetail?->full_name),
            'address_id' => $this->address_id,
            'address_street' => $this->whenLoaded('address', fn (): ?string => $this->address?->street),
            'territory_id' => $this->territory_id,
            'territory_name' => $this->whenLoaded('territory', fn (): ?string => $this->territory?->name),
            'lead_source_id' => $this->lead_source_id,
            'lead_source_name' => $this->whenLoaded('leadSource', fn (): ?string => $this->leadSource?->name),
            'annual_revenue' => $this->annual_revenue !== null ? (float) $this->annual_revenue : null,
            'description' => $this->description,
            'status_id' => $this->status_id,
            'status_name' => $this->whenLoaded('status', fn (): ?string => $this->status?->name),
            'approval_status' => $this->approval_status,
            'display_name' => $this->display_name,
            'can_update' => $user !== null
                ? app(FieldLeadService::class)->canUpdate($user, $this->resource)
                : false,
            'can_convert' => $user !== null
                ? app(FieldLeadService::class)->canConvert($user, $this->resource)
                : false,
        ];
    }

    private function asDateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}

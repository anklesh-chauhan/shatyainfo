<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Deal;
use App\Services\Field\FieldDealService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Deal
 */
class DealResource extends JsonResource
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
            'deal_name' => $this->deal_name,
            'transaction_date' => $this->asDateString($this->transaction_date),
            'owner_id' => $this->owner_id,
            'owner_name' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'account_master_id' => $this->account_master_id,
            'account_name' => $this->whenLoaded('accountMaster', fn (): ?string => $this->accountMaster?->name),
            'contact_detail_id' => $this->contact_detail_id,
            'contact_name' => $this->whenLoaded('contactDetail', fn (): ?string => $this->contactDetail?->full_name),
            'address_id' => $this->address_id,
            'address_street' => $this->whenLoaded('address', fn (): ?string => $this->address?->street),
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'expected_revenue' => $this->expected_revenue !== null ? (float) $this->expected_revenue : null,
            'expected_close_date' => $this->asDateString($this->expected_close_date),
            'lead_source_id' => $this->lead_source_id,
            'description' => $this->description,
            'status_id' => $this->status_id,
            'status_name' => $this->whenLoaded('status', fn (): ?string => $this->status?->name),
            'type_id' => $this->type_id,
            'type_name' => $this->whenLoaded('type', fn (): ?string => $this->type?->name),
            'approval_status' => $this->approval_status,
            'can_update' => $user !== null
                ? app(FieldDealService::class)->canUpdate($user, $this->resource)
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

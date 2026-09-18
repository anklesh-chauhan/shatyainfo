<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SgipDistribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SgipDistribution
 */
class SgipDistributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visit_id' => $this->visit_id,
            'user_id' => $this->user_id,
            'employee_id' => $this->employee_id,
            'account_master_id' => $this->account_master_id,
            'territory_id' => $this->territory_id,
            'visit_date' => optional($this->visit_date)?->toDateString(),
            'approval_status' => $this->approval_status,
            'total_value' => $this->total_value !== null ? (float) $this->total_value : 0.0,
            'sample_issue_id' => $this->sample_issue_id,
            'marketing_campaign_id' => $this->marketing_campaign_id,
            'inventory_posted_at' => optional($this->inventory_posted_at)?->toIso8601String(),
            'doctor' => $this->whenLoaded('doctor', fn (): ?array => $this->doctor ? [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
            ] : null),
            'territory' => $this->whenLoaded('territory', fn (): ?array => $this->territory ? [
                'id' => $this->territory->id,
                'name' => $this->territory->name,
                'code' => $this->territory->code,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'item_master_id' => $item->item_master_id,
                'quantity' => (float) $item->quantity,
                'unit_value' => (float) $item->unit_value,
                'total_value' => (float) $item->total_value,
                'item' => $item->relationLoaded('item') && $item->item ? [
                    'id' => $item->item->id,
                    'item_code' => $item->item->item_code,
                    'item_name' => $item->item->item_name,
                ] : null,
            ])->values()),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ItemMaster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item linked to a lead through `item_master_lead`.
 *
 * @mixin ItemMaster
 */
class LeadItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->pivot;

        return [
            'item_master_id' => $this->id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'quantity' => $pivot?->quantity !== null ? (int) $pivot->quantity : null,
            'price' => $pivot?->price !== null ? (float) $pivot->price : null,
            'linked_at' => $pivot?->created_at?->toDateTimeString(),
        ];
    }
}

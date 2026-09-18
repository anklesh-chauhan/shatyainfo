<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ItemMaster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ItemMaster
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'sku' => $this->sku,
            'item_type' => $this->item_type?->value,
            'item_type_label' => $this->item_type?->getLabel(),
            'selling_price' => $this->selling_price !== null ? (float) $this->selling_price : null,
            'category_type' => $this->category_type,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Address
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type_master_id' => $this->type_master_id,
            'street' => $this->street,
            'pin_code' => $this->pin_code,
            'area_town_id' => $this->area_town_id,
            'city_id' => $this->city_id,
            'state_id' => $this->state_id,
            'country_id' => $this->country_id,
            'is_primary' => (bool) $this->is_primary,
            'sort' => $this->sort,
            'type' => $this->whenLoaded('typeMaster', fn (): ?array => $this->typeMaster ? [
                'id' => $this->typeMaster->id,
                'name' => $this->typeMaster->name,
            ] : null),
            'area_town' => $this->whenLoaded('areaTown', fn (): ?array => $this->areaTown ? [
                'id' => $this->areaTown->id,
                'name' => $this->areaTown->area_town,
                'pin_code' => $this->areaTown->pin_code,
            ] : null),
            'city' => $this->whenLoaded('city', fn (): ?array => $this->city ? [
                'id' => $this->city->id,
                'name' => $this->city->name,
            ] : null),
            'state' => $this->whenLoaded('state', fn (): ?array => $this->state ? [
                'id' => $this->state->id,
                'name' => $this->state->name,
                'gst_code' => $this->state->gst_code ?? null,
            ] : null),
            'country' => $this->whenLoaded('country', fn (): ?array => $this->country ? [
                'id' => $this->country->id,
                'name' => $this->country->name,
            ] : null),
        ];
    }
}

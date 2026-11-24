<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BaseModel;
use App\Models\CityPinCode;
use App\Models\Territory;
use App\Services\Field\FieldTerritoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Territory */
class TerritoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $locked = app(FieldTerritoryService::class)->locked($actor, $this->resource);
        $writable = ! $this->trashed() && ! $locked && $actor->can('view', $this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'status' => $this->status,
            'approval_status' => $this->approval?->approval_status ?? $this->approval_status,
            'trashed' => $this->trashed(),
            'parent' => $this->whenLoaded('parentTerritory', fn (Territory $parent): array => self::option($parent)),
            'type' => $this->whenLoaded('typeMaster', fn (BaseModel $type): array => self::option($type)),
            'can_view' => $actor->can('view', $this->resource),
            'can_edit' => $writable && $actor->can('update', $this->resource),
            'can_delete' => $writable && $actor->can('delete', $this->resource),
            'description' => $this->when($this->resource->relationLoaded('cityPinCodes'), $this->description),
            'region' => $this->whenLoaded('region', fn (BaseModel $region): array => self::option($region)),
            'divisions' => $this->whenLoaded('divisions', fn ($rows) => $rows->map(self::option(...))),
            'positions' => $this->whenLoaded('positions', fn ($rows) => $rows->map(self::option(...))),
            'city_pin_codes' => $this->whenLoaded('cityPinCodes', fn ($rows) => $rows->map(self::option(...))),
            'can_view_patches' => $this->when($this->resource->relationLoaded('cityPinCodes'), $actor->can(['ViewAny:Patch', 'View:Patch'])),
        ];
    }

    /** @return array{id: int, label: string} */
    public static function option(BaseModel $model): array
    {
        return ['id' => $model->id, 'label' => $model instanceof CityPinCode ? $model->full_location : $model->name];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SalesTourPlanDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesTourPlanDetail
 */
class TourPlanDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => optional($this->date)?->toDateString(),
            'remarks' => $this->remarks,
            'territory' => $this->whenLoaded('territory', fn (): ?array => $this->territory ? [
                'id' => $this->territory->id,
                'name' => $this->territory->name,
                'code' => $this->territory->code,
            ] : null),
            'patches' => $this->whenLoaded('patches', function () {
                return $this->patches->map(function ($patch): array {
                    return [
                        'id' => $patch->id,
                        'name' => $patch->name,
                        'code' => $patch->code,
                        'companies' => $patch->relationLoaded('companies')
                            ? $patch->companies->map(fn ($company): array => [
                                'id' => $company->id,
                                'name' => $company->name,
                                'phone_number' => $company->phone_number,
                                'email' => $company->email,
                                'type_name' => $company->typeMaster?->name,
                                'contact_name' => $company->contactDetails->first()?->full_name,
                                'contact_mobile' => $company->contactDetails->first()?->mobile_number,
                            ])->values()
                            : [],
                    ];
                })->values();
            }),
            'visits' => VisitResource::collection($this->whenLoaded('visits')),
        ];
    }
}

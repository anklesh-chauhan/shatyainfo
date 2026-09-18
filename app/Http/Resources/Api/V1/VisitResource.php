<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Visit
 */
class VisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'employee_id' => $this->employee_id,
            'territory_id' => $this->territory_id,
            'patch_id' => $this->patch_id,
            'sales_dcr_id' => $this->sales_dcr_id,
            'sales_tour_plan_detail_id' => $this->sales_tour_plan_detail_id,
            'visit_date' => optional($this->visit_date)?->toDateString(),
            'start_time' => optional($this->start_time)?->toIso8601String(),
            'end_time' => optional($this->end_time)?->toIso8601String(),
            'visit_type' => $this->visit_type,
            'visit_status' => $this->visit_status,
            'checkin_latitude' => $this->checkin_latitude !== null ? (float) $this->checkin_latitude : null,
            'checkin_longitude' => $this->checkin_longitude !== null ? (float) $this->checkin_longitude : null,
            'checkout_latitude' => $this->checkout_latitude !== null ? (float) $this->checkout_latitude : null,
            'checkout_longitude' => $this->checkout_longitude !== null ? (float) $this->checkout_longitude : null,
            'needs_check_in_image' => $this->when(
                isset($this->needs_check_in_image),
                $this->needs_check_in_image,
            ),
            'needs_check_out_image' => $this->when(
                isset($this->needs_check_out_image),
                $this->needs_check_out_image,
            ),
            'primary_company' => $this->when(
                $this->relationLoaded('visitables'),
                function (): ?array {
                    $company = $this->primaryCompany();

                    if (! $company) {
                        return null;
                    }

                    return [
                        'id' => $company->id,
                        'name' => $company->name,
                    ];
                },
            ),
            'sgip' => $this->when(
                $this->relationLoaded('sgipDistribution'),
                function (): ?array {
                    $sgip = $this->sgipDistribution;

                    if (! $sgip) {
                        return null;
                    }

                    return [
                        'id' => $sgip->id,
                        'approval_status' => $sgip->approval_status,
                        'total_value' => $sgip->total_value !== null ? (float) $sgip->total_value : 0.0,
                        'items_count' => $sgip->relationLoaded('items') ? $sgip->items->count() : null,
                    ];
                },
            ),
            'media' => MediaResource::collection($this->whenLoaded('media')),
        ];
    }
}

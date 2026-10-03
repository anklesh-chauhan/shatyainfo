<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SalesDcr;
use App\Models\VisitPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin SalesDcr
 */
class SalesDcrResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dcr_date' => optional($this->dcr_date)?->toDateString(),
            'user_id' => $this->user_id,
            'territory_id' => $this->territory_id,
            'approval_status' => $this->approval_status,
            'reportable' => $this->dcr_date !== null && VisitPreference::current()->allowsDcrDate($this->dcr_date),
            'visits_count' => (int) ($this->visits_count ?? 0),
            'total_expense' => $this->total_expense !== null ? (float) $this->total_expense : 0.0,
            'distance_covered' => $this->distance_covered !== null ? (float) $this->distance_covered : null,
            'remarks' => $this->remarks,
            'submitted_at' => $this->formatTimestamp($this->submitted_at),
            'territory' => $this->whenLoaded('territory', fn (): ?array => $this->territory ? [
                'id' => $this->territory->id,
                'name' => $this->territory->name,
                'code' => $this->territory->code,
            ] : null),
            'expenses' => SalesDcrExpenseResource::collection($this->whenLoaded('expenses')),
            'visits' => $this->whenLoaded('visits', fn () => $this->visits->map(fn ($visit): array => [
                'id' => $visit->id,
                'document_number' => $visit->document_number,
                'visit_date' => optional($visit->visit_date)?->toDateString(),
                'visit_status' => $visit->visit_status,
                'territory_id' => $visit->territory_id,
                'patch_id' => $visit->patch_id,
            ])->values()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'reporting' => VisitPreference::current()->dcrReportingWindow(),
        ];
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value)->toIso8601String();
        }

        return optional($value)->toIso8601String();
    }
}

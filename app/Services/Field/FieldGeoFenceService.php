<?php

namespace App\Services\Field;

use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitGeoAssessment;
use App\Models\VisitPreference;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class FieldGeoFenceService
{
    /**
     * Create a new class instance.
     */
    public function record(Visit $visit, string $stage, array $data, CarbonInterface $capturedAt): void
    {
        $prefs = VisitPreference::current();
        if (! $prefs->enforce_geo_fencing) {
            return;
        }
        $company = $visit->primaryCompany();
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $accuracy = $data['accuracy_meters'] ?? null;
        $radius = min(250, max(100, (int) ($company?->geo_radius_meters ?? 100)));
        $distance = null;
        $result = 'missing_master';
        if ($company?->geo_latitude !== null && $company?->geo_longitude !== null) {
            $result = 'missing_gps';
            if ($latitude !== null && $longitude !== null) {
                $distance = self::distance((float) $latitude, (float) $longitude, (float) $company->geo_latitude, (float) $company->geo_longitude);
                $result = $distance <= $radius ? 'inside' : 'outside';
            }
        }
        VisitGeoAssessment::query()->create(['visit_id' => $visit->id, 'stage' => $stage,
            'result' => $result, 'distance_meters' => $distance, 'radius_meters' => $radius,
            'low_accuracy' => $accuracy === null || (float) $accuracy > 50,
            'evidence' => ['latitude' => $latitude, 'longitude' => $longitude, 'accuracy_meters' => $accuracy,
                'master_latitude' => $company?->geo_latitude, 'master_longitude' => $company?->geo_longitude,
                'captured_at' => $capturedAt->toIso8601String(), 'received_at' => now()->toIso8601String(),
                'provenance' => 'device_reported_gps'],
            'assigned_to' => $result === 'inside' ? null : $prefs->geo_asm_user_id,
            'decision' => $result === 'inside' ? 'not_required' : 'pending']);
    }

    public static function distance(float $latitude, float $longitude, float $masterLatitude, float $masterLongitude): float
    {
        $a = sin(deg2rad($masterLatitude - $latitude) / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad($masterLatitude)) * sin(deg2rad($masterLongitude - $longitude) / 2) ** 2;

        return 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));
    }

    public function assertCleared(SalesDcr $dcr): void
    {
        if (! VisitPreference::current()->enforce_geo_fencing) {
            return;
        }
        foreach ($dcr->visits()->get() as $visit) {
            $required = ($visit->start_time ? 1 : 0) + ($visit->end_time ? 1 : 0);
            if ($required === 0 || VisitGeoAssessment::query()->where('visit_id', $visit->id)->count() < $required) {
                throw ValidationException::withMessages(['geo_fence' => 'This DCR contains calls without validated location evidence. Reconcile them before submission.']);
            }
        }
        if (VisitGeoAssessment::query()->whereHas('visit', fn ($query) => $query->where('sales_dcr_id', $dcr->id))
            ->whereNotIn('decision', ['approved', 'not_required'])->exists()) {
            throw ValidationException::withMessages(['geo_fence' => 'ASM review is required for location exceptions before this DCR can lock.']);
        }
    }
}

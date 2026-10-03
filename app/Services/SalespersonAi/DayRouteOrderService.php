<?php

namespace App\Services\SalespersonAi;

use App\Models\SalesTourPlanDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

class DayRouteOrderService
{
    /**
     * @return array{date: string, method: string, stops: list<array<string, mixed>>, summary: string}
     */
    public function forUser(User $user, Carbon $date): array
    {
        $details = SalesTourPlanDetail::query()
            ->whereDate('date', $date)
            ->whereHas('tourPlan', fn ($query) => $query->where('user_id', $user->id))
            ->with(['patches.cityPinCode', 'patches.companies'])
            ->get();

        $stops = [];

        foreach ($details as $detail) {
            foreach ($detail->patches as $patch) {
                $latitude = $patch->cityPinCode?->latitude;
                $longitude = $patch->cityPinCode?->longitude;

                foreach ($patch->companies as $company) {
                    $sequence = (int) ($company->pivot->sequence_no ?? 9999);
                    $existing = $stops[$company->id] ?? null;

                    if ($existing && $existing['sequence_no'] <= $sequence) {
                        continue;
                    }

                    $stops[$company->id] = [
                        'account_id' => $company->id,
                        'name' => $company->name,
                        'patch_name' => $patch->name,
                        'sequence_no' => $sequence,
                        'latitude' => $latitude !== null ? (float) $latitude : null,
                        'longitude' => $longitude !== null ? (float) $longitude : null,
                    ];
                }
            }
        }

        $stops = array_values($stops);

        if ($stops === []) {
            return [
                'date' => $date->toDateString(),
                'method' => 'none',
                'stops' => [],
                'summary' => 'Nothing is planned for '.$date->toDateString().'.',
            ];
        }

        $withCoordinates = array_values(array_filter(
            $stops,
            fn (array $stop): bool => $stop['latitude'] !== null && $stop['longitude'] !== null,
        ));

        if (count($withCoordinates) < 2) {
            $ordered = $this->bySequence($stops);

            return [
                'date' => $date->toDateString(),
                'method' => 'patch_sequence',
                'stops' => $ordered,
                'summary' => count($ordered).' stop'.(count($ordered) === 1 ? '' : 's').' kept in patch sequence. Coordinates are not available for a shorter route.',
            ];
        }

        $ordered = $this->nearestNeighbor($withCoordinates);
        $missing = $this->bySequence(array_values(array_filter(
            $stops,
            fn (array $stop): bool => $stop['latitude'] === null || $stop['longitude'] === null,
        )));
        $ordered = [...$ordered, ...$missing];

        return [
            'date' => $date->toDateString(),
            'method' => 'nearest_neighbor',
            'stops' => $ordered,
            'summary' => count($ordered).' stops ordered from the first patch sequence, then by the nearest recorded coordinates. The approved tour plan is unchanged.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<array<string, mixed>>
     */
    private function bySequence(array $stops): array
    {
        usort($stops, function (array $left, array $right): int {
            return [$left['sequence_no'], $left['name']] <=> [$right['sequence_no'], $right['name']];
        });

        return $stops;
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<array<string, mixed>>
     */
    private function nearestNeighbor(array $stops): array
    {
        $remaining = $this->bySequence($stops);
        $ordered = [];
        $current = array_shift($remaining);
        $ordered[] = $current;

        while ($remaining !== []) {
            $bestIndex = 0;
            $bestDistance = INF;

            foreach ($remaining as $index => $candidate) {
                $distance = $this->distanceKm(
                    (float) $current['latitude'],
                    (float) $current['longitude'],
                    (float) $candidate['latitude'],
                    (float) $candidate['longitude'],
                );

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestIndex = $index;
                }
            }

            $current = $remaining[$bestIndex];
            $ordered[] = $current;
            array_splice($remaining, $bestIndex, 1);
        }

        return $ordered;
    }

    private function distanceKm(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadius = 6371;
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}

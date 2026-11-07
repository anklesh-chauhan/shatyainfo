<?php

namespace App\Services\Travel;

use App\Models\AccountMaster;
use App\Models\SalesDcr;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\TravelSegment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TravelSegmentService
{
    public function __construct(
        protected DistanceResolutionService $distanceResolutionService
    ) {}

    public function regenerateFromTourPlan(SalesTourPlan $tourPlan): void
    {
        $tourPlan->loadMissing([
            'details.patches.companies.addresses',
        ]);

        foreach ($tourPlan->details as $detail) {
            $this->generateFromTourPlanDetail($tourPlan, $detail);
        }
    }

    public function generateFromTourPlanDetail(
        SalesTourPlan $tourPlan,
        SalesTourPlanDetail $detail
    ): void {
        $dcr = $this->getOrCreateDcr($tourPlan, $detail);

        DB::transaction(function () use ($detail, $dcr): void {
            TravelSegment::query()
                ->where('sales_dcr_id', $dcr->id)
                ->where('sales_tour_plan_detail_id', $detail->id)
                ->where('is_auto_generated', true)
                ->delete();

            $detail->loadMissing('patches.companies.addresses');

            foreach ($detail->patches as $patch) {
                /** @var Collection<int, AccountMaster> $orderedAccounts */
                $orderedAccounts = $patch->companies
                    ->sortBy(fn ($account) => $account->pivot?->sequence_no ?? PHP_INT_MAX)
                    ->values();

                if ($orderedAccounts->count() < 2) {
                    continue;
                }

                for ($index = 0; $index < $orderedAccounts->count() - 1; $index++) {
                    $fromAccount = $orderedAccounts[$index];
                    $toAccount = $orderedAccounts[$index + 1];

                    $segment = new TravelSegment([
                        'sales_dcr_id' => $dcr->id,
                        'sales_tour_plan_detail_id' => $detail->id,
                        'patch_id' => $patch->id,
                        'from_account_id' => $fromAccount->id,
                        'to_account_id' => $toAccount->id,
                        'from_area_town_id' => $this->resolveAccountCityId($fromAccount),
                        'to_area_town_id' => $this->resolveAccountCityId($toAccount),
                        'distance_km' => 0,
                        'distance_source' => 'manual',
                        'is_auto_generated' => true,
                    ]);

                    $resolved = $this->distanceResolutionService->resolve($segment);

                    $segment->distance_km = $resolved['distance_km'];
                    $segment->distance_source = $resolved['distance_source'];
                    $segment->save();
                }
            }
        });
    }

    public function generateFromActualVisits(SalesDcr $dcr): void
    {
        $visits = $dcr->visits()
            ->with(['visitables', 'visitables.visitable.addresses'])
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        if ($visits->count() < 2) {
            return;
        }

        DB::transaction(function () use ($dcr, $visits): void {
            TravelSegment::query()
                ->where('sales_dcr_id', $dcr->id)
                ->whereNotNull('visit_id')
                ->where('is_auto_generated', true)
                ->delete();

            for ($index = 0; $index < $visits->count() - 1; $index++) {
                $fromVisit = $visits[$index];
                $toVisit = $visits[$index + 1];

                $fromAccount = $fromVisit->primaryCompany();
                $toAccount = $toVisit->primaryCompany();

                if ($fromAccount === null || $toAccount === null) {
                    continue;
                }

                $segment = new TravelSegment([
                    'sales_dcr_id' => $dcr->id,
                    'visit_id' => $toVisit->id,
                    'patch_id' => $toVisit->patch_id ?? $fromVisit->patch_id,
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'from_area_town_id' => $this->resolveAccountCityId($fromAccount),
                    'to_area_town_id' => $this->resolveAccountCityId($toAccount),
                    'distance_km' => 0,
                    'distance_source' => 'manual',
                    'is_auto_generated' => true,
                ]);

                $resolved = $this->distanceResolutionService->resolve($segment);
                $segment->distance_km = $resolved['distance_km'];
                $segment->distance_source = $resolved['distance_source'];
                $segment->save();
            }
        });
    }

    protected function getOrCreateDcr(SalesTourPlan $tourPlan, SalesTourPlanDetail $detail): SalesDcr
    {
        /** @var SalesDcr $dcr */
        $dcr = SalesDcr::query()->firstOrCreate(
            [
                'user_id' => $tourPlan->user_id,
                'dcr_date' => $detail->date,
            ],
            [
                'sales_tour_plan_id' => $tourPlan->id,
                'approval_status' => 'draft',
                'territory_id' => $detail->territory_id,
            ]
        );

        return $dcr;
    }

    public function recalculateDistances(SalesDcr $dcr): void
    {
        $dcr->travelSegments()
            ->orderBy('id')
            ->get()
            ->each(function (TravelSegment $segment): void {
                $resolved = $this->distanceResolutionService->resolve($segment);

                $segment->forceFill([
                    'distance_km' => $resolved['distance_km'],
                    'distance_source' => $resolved['distance_source'],
                ])->saveQuietly();
            });
    }

    protected function resolveAccountCityId($account): ?int
    {
        $address = $account->addresses
            ?->sortBy('sort')
            ->first();

        return $address?->area_town_id;
    }
}

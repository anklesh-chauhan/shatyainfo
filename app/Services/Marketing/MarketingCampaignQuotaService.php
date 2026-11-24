<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignTerritoryQuota;
use App\Models\SgipDistribution;
use App\Models\SgipViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingCampaignQuotaService
{
    /**
     * Validate SGIP distribution against campaign territory quotas.
     *
     * @throws ValidationException
     */
    public function validate(SgipDistribution $distribution, bool $blockOnViolation = true): void
    {
        if ($distribution->marketing_campaign_id === null) {
            return;
        }

        $distribution->loadMissing('items.item');
        $campaign = $blockOnViolation
            ? $distribution->marketingCampaign()->lockForUpdate()->first()
            : $distribution->marketingCampaign;

        if ($campaign === null || ! $campaign->isActive()) {
            if ($blockOnViolation) {
                throw ValidationException::withMessages([
                    'marketing_campaign_id' => 'The selected marketing campaign is not active.',
                ]);
            }

            return;
        }

        if ($distribution->territory_id === null) {
            if ($blockOnViolation) {
                throw ValidationException::withMessages([
                    'territory_id' => 'Territory is required when a marketing campaign is selected.',
                ]);
            }

            return;
        }

        foreach ($distribution->items->groupBy('item_master_id') as $lines) {
            $line = $lines->first();
            if ($line->item?->item_type?->value !== 'sample') {
                continue;
            }

            $quota = MarketingCampaignTerritoryQuota::query()
                ->where('marketing_campaign_id', $campaign->id)
                ->where('territory_id', $distribution->territory_id)
                ->where('item_master_id', $line->item_master_id)
                ->lockForUpdate()
                ->first();

            if ($quota === null) {
                if ($blockOnViolation) {
                    throw ValidationException::withMessages([
                        'marketing_campaign_id' => "No campaign quota defined for {$line->item?->item_name} in this territory.",
                    ]);
                }

                continue;
            }

            $requested = (float) $lines->sum('quantity');
            $remaining = $quota->remainingQuota();

            if ($requested > $remaining) {
                $this->recordViolation(
                    distribution: $distribution,
                    quota: $quota,
                    allowed: $remaining,
                    actual: $requested,
                    block: $blockOnViolation,
                );
            }
        }
    }

    /**
     * Consume campaign quota after inventory is posted via InventoryService.
     */
    public function consumeQuota(SgipDistribution $distribution): void
    {
        if ($distribution->marketing_campaign_id === null || $distribution->territory_id === null) {
            return;
        }

        DB::transaction(function () use ($distribution): void {
            $distribution->loadMissing('items.item');

            foreach ($distribution->items as $line) {
                if ($line->item?->item_type?->value !== 'sample') {
                    continue;
                }

                MarketingCampaignTerritoryQuota::query()
                    ->where('marketing_campaign_id', $distribution->marketing_campaign_id)
                    ->where('territory_id', $distribution->territory_id)
                    ->where('item_master_id', $line->item_master_id)
                    ->lockForUpdate()
                    ->first()
                    ?->increment('used_quantity', (float) $line->quantity);
            }
        });
    }

    protected function recordViolation(
        SgipDistribution $distribution,
        MarketingCampaignTerritoryQuota $quota,
        float $allowed,
        float $actual,
        bool $block,
    ): void {
        SgipViolation::query()->create([
            'sgip_distribution_id' => $distribution->id,
            'violation_type' => 'campaign_quota',
            'allowed_value' => $allowed,
            'actual_value' => $actual,
        ]);

        if ($block) {
            throw ValidationException::withMessages([
                'marketing_campaign_id' => "Campaign quota exceeded for {$quota->item?->item_name} ({$actual} / {$allowed} remaining).",
            ]);
        }
    }
}

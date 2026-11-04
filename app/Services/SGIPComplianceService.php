<?php

namespace App\Services;

use App\Models\SgipDistribution;
use App\Models\SgipDistributionItem;
use App\Models\SgipLimit;
use App\Models\SgipViolation;
use App\Services\Marketing\MarketingCampaignQuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SGIPComplianceService
{
    /**
     * Validate SGIP distribution against all applicable limits.
     *
     * @throws ValidationException
     */
    public static function validate(
        SgipDistribution $distribution,
        bool $blockOnViolation = true
    ): void {
        DB::transaction(function () use ($distribution, $blockOnViolation) {

            // Remove old violations (safe: re-generated every validation)
            $distribution->violations()->delete();

            $limits = self::resolveApplicableLimits($distribution);

            foreach ($limits as $limit) {
                self::validateLimit($distribution, $limit, $blockOnViolation);
            }

            app(MarketingCampaignQuotaService::class)->validate($distribution, $blockOnViolation);
        });
    }

    /* =========================================================
     | Resolve applicable limits
     ========================================================= */

    protected static function resolveApplicableLimits(
        SgipDistribution $distribution
    ) {
        return SgipLimit::query()
            ->where(function ($q) use ($distribution) {

                $q->where('applies_to', 'global')
                    ->orWhere(function ($q) use ($distribution) {
                        $q->where('applies_to', 'account')
                            ->where('applies_to_id', $distribution->account_master_id);
                    })
                    ->orWhere(function ($q) use ($distribution) {
                        $q->where('applies_to', 'employee')
                            ->where('applies_to_id', $distribution->employee_id);
                    })
                    ->orWhere(function ($q) use ($distribution) {
                        $q->where('applies_to', 'territory')
                            ->where('applies_to_id', $distribution->territory_id);
                    });
            })
            ->orderBy('id')->lockForUpdate()->get();
    }

    /* =========================================================
     | Validate a single limit
     ========================================================= */

    protected static function validateLimit(
        SgipDistribution $distribution,
        SgipLimit $limit,
        bool $blockOnViolation
    ): void {

        [$from, $to] = self::resolvePeriodRange(
            $distribution->visit_date,
            $limit->period
        );

        // Filter applicable items by type
        $items = $distribution->items()
            ->whereHas('item', function ($q) use ($limit) {
                $q->where('item_type', $limit->item_type);
            })
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        // Current distribution totals
        $currentQty = $items->sum('quantity');
        $currentValue = $items->sum('total_value');

        // Historical usage
        $historical = self::calculateHistoricalUsage(
            $distribution,
            $limit,
            $from,
            $to
        );

        $totalQty = $historical['quantity'] + $currentQty;
        $totalValue = $historical['value'] + $currentValue;

        // Check violations
        if ($limit->max_quantity !== null && $totalQty > $limit->max_quantity) {
            self::recordViolation(
                $distribution,
                $limit,
                'quantity',
                $limit->max_quantity,
                $totalQty,
                $blockOnViolation
            );
        }

        if ($limit->max_value !== null && $totalValue > $limit->max_value) {
            self::recordViolation(
                $distribution,
                $limit,
                'value',
                $limit->max_value,
                $totalValue,
                $blockOnViolation
            );
        }
    }

    /* =========================================================
     | Calculate historical usage
     ========================================================= */

    protected static function calculateHistoricalUsage(
        SgipDistribution $distribution,
        SgipLimit $limit,
        Carbon $from,
        Carbon $to
    ): array {

        $query = SgipDistribution::query()
            ->where('id', '!=', $distribution->id)
            ->whereBetween('visit_date', [$from, $to])
            ->whereIn('approval_status', ['submitted', 'approved'])
            ->whereHas('items.item', function ($q) use ($limit) {
                $q->where('item_type', $limit->item_type);
            });

        match ($limit->applies_to) {
            'account' => $query->where('account_master_id', $distribution->account_master_id),
            'employee' => $query->where('employee_id', $distribution->employee_id),
            'territory' => $query->where('territory_id', $distribution->territory_id),
            default => null,
        };

        $ids = $query->lockForUpdate()->pluck('id');
        $items = SgipDistributionItem::query()
            ->whereIn('sgip_distribution_id', $ids)
            ->whereHas('item', fn ($item) => $item->where('item_type', $limit->item_type))
            ->lockForUpdate()->get(['quantity', 'total_value']);

        return ['quantity' => $items->sum('quantity'), 'value' => $items->sum('total_value')];
    }

    /* =========================================================
     | Record violation
     ========================================================= */

    protected static function recordViolation(
        SgipDistribution $distribution,
        SgipLimit $limit,
        string $type,
        float|int $allowed,
        float|int $actual,
        bool $block
    ): void {

        SgipViolation::create([
            'sgip_distribution_id' => $distribution->id,
            'sgip_limit_id' => $limit->id,
            'violation_type' => $type,
            'allowed_value' => $allowed,
            'actual_value' => $actual,
        ]);

        if ($block) {
            throw ValidationException::withMessages([
                'sgip' => "SGIP {$type} limit exceeded ({$actual} / {$allowed})",
            ]);
        }
    }

    /* =========================================================
     | Resolve date ranges
     ========================================================= */

    protected static function resolvePeriodRange(
        Carbon $date,
        string $period
    ): array {

        return match ($period) {
            'daily' => [$date->copy()->startOfDay(),   $date->copy()->endOfDay()],
            'monthly' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'yearly' => [$date->copy()->startOfYear(),  $date->copy()->endOfYear()],
            default => throw new \InvalidArgumentException('Invalid SGIP period'),
        };
    }
}

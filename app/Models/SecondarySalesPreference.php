<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecondarySalesPreference extends Model
{
    public const QUANTITY_FIELDS = [
        'opening_qty', 'purchase_qty', 'primary_free_qty', 'purchase_return_qty', 'secondary_sales_qty',
        'secondary_free_qty', 'sales_return_qty', 'expiry_breakage_qty',
    ];

    public const DEFAULT_FORMULA_SIGNS = [
        'opening_qty' => 1,
        'purchase_qty' => 1,
        'primary_free_qty' => 1,
        'purchase_return_qty' => -1,
        'secondary_sales_qty' => -1,
        'secondary_free_qty' => -1,
        'sales_return_qty' => 1,
        'expiry_breakage_qty' => -1,
    ];

    public const DISCREPANCY_CODES = [
        'stock_balance_variance',
        'opening_does_not_match_prior_closing',
        'missing_prior_closing',
        'primary_purchase_mismatch',
        'expired_batch_still_in_stock',
    ];

    protected $guarded = [];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'quantity_unit' => 'box',
            'formula_signs' => self::DEFAULT_FORMULA_SIGNS,
            'variance_tolerance_qty' => 0,
            'variance_tolerance_percent' => 0,
            'freeze_incentive_on_variance' => true,
            'require_prior_closing' => false,
            'prior_closing_source' => 'reported',
            'verify_primary_purchases' => false,
            'primary_purchase_tolerance_qty' => 0,
            'include_secondary_free_in_scheme_deduction' => false,
            'flag_expired_batches' => true,
            'allow_fractional_quantities' => false,
            'exclusive_source_kind_per_period' => true,
            'approvable_discrepancy_codes' => [],
        ];
    }

    public static function current(): self
    {
        $row = static::query()->first();
        if ($row) {
            return $row;
        }

        return static::query()->create(self::defaults());
    }

    /**
     * Rules applied at ingest time so later preference edits do not rewrite historical reconciliation.
     *
     * @return array{
     *     quantity_unit: string,
     *     formula_signs: array<string, int>,
     *     variance_tolerance_qty: float,
     *     variance_tolerance_percent: float,
     *     freeze_incentive_on_variance: bool,
     *     require_prior_closing: bool,
     *     prior_closing_source: string,
     *     verify_primary_purchases: bool,
     *     primary_purchase_tolerance_qty: float,
     *     include_secondary_free_in_scheme_deduction: bool,
     *     flag_expired_batches: bool,
     *     allow_fractional_quantities: bool,
     *     exclusive_source_kind_per_period: bool,
     *     approvable_discrepancy_codes: list<string>
     * }
     */
    public function snapshot(): array
    {
        $signs = [];
        foreach (self::DEFAULT_FORMULA_SIGNS as $field => $default) {
            $signs[$field] = (int) ($this->formula_signs[$field] ?? $default);
        }

        return [
            'quantity_unit' => $this->quantity_unit ?: 'box',
            'formula_signs' => $signs,
            'variance_tolerance_qty' => round((float) $this->variance_tolerance_qty, 6),
            'variance_tolerance_percent' => round((float) $this->variance_tolerance_percent, 4),
            'freeze_incentive_on_variance' => (bool) $this->freeze_incentive_on_variance,
            'require_prior_closing' => (bool) $this->require_prior_closing,
            'prior_closing_source' => $this->prior_closing_source === 'calculated' ? 'calculated' : 'reported',
            'verify_primary_purchases' => (bool) $this->verify_primary_purchases,
            'primary_purchase_tolerance_qty' => round((float) $this->primary_purchase_tolerance_qty, 6),
            'include_secondary_free_in_scheme_deduction' => (bool) $this->include_secondary_free_in_scheme_deduction,
            'flag_expired_batches' => (bool) $this->flag_expired_batches,
            'allow_fractional_quantities' => (bool) $this->allow_fractional_quantities,
            'exclusive_source_kind_per_period' => (bool) $this->exclusive_source_kind_per_period,
            'approvable_discrepancy_codes' => array_values(array_intersect(self::DISCREPANCY_CODES, $this->approvable_discrepancy_codes ?? [])),
        ];
    }

    public function formulaPreview(): string
    {
        $parts = [];
        foreach ($this->snapshot()['formula_signs'] as $field => $sign) {
            $parts[] = ($sign < 0 ? ' - ' : ' + ').abs($sign).'×'.$field;
        }

        return 'calculated_closing = 0'.implode('', $parts);
    }

    protected function casts(): array
    {
        return [
            'formula_signs' => 'array',
            'variance_tolerance_qty' => 'decimal:6',
            'variance_tolerance_percent' => 'decimal:4',
            'freeze_incentive_on_variance' => 'boolean',
            'require_prior_closing' => 'boolean',
            'verify_primary_purchases' => 'boolean',
            'primary_purchase_tolerance_qty' => 'decimal:6',
            'include_secondary_free_in_scheme_deduction' => 'boolean',
            'flag_expired_batches' => 'boolean',
            'allow_fractional_quantities' => 'boolean',
            'exclusive_source_kind_per_period' => 'boolean',
            'approvable_discrepancy_codes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (static::query()->count() > 0) {
                throw new \RuntimeException('Only one secondary-sales preference row is allowed.');
            }
        });
    }
}

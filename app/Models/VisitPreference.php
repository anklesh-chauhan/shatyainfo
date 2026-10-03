<?php

namespace App\Models;

use App\Enums\SgipStockSource;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class VisitPreference extends BaseModel
{
    protected $fillable = [

        'enable_check_in',
        'enable_check_out',
        'enforce_check_in_before_check_out',
        'allow_manual_time_edit',
        'enable_auto_checkout',
        'auto_checkout_time',

        'require_check_in_image',
        'require_check_out_image',
        'require_general_visit_image',
        'require_gps',
        'geo_fence_radius_meters',

        'enforce_minimum_duration',
        'minimum_duration_minutes',

        'field_rules',
        'allow_rescheduling',
        'allow_cancellation',
        'require_visit_outcome',
        'sgip_stock_source',
        'sgip_hq_location_id',
        'tour_plan_future_months',
        'allow_backdated_dcr',
        'dcr_backdate_days',
    ];

    protected $casts = [
        'enable_check_in' => 'boolean',
        'enable_check_out' => 'boolean',
        'enforce_check_in_before_check_out' => 'boolean',
        'allow_manual_time_edit' => 'boolean',
        'enable_auto_checkout' => 'boolean',

        'require_check_in_image' => 'boolean',
        'require_check_out_image' => 'boolean',
        'require_general_visit_image' => 'boolean',
        'require_gps' => 'boolean',

        'enforce_minimum_duration' => 'boolean',

        'field_rules' => 'array',

        'allow_rescheduling' => 'boolean',
        'allow_cancellation' => 'boolean',
        'require_visit_outcome' => 'boolean',
        'sgip_stock_source' => SgipStockSource::class,
        'tour_plan_future_months' => 'integer',
        'allow_backdated_dcr' => 'boolean',
        'dcr_backdate_days' => 'integer',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    public function tourPlanFutureMonths(): int
    {
        $months = (int) ($this->tour_plan_future_months ?: 12);

        return min(24, max(1, $months));
    }

    /**
     * @return list<string>
     */
    public function allowedTourPlanMonths(): array
    {
        $start = now()->startOfMonth();
        $months = [];

        for ($i = 0; $i < $this->tourPlanFutureMonths(); $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        return $months;
    }

    public function allowsTourPlanMonth(string $month): bool
    {
        return in_array($month, $this->allowedTourPlanMonths(), true);
    }

    public function dcrBackdateDays(): int
    {
        if (! $this->allow_backdated_dcr) {
            return 0;
        }

        return min(31, max(1, (int) $this->dcr_backdate_days));
    }

    public function earliestReportableDcrDate(): Carbon
    {
        return today()->subDays($this->dcrBackdateDays())->startOfDay();
    }

    public function allowsDcrDate(Carbon|string $date): bool
    {
        $day = $date instanceof Carbon
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();

        return $day->lessThanOrEqualTo(today()->startOfDay())
            && $day->greaterThanOrEqualTo($this->earliestReportableDcrDate());
    }

    public function dcrDateRejectionMessage(): string
    {
        if ($this->dcrBackdateDays() === 0) {
            return 'DCR reporting is open for today only.';
        }

        return 'DCR reporting is open from '.$this->earliestReportableDcrDate()->format('j M Y').' through today.';
    }

    /**
     * @return array{allow_backdated: bool, backdate_days: int, earliest_date: string, latest_date: string}
     */
    public function dcrReportingWindow(): array
    {
        return [
            'allow_backdated' => $this->dcrBackdateDays() > 0,
            'backdate_days' => $this->dcrBackdateDays(),
            'earliest_date' => $this->earliestReportableDcrDate()->toDateString(),
            'latest_date' => today()->toDateString(),
        ];
    }

    public function assertDcrDateIsReportable(Carbon|string $date, string $key = 'date'): void
    {
        if ($this->allowsDcrDate($date)) {
            return;
        }

        throw ValidationException::withMessages([
            $key => $this->dcrDateRejectionMessage(),
        ]);
    }

    public function isFieldVisible(string $field): bool
    {
        return data_get($this->field_rules, "{$field}.visible", true);
    }

    public function isFieldRequired(string $field): bool
    {
        return data_get($this->field_rules, "{$field}.required", false);
    }

    public function isFieldEditable(string $field): bool
    {
        return data_get($this->field_rules, "{$field}.editable", true);
    }

    protected static function booted()
    {
        static::creating(function () {
            if (static::count() > 0) {
                throw new \RuntimeException(
                    'Only one VisitPreference row is allowed.'
                );
            }
        });

        static::saving(function (VisitPreference $preference): void {
            if (! $preference->allow_backdated_dcr) {
                return;
            }

            $preference->dcr_backdate_days = min(31, max(1, (int) $preference->dcr_backdate_days));
        });
    }
}

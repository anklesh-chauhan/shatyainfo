<?php

namespace Database\Seeders;

use App\Models\ShiftMaster;
use App\Models\ShiftOverTimeSetup;
use App\Models\ShiftTimeSlabSetup;
use App\Models\WeekOff;
use Illuminate\Database\Seeder;

class ShiftMasterSeeder extends Seeder
{
    /**
     * Seed standard Indian office and factory shifts.
     *
     * Factories Act, 1948 / OSHWC Code, 2020:
     * 8–9 hours a day, 48 hours a week, 30-minute rest within 5 hours,
     * one weekly holiday (Sunday), overtime at twice the ordinary rate
     * capped at 2 hours a day and 50 hours a quarter.
     *
     * Shops and Establishments: typical staff shift 09:00–18:00 with a 60-minute lunch.
     */
    public function run(): void
    {
        foreach ($this->shifts() as $definition) {
            $this->seedShift($definition);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function shifts(): array
    {
        return [
            [
                'code' => 'GEN',
                'name' => 'General Staff Shift',
                'shift_type' => ShiftMaster::SHIFT_TYPE_FIXED,
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'first_half_start_at' => '09:00:00',
                'first_half_end_at' => '13:00:00',
                'second_half_start_at' => '14:00:00',
                'second_half_end_at' => '18:00:00',
                'shift_duration_hours' => 8,
                'is_night_shift' => false,
                'is_first_in_last_out_punch' => false,
                'is_lunch_time_flexible' => true,
                'lunch_break_minutes' => 60,
                'lunch_start_time' => '13:00:00',
                'lunch_end_time' => '14:00:00',
                'is_dinner_time_flexible' => false,
                'dinner_break_minutes' => null,
                'dinner_start_time' => null,
                'dinner_end_time' => null,
                'reduce_lunch' => true,
                'reduce_dinner' => false,
                'allow_auto_shift' => false,
                'late_in_grace_minutes' => 15,
                'early_out_grace_minutes' => 15,
            ],
            [
                'code' => 'WRK-A',
                'name' => 'Worker First Shift',
                'shift_type' => ShiftMaster::SHIFT_TYPE_ROTATIONAL,
                'start_time' => '07:00:00',
                'end_time' => '15:00:00',
                'first_half_start_at' => '07:00:00',
                'first_half_end_at' => '11:00:00',
                'second_half_start_at' => '11:30:00',
                'second_half_end_at' => '15:00:00',
                'shift_duration_hours' => 8,
                'is_night_shift' => false,
                'is_first_in_last_out_punch' => true,
                'is_lunch_time_flexible' => false,
                'lunch_break_minutes' => 30,
                'lunch_start_time' => '11:00:00',
                'lunch_end_time' => '11:30:00',
                'is_dinner_time_flexible' => false,
                'dinner_break_minutes' => null,
                'dinner_start_time' => null,
                'dinner_end_time' => null,
                'reduce_lunch' => false,
                'reduce_dinner' => false,
                'allow_auto_shift' => true,
                'late_in_grace_minutes' => 10,
                'early_out_grace_minutes' => 10,
            ],
            [
                'code' => 'WRK-B',
                'name' => 'Worker Second Shift',
                'shift_type' => ShiftMaster::SHIFT_TYPE_ROTATIONAL,
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'first_half_start_at' => '15:00:00',
                'first_half_end_at' => '19:00:00',
                'second_half_start_at' => '19:30:00',
                'second_half_end_at' => '23:00:00',
                'shift_duration_hours' => 8,
                'is_night_shift' => false,
                'is_first_in_last_out_punch' => true,
                'is_lunch_time_flexible' => false,
                'lunch_break_minutes' => null,
                'lunch_start_time' => null,
                'lunch_end_time' => null,
                'is_dinner_time_flexible' => false,
                'dinner_break_minutes' => 30,
                'dinner_start_time' => '19:00:00',
                'dinner_end_time' => '19:30:00',
                'reduce_lunch' => false,
                'reduce_dinner' => false,
                'allow_auto_shift' => true,
                'late_in_grace_minutes' => 10,
                'early_out_grace_minutes' => 10,
            ],
            [
                'code' => 'WRK-C',
                'name' => 'Worker Third Shift',
                'shift_type' => ShiftMaster::SHIFT_TYPE_ROTATIONAL,
                'start_time' => '23:00:00',
                'end_time' => '07:00:00',
                'first_half_start_at' => '23:00:00',
                'first_half_end_at' => '03:00:00',
                'second_half_start_at' => '03:30:00',
                'second_half_end_at' => '07:00:00',
                'shift_duration_hours' => 8,
                'is_night_shift' => true,
                'is_first_in_last_out_punch' => true,
                'is_lunch_time_flexible' => false,
                'lunch_break_minutes' => null,
                'lunch_start_time' => null,
                'lunch_end_time' => null,
                'is_dinner_time_flexible' => false,
                'dinner_break_minutes' => 30,
                'dinner_start_time' => '03:00:00',
                'dinner_end_time' => '03:30:00',
                'reduce_lunch' => false,
                'reduce_dinner' => false,
                'allow_auto_shift' => true,
                'late_in_grace_minutes' => 10,
                'early_out_grace_minutes' => 10,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function seedShift(array $definition): void
    {
        $shift = ShiftMaster::query()->updateOrCreate(
            ['code' => $definition['code']],
            [
                'name' => $definition['name'],
                'shift_change_time' => $definition['start_time'],
                'shift_type' => $definition['shift_type'],
                'week_off_type' => ShiftMaster::WEEK_OFF_FIXED,
                'start_time' => $definition['start_time'],
                'end_time' => $definition['end_time'],
                'first_half_start_at' => $definition['first_half_start_at'],
                'first_half_end_at' => $definition['first_half_end_at'],
                'second_half_start_at' => $definition['second_half_start_at'],
                'second_half_end_at' => $definition['second_half_end_at'],
                'shift_duration_hours' => $definition['shift_duration_hours'],
                'overtime_start_minutes' => 0,
                'is_first_in_last_out_punch' => $definition['is_first_in_last_out_punch'],
                'is_night_shift' => $definition['is_night_shift'],
                'is_flexible' => false,
                'is_system' => false,
                'is_lunch_time_flexible' => $definition['is_lunch_time_flexible'],
                'lunch_break_minutes' => $definition['lunch_break_minutes'],
                'lunch_start_time' => $definition['lunch_start_time'],
                'lunch_end_time' => $definition['lunch_end_time'],
                'is_dinner_time_flexible' => $definition['is_dinner_time_flexible'],
                'dinner_break_minutes' => $definition['dinner_break_minutes'],
                'dinner_start_time' => $definition['dinner_start_time'],
                'dinner_end_time' => $definition['dinner_end_time'],
                'is_break_time_flexible' => false,
                'break_minutes' => null,
                'break_start_time' => null,
                'break_end_time' => null,
            ]
        );

        $this->seedSetups($shift, $definition);
        $this->seedTimeSlabs($shift, (int) $definition['late_in_grace_minutes']);
        $this->seedSundayWeekOff($shift);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function seedSetups(ShiftMaster $shift, array $definition): void
    {
        $shift->generalSetup()->updateOrCreate([], [
            'reduce_lunch_break_minutes_from_working_hours' => $definition['reduce_lunch'],
            'reduce_dinner_break_minutes_from_working_hours' => $definition['reduce_dinner'],
            'reduce_break_minutes_from_working_hours' => false,
            'auto_convert_wop_to_co_plus' => true,
            'auto_convert_php_to_co_plus' => true,
            'calculate_compensation' => true,
            'is_allow_auto_shift' => $definition['allow_auto_shift'],
            'is_allow_half_day_leave' => true,
            'is_allow_shift_change_request' => true,
        ]);

        $shift->lateMarkSetup()->updateOrCreate([], [
            'is_active' => true,
            'late_in_grace_minutes' => $definition['late_in_grace_minutes'],
            'early_out_grace_minutes' => $definition['early_out_grace_minutes'],
            'total_late_in_early_out_mark_threshold_minutes_in_month' => 45,
            'total_late_in_early_out_mark_no_of_times_in_month' => 3,
            'is_save_late_minutes_as_late_mark' => true,
            'is_calculate_on_weekly_off_and_paid_holiday' => false,
            'is_mark_abs_once_late_mark_grace_crossed_in_a_month' => false,
            'is_avoid_latemark_on_half_day_absent' => true,
            'is_avoid_latemark_on_full_day_absent' => true,
            'conversion_rate_grace_late_mark_count' => 0,
            'conversion_rate_no_of_late_mark_count' => 3,
            'conversion_rate_no_of_day_absent' => 1,
        ]);

        $shift->dayWorkSetup()->updateOrCreate([], [
            'is_active' => true,
            'first_half_late_in_cutoff_minutes' => 120,
            'first_half_early_out_cutoff_minutes' => 120,
            'second_half_late_in_cutoff_minutes' => 120,
            'second_half_early_out_cutoff_minutes' => 120,
            'add_early_in_minutes' => false,
            'add_late_out_minutes' => true,
            'daily_early_in_limit_minutes' => 0,
            'daily_late_out_limit_minutes' => 120,
            'monthly_early_in_grace_no_of_times' => 0,
            'monthly_late_out_grace_no_of_times' => 0,
        ]);

        $shift->overTimeSetup()->updateOrCreate([], [
            'is_active' => true,
            'is_weekly_off_paid_holiday_as_ot' => false,
            'minimum_ot_hours_required_per_day' => 0,
            'ot_calculation_basis' => ShiftOverTimeSetup::CALCULATION_ACTUAL,
            'ot_rounding_method' => ShiftOverTimeSetup::ROUND_15,
            'maximum_ot_hours_allowed_per_day' => 2,
            'maximum_ot_hours_per_month' => 16,
            'consider_working_hours_as_ot_if_less_than_half_day_hours' => false,
            'monthly_total_ot_round_off_method' => ShiftOverTimeSetup::ROUND_15,
            'is_approval_required' => true,
        ]);

        $shift->compOffSetup()->updateOrCreate([], [
            'is_active' => true,
            'is_weekly_off_paid_holiday_as_comp_off' => true,
            'minimum_comp_off_hours_required_per_day' => 4,
            'conversion_daily_ot_hours' => 8,
            'conversion_co_plus_credit_days' => 1,
        ]);
    }

    protected function seedTimeSlabs(ShiftMaster $shift, int $graceMinutes): void
    {
        $shift->timeSlabSetups()->delete();

        $afterGrace = $graceMinutes + 1;

        $lateSlabs = [
            ['from_minute' => 1, 'to_minute' => $graceMinutes, 'diff_calc' => 0],
            ['from_minute' => $afterGrace, 'to_minute' => 60, 'diff_calc' => 1],
            ['from_minute' => 61, 'to_minute' => 120, 'diff_calc' => 1],
            ['from_minute' => 121, 'to_minute' => 480, 'diff_calc' => 1],
        ];

        foreach ([ShiftTimeSlabSetup::TYPE_LATE_IN, ShiftTimeSlabSetup::TYPE_LATE_OUT] as $type) {
            foreach ($lateSlabs as $slab) {
                $shift->timeSlabSetups()->create([
                    'time_slab_type' => $type,
                    ...$slab,
                ]);
            }
        }
    }

    protected function seedSundayWeekOff(ShiftMaster $shift): void
    {
        WeekOff::query()->updateOrCreate(
            [
                'shift_master_id' => $shift->id,
                'day_of_week' => 0,
                'employee_id' => null,
                'emp_department_id' => null,
            ],
            ['is_active' => true]
        );
    }
}

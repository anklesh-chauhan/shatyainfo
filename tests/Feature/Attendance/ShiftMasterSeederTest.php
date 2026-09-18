<?php

use App\Models\ShiftMaster;
use App\Models\ShiftTimeSlabSetup;
use App\Models\WeekOff;
use Database\Seeders\ShiftMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the general staff shift and three worker shifts with indian labour defaults', function () {
    $this->seed(ShiftMasterSeeder::class);

    expect(ShiftMaster::query()->count())->toBe(4);

    $general = ShiftMaster::query()->where('code', 'GEN')->first();
    $first = ShiftMaster::query()->where('code', 'WRK-A')->first();
    $second = ShiftMaster::query()->where('code', 'WRK-B')->first();
    $third = ShiftMaster::query()->where('code', 'WRK-C')->first();

    expect($general)->not->toBeNull()
        ->and($general->name)->toBe('General Staff Shift')
        ->and($general->start_time->format('H:i'))->toBe('09:00')
        ->and($general->end_time->format('H:i'))->toBe('18:00')
        ->and((int) $general->shift_duration_hours)->toBe(8)
        ->and((int) $general->lunch_break_minutes)->toBe(60)
        ->and($general->is_night_shift)->toBeFalse()
        ->and($general->week_off_type)->toBe(ShiftMaster::WEEK_OFF_FIXED)
        ->and($general->generalSetup->reduce_lunch_break_minutes_from_working_hours)->toBeTrue()
        ->and((int) $general->lateMarkSetup->late_in_grace_minutes)->toBe(15)
        ->and((int) $general->overTimeSetup->maximum_ot_hours_allowed_per_day)->toBe(2)
        ->and((int) $general->overTimeSetup->maximum_ot_hours_per_month)->toBe(16)
        ->and($general->compOffSetup->is_weekly_off_paid_holiday_as_comp_off)->toBeTrue();

    expect($first)->not->toBeNull()
        ->and($first->name)->toBe('Worker First Shift')
        ->and($first->start_time->format('H:i'))->toBe('07:00')
        ->and($first->end_time->format('H:i'))->toBe('15:00')
        ->and((int) $first->lunch_break_minutes)->toBe(30)
        ->and($first->is_night_shift)->toBeFalse()
        ->and($first->shift_type)->toBe(ShiftMaster::SHIFT_TYPE_ROTATIONAL);

    expect($second)->not->toBeNull()
        ->and($second->start_time->format('H:i'))->toBe('15:00')
        ->and($second->end_time->format('H:i'))->toBe('23:00')
        ->and((int) $second->dinner_break_minutes)->toBe(30)
        ->and($second->is_night_shift)->toBeFalse();

    expect($third)->not->toBeNull()
        ->and($third->start_time->format('H:i'))->toBe('23:00')
        ->and($third->end_time->format('H:i'))->toBe('07:00')
        ->and($third->is_night_shift)->toBeTrue()
        ->and((int) $third->dinner_break_minutes)->toBe(30);

    foreach ([$general, $first, $second, $third] as $shift) {
        expect($shift->generalSetup)->not->toBeNull()
            ->and($shift->lateMarkSetup->is_active)->toBeTrue()
            ->and($shift->dayWorkSetup->is_active)->toBeTrue()
            ->and($shift->overTimeSetup->is_active)->toBeTrue()
            ->and($shift->compOffSetup->is_active)->toBeTrue()
            ->and((int) $shift->lateMarkSetup->conversion_rate_no_of_late_mark_count)->toBe(3)
            ->and($shift->timeSlabSetups()->where('time_slab_type', ShiftTimeSlabSetup::TYPE_LATE_IN)->count())->toBe(4)
            ->and(
                WeekOff::query()
                    ->where('shift_master_id', $shift->id)
                    ->where('day_of_week', 0)
                    ->whereNull('employee_id')
                    ->whereNull('emp_department_id')
                    ->where('is_active', true)
                    ->exists()
            )->toBeTrue();
    }
});

it('is idempotent when seeded twice', function () {
    $this->seed(ShiftMasterSeeder::class);
    $this->seed(ShiftMasterSeeder::class);

    expect(ShiftMaster::query()->count())->toBe(4)
        ->and(WeekOff::query()->where('day_of_week', 0)->count())->toBe(4);
});

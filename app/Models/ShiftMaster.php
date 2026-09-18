<?php

namespace App\Models;

class ShiftMaster extends BaseModel
{
    protected $table = 'shift_masters';

    /**
     * Mass assignable attributes
     */
    protected $fillable = [
        'code',
        'name',
        'shift_change_time',

        'shift_type',
        'week_off_type',

        'start_time',
        'end_time',
        'first_half_start_at',
        'first_half_end_at',
        'second_half_start_at',
        'second_half_end_at',
        'shift_duration_hours',

        'overtime_start_minutes',

        'is_first_in_last_out_punch',
        'is_night_shift',
        'is_flexible',
        'is_system',

        'is_lunch_time_flexible',
        'lunch_break_minutes',
        'lunch_start_time',
        'lunch_end_time',

        'is_dinner_time_flexible',
        'dinner_break_minutes',
        'dinner_start_time',
        'dinner_end_time',

        'is_break_time_flexible',
        'break_minutes',
        'break_start_time',
        'break_end_time',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'shift_change_time' => 'datetime:H:i',

        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'first_half_start_at' => 'datetime:H:i',
        'first_half_end_at' => 'datetime:H:i',
        'second_half_start_at' => 'datetime:H:i',
        'second_half_end_at' => 'datetime:H:i',

        'lunch_start_time' => 'datetime:H:i',
        'lunch_end_time' => 'datetime:H:i',
        'dinner_start_time' => 'datetime:H:i',
        'dinner_end_time' => 'datetime:H:i',
        'break_start_time' => 'datetime:H:i',
        'break_end_time' => 'datetime:H:i',

        'is_first_in_last_out_punch' => 'boolean',
        'is_night_shift' => 'boolean',
        'is_flexible' => 'boolean',
        'is_system' => 'boolean',

        'is_lunch_time_flexible' => 'boolean',
        'is_dinner_time_flexible' => 'boolean',
        'is_break_time_flexible' => 'boolean',
    ];

    /**
     * Constants for enums (recommended)
     */
    public const SHIFT_TYPE_FIXED = 'fixed';

    public const SHIFT_TYPE_ROTATIONAL = 'rotational';

    public const WEEK_OFF_FIXED = 'fixed';

    public const WEEK_OFF_ROTATIONAL = 'rotational';

    public const WEEK_OFF_NONE = 'none';

    /**
     * Relationships
     */
    public function employees()
    {
        return $this->hasMany(Employee::class, 'shift_master_id');
    }

    public function generalSetup()
    {
        return $this->hasOne(ShiftGeneralSetup::class);
    }

    public function timeSlabSetups()
    {
        return $this->hasMany(ShiftTimeSlabSetup::class);
    }

    public function lateMarkSetup()
    {
        return $this->hasOne(ShiftLateMarkSetup::class);
    }

    public function dayWorkSetup()
    {
        return $this->hasOne(ShiftDayWorkSetup::class);
    }

    public function overTimeSetup()
    {
        return $this->hasOne(ShiftOverTimeSetup::class);
    }

    public function compOffSetup()
    {
        return $this->hasOne(ShiftCompOffSetup::class);
    }

    protected static function booted()
    {
        static::created(function ($shift) {
            $shift->generalSetup()->create();
            $shift->lateMarkSetup()->create();
            $shift->dayWorkSetup()->create();
            $shift->overTimeSetup()->create();
            $shift->compOffSetup()->create();
        });
    }
}

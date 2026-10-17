<?php

namespace App\Models;

use App\Services\EmployeeReportingService;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EmployeePosition extends Pivot
{
    protected $table = 'employee_position_pivot';

    protected static function booted(): void
    {
        static::saved(fn () => app(EmployeeReportingService::class)->synchronize());
        static::deleted(fn () => app(EmployeeReportingService::class)->synchronize());
    }
}

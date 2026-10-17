<?php

namespace App\Models;

use App\Enums\PositionStatus;
use App\Services\EmployeeReportingService;
use App\Services\OrganizationalUnitService;
use App\Traits\HasApprovalWorkflow;
use App\Traits\HasVisibilityScope; // Import the new enum
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Position extends BaseModel
{
    use HasApprovalWorkflow, HasFactory, HasVisibilityScope;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'positions'; // Explicitly define table name if it's not the plural of model name

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'job_role_id',
        'division_ou_id',
        'organizational_unit_id',
        'department_id',
        'job_title_id',
        'job_grade_id',
        'reports_to_position_id',
        'is_multi_territory', // New field to indicate if the position can have multiple territories
        'hq_territory_id',
        'description',
        'status',
        'location_id',
        'level',
        'approval_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => PositionStatus::class, // Cast to the new PositionStatus enum
    ];

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class);
    }

    /**
     * Get the territory associated with the position.
     */
    public function territories()
    {
        return $this->belongsToMany(Territory::class, 'position_territory_pivot', 'position_id', 'territory_id');
    }

    public function hqTerritory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'hq_territory_id');
    }

    /**
     * Get the division associated with the position.
     */
    public function division()
    {
        return $this->belongsTo(
            OrganizationalUnit::class,
            'division_ou_id'
        );
    }

    /**
     * Get the department associated with the position.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(EmpDepartment::class, 'department_id');
    }

    /**
     * Get the job title associated with the position.
     */
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(EmpJobTitle::class, 'job_title_id');
    }

    /**
     * Get the job grade associated with the position.
     */
    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(EmpGrade::class, 'job_grade_id');
    }

    /**
     * Get the position this position reports to.
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'reports_to_position_id');
    }

    /**
     * Get the positions that report to this position.
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Position::class, 'reports_to_position_id');
    }

    /**
     * Get the location master associated with the position.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'location_id');
    }

    /**
     * The organizational units that belong to the position.
     */
    public function organizationalUnits(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationalUnit::class, 'position_organizational_unit_pivot', 'position_id', 'organizational_unit_id')
            ->withTimestamps(); // Assuming your pivot table has timestamps based on the other pivot
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_position_pivot', 'position_id', 'employee_id')
            ->using(EmployeePosition::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function ancestors(): Collection
    {
        $ancestors = collect();
        $current = $this->reportsTo;

        while ($current) {
            $ancestors->push($current);
            $current = $current->reportsTo;
        }

        return $ancestors;
    }

    public function getRecordTitle(): string
    {
        return $this->name;
    }

    protected static function booted(): void
    {
        static::saving(fn (Position $position) => OrganizationalUnitService::normalizeDivision($position));
        static::saving(fn (Position $position) => app(EmployeeReportingService::class)->validatePosition($position));
        static::saved(fn () => app(EmployeeReportingService::class)->synchronize());
        static::deleted(fn () => app(EmployeeReportingService::class)->synchronize());
        static::restored(fn () => app(EmployeeReportingService::class)->synchronize());
    }
}

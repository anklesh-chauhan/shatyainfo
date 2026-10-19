<?php

namespace App\Models;

use App\Traits\HasOperationalApproval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesDcr extends BaseModel
{
    use HasFactory, HasOperationalApproval, SoftDeletes;

    protected $fillable = [
        'dcr_date',
        'user_id',
        'sales_tour_plan_id',
        'approval_status',
        'approved_by',
        'approved_at',
        'total_expense',
        'submitted_at',
        'approved_at',
        'remarks',
        'total_expense_approved',
        'total_expense_rejected',
        'rejected_at',
        'rejected_by',
        'rejected_remarks',
        'approved_remarks',
        'territory_id',
        'route',
        'sales_person_id',
        'sales_manager_id',
        'distance_covered',
        'duration',
        'visits_count',
        'orders_count',
    ];

    protected $casts = [
        'dcr_date' => 'date',
        'submitted_at' => 'timestamp',
        'approved_at' => 'timestamp',
        'rejected_at' => 'timestamp',
        'route' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function tourPlan(): BelongsTo
    {
        return $this->belongsTo(SalesTourPlan::class, 'sales_tour_plan_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(SalesDcrExpense::class);
    }

    public function travelSegments(): HasMany
    {
        return $this->hasMany(TravelSegment::class);
    }

    public function updateVisitCount(): void
    {
        $this->visits_count = $this->visits()->count();
        $this->saveQuietly();
    }

    public function recalculateTotalExpense(): void
    {
        $total = $this->expenses()->sum('amount');

        $this->forceFill([
            'total_expense' => $total,
        ])->save();
    }
}

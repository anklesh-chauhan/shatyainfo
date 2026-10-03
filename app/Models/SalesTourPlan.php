<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesTourPlan extends BaseModel
{
    use HasApprovalWorkflow, HasFactory;

    protected $fillable = [
        'user_id',
        'month',
        'approval_status',
        'manager_remarks',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'month' => 'date:Y-m',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The sales employee (creator) of the plan.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The details of the tour plan.
     */
    public function details(): HasMany
    {
        return $this->hasMany(SalesTourPlanDetail::class, 'sales_tour_plan_id');
    }

    /**
     * The manager who approved the plan.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Related patches for this plan.
     */
    public function patches(): HasMany
    {
        return $this->hasMany(Patch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeApproved($query)
    {
        return $query->where('approval_status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('approval_status', 'submitted');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isEditable(): bool
    {
        return in_array($this->approval_status, ['draft', 'rejected']);
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isSubmitted(): bool
    {
        return $this->approval_status === 'submitted';
    }

    public function scopeApplyOwnVisibility(Builder $query, $user): Builder
    {
        return $query->where($query->getModel()->getTable().'.user_id', $user->id);
    }

    public function scopeApplyTerritoryVisibility(
        Builder $query,
        array $territoryIds
    ): Builder {
        return $query->whereHas('details', function ($q) use ($territoryIds) {
            $q->whereIn('territory_id', $territoryIds);
        });
    }
}

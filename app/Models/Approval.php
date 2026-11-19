<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

class Approval extends BaseModel
{
    protected $table = 'approvals';

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'approval_flow_id',
        'module',
        'record_type',
        'record_id',
        'requested_by',
        'requested_amount',
        'territory_id',
        'flow_version',
        'selected_steps',
        'selected_approvers',
        'submitted_record_summary',
        'approval_status',
        'completed_at',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'selected_steps' => 'array',
            'selected_approvers' => 'array',
            'submitted_record_summary' => 'array',
            'completed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ApprovalActivity::class)->latest();
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function currentStep(): ?ApprovalStep
    {
        return $this->steps()
            ->where('status', ApprovalStepStatus::Pending->value)
            ->orderBy('step_order')
            ->first();
    }

    public function isFullyApproved(): bool
    {
        return ! $this->steps()
            ->whereIn('status', [ApprovalStepStatus::Pending->value, ApprovalStepStatus::Rejected->value])
            ->exists();
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['super_admin', 'administration_admin']) || $user->can('AccessAllRecords')) {
            return $query;
        }

        return $query->where(fn (Builder $approvals): Builder => $approvals
            ->where('requested_by', $user->id)
            ->orWhereHas('steps', fn (Builder $steps): Builder => $steps->where('assigned_user_id', $user->id)));
    }

    public function scopeAvailableOnMobile(Builder $query): Builder
    {
        return $query->whereNotIn('approvable_type', [Lead::class, Deal::class]);
    }

    public function scopeDraftForUser(Builder $query): Builder
    {
        $userId = Auth::id();

        return $query->where('approval_status', ApprovalStatus::Pending->value)
            ->whereHas('steps', function (Builder $stepQuery) use ($userId) {
                $stepQuery->where('status', ApprovalStepStatus::Pending->value)
                    ->where('assigned_user_id', $userId)
                    ->orderBy('step_order')
                    ->limit(1);
            });
    }

    public function getDocumentNumber(): ?string
    {
        $approvable = $this->approvable;

        if (! $approvable) {
            return null;
        }

        // Preferred: method-based contract
        if (method_exists($approvable, 'getDocumentNumber')) {
            return $approvable->getDocumentNumber();
        }

        // Fallback: common field names
        foreach (['document_number', 'doc_no', 'number', 'code'] as $field) {
            if (isset($approvable->{$field}) && filled($approvable->{$field})) {
                return $approvable->{$field};
            }
        }

        return null;
    }

    public static function scopeSortByStatusPriority(Builder $query, string $direction): Builder
    {
        return $query->orderByRaw("CASE approval_status WHEN 'draft' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 ELSE 0 END $direction");
    }
}

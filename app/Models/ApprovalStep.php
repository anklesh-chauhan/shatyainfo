<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ApprovalStep extends BaseModel
{
    protected $table = 'approval_steps';

    protected $fillable = [
        'approval_id',
        'step_order',
        'job_role_id',
        'assigned_user_id',
        'reassigned_from_user_id',
        'status',
        'comments',
        'approved_at',
        'due_at',
        'reminded_at',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStepStatus::class,
            'approved_at' => 'datetime',
            'due_at' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    public function scopeCurrentFor(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_user_id', $userId)
            ->where('status', ApprovalStepStatus::Pending->value)
            ->whereHas('approval', fn (Builder $approval): Builder => $approval
                ->where('approval_status', ApprovalStatus::Pending->value)->whereNull('finalized_at'))
            ->where('approval_steps.id', static::query()->from('approval_steps as current_steps')
                ->select('current_steps.id')
                ->whereColumn('current_steps.approval_id', 'approval_steps.approval_id')
                ->where('current_steps.status', ApprovalStepStatus::Pending->value)
                ->orderBy('current_steps.step_order')->orderBy('current_steps.id')->limit(1)
                ->withoutGlobalScopes()->whereNull('current_steps.deleted_at'));
    }

    public function scopeAvailableOnMobile(Builder $query): Builder
    {
        return $query->whereHas('approval', fn (Builder $approval): Builder => $approval->availableOnMobile());
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function previousApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reassigned_from_user_id');
    }

    public function jobRole()
    {
        return $this->belongsTo(JobRole::class);
    }

    public function transitionTo(ApprovalStepStatus $status, ?string $comments = null): void
    {
        if (! $this->status instanceof ApprovalStepStatus) {
            throw new LogicException('Approval step status is not cast to ApprovalStepStatus.');
        }

        $this->status->assertCanTransitionTo($status);

        $this->forceFill([
            'status' => $status,
            'comments' => $comments,
            'approved_at' => in_array($status, [ApprovalStepStatus::Approved, ApprovalStepStatus::Rejected], true) ? now() : $this->approved_at,
        ])->save();
    }
}

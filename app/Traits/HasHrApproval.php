<?php

namespace App\Traits;

use App\Models\ApprovalSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait HasHrApproval
{
    use HasApprovalWorkflow;

    protected static function bootHasHrApproval(): void
    {
        static::creating(function (Model $record): void {
            $record->approval_status = ApprovalSetting::moduleRequiresApproval($record::class) ? 'draft' : 'approved';
        });
        static::updating(function (Model $record): void {
            $record->assertHrEditable();
            if ($record->isDirty(['approval_status', 'applied_at'])) {
                throw ValidationException::withMessages(['approval' => 'Use the approval workflow to change this status.']);
            }
        });
        static::deleting(fn (Model $record) => $record->assertHrEditable());
    }

    public function assertHrEditable(): void
    {
        if ($this->getRawOriginal('approval_status') === 'approved'
            || $this->approval()->whereIn('approval_status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['approval' => 'Submitted or approved HR records cannot be edited or deleted.']);
        }
    }

    public function prepareOperationalApproval(): void
    {
        $this->assertHrEditable();
    }

    public function markOperationalApprovalSubmitted(): void
    {
        $this->forceFill(['approval_status' => 'pending'])->saveQuietly();
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }

    protected function resolveApprovalTerritoryId(): ?int
    {
        return $this->employee?->territory_id;
    }
}

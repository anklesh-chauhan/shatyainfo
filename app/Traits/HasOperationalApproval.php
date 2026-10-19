<?php

namespace App\Traits;

use App\Enums\PurchaseOrderStatus;
use App\Models\ApprovalSetting;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryAdjustment;
use App\Models\InventoryTransfer;
use App\Models\PurchaseOrder;
use App\Models\SalesDcr;
use App\Models\SalesDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait HasOperationalApproval
{
    use HasApprovalWorkflow;

    protected static function bootHasOperationalApproval(): void
    {
        static::updating(function (Model $model): void {
            $model->assertApprovalDocumentEditable();
            if (ApprovalSetting::moduleRequiresApproval($model::class) && $model->isDirty('approval_status')) {
                throw ValidationException::withMessages(['approval_status' => 'Use the approval workflow to change approval status.']);
            }
            if ($model->isDirty('status') && in_array($model->getAttributes()['status'] ?? null, ['approved', 'accepted', 'sent', 'posted'], true)) {
                $model->assertApprovalComplete();
            }
        });
        static::deleting(fn (Model $model) => $model->assertApprovalDocumentEditable());
        static::creating(function (Model $model): void {
            if ($model instanceof GoodsReceiptNote) {
                $model->purchaseOrder?->assertApprovalComplete();
            }
            if ($model instanceof DeliveryChallan) {
                $model->salesInvoice?->assertApprovalComplete();
            }
            if (ApprovalSetting::moduleRequiresApproval($model::class)) {
                if (isset($model->approval_status)) {
                    $model->approval_status = 'draft';
                }
                if (in_array($model->getAttributes()['status'] ?? null, ['approved', 'accepted', 'sent', 'posted'], true)) {
                    throw ValidationException::withMessages(['status' => 'Create a draft and submit it for approval first.']);
                }
            }
        });
    }

    public function prepareOperationalApproval(): void
    {
        $this->assertApprovalDocumentEditable();
        if (in_array($this->getAttributes()['status'] ?? null, ['cancelled', 'canceled'], true)) {
            throw ValidationException::withMessages(['approval' => 'Cancelled documents cannot be submitted.']);
        }
        if ($this instanceof SalesDcr) {
            if (! $this->visits()->exists()) {
                throw ValidationException::withMessages(['dcr' => 'Add at least one visit before submitting the DCR.']);
            }
            $this->forceFill(['total_expense' => $this->expenses()->sum('amount')])->saveQuietly();
        }
        if ($this instanceof PurchaseOrder) {
            $this->recalculateTotals();
        }
    }

    public function markOperationalApprovalSubmitted(): void
    {
        $data = match (true) {
            $this instanceof SalesDcr => ['approval_status' => 'submitted', 'submitted_at' => now(), 'approved_by' => null, 'approved_at' => null, 'rejected_by' => null, 'rejected_at' => null, 'total_expense_approved' => 0, 'total_expense_rejected' => 0],
            $this instanceof PurchaseOrder => ['approval_status' => 'pending', 'status' => PurchaseOrderStatus::Submitted],
            $this instanceof SalesDocument => ['approval_status' => 'pending'],
            default => [],
        };
        if ($data !== []) {
            $this->forceFill($data)->saveQuietly();
        }
    }

    protected function resolveApprovalAmount(): float
    {
        return match (true) {
            $this instanceof SalesDcr => (float) $this->total_expense,
            $this instanceof PurchaseOrder => (float) $this->total_amount,
            $this instanceof InventoryAdjustment, $this instanceof InventoryTransfer => (float) $this->quantity * (float) $this->unit_cost,
            $this instanceof GoodsReceiptNote => (float) $this->lines()->get()->sum(fn ($line): float => (float) $line->quantity_received * (float) $line->unit_cost),
            $this instanceof DeliveryChallan => (float) $this->lines()->get()->sum(fn ($line): float => (float) $line->quantity_delivered * (float) $line->unit_cost),
            default => (float) ($this->total ?? $this->amount ?? 0),
        };
    }

    public function approvalDocumentIsLocked(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->getOriginal('approval_status') === 'approved'
            || in_array($this->approval()->value('approval_status'), ['pending', 'approved'], true)
            || (method_exists($this, 'isPosted') && $this->isPosted());
    }

    public function assertApprovalDocumentEditable(): void
    {
        if ($this->approvalDocumentIsLocked()) {
            throw ValidationException::withMessages(['approval' => 'This document is pending approval, approved, or posted and cannot be changed.']);
        }
    }

    public function canProceedAfterApproval(): bool
    {
        $status = $this->approval()->value('approval_status');
        if ($status !== null) {
            return $status === 'approved';
        }

        return ! ApprovalSetting::moduleRequiresApproval($this::class);
    }

    public function assertApprovalComplete(): void
    {
        if (! $this->canProceedAfterApproval()) {
            throw ValidationException::withMessages(['approval' => 'Complete the configured approval workflow before continuing.']);
        }
    }
}

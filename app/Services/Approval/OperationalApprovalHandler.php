<?php

namespace App\Services\Approval;

use App\Domains\Approval\Contracts\ApprovalHandler;
use App\Enums\PurchaseOrderStatus;
use App\Models\Approval;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryAdjustment;
use App\Models\InventoryTransfer;
use App\Models\PurchaseOrder;
use App\Models\SalesDcr;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class OperationalApprovalHandler implements ApprovalHandler
{
    /** @return array<class-string<Model>> */
    public static function supports(): array
    {
        return [SalesDcr::class, SalesOrder::class, SalesInvoice::class, PurchaseOrder::class,
            GoodsReceiptNote::class, InventoryAdjustment::class, InventoryTransfer::class, DeliveryChallan::class];
    }

    public function handle(Model $model, Approval $approval): void
    {
        if (! in_array($model::class, self::supports(), true)) {
            throw new LogicException('Unsupported operational approval document.');
        }

        if (! in_array($approval->approval_status, ['approved', 'rejected', 'cancelled'], true)) {
            return;
        }

        // Stock documents retain their posting status; the approval record authorizes posting.
        if (! $model instanceof SalesDcr && ! $model instanceof SalesOrder && ! $model instanceof SalesInvoice && ! $model instanceof PurchaseOrder) {
            return;
        }

        $status = $approval->approval_status;
        $data = ['approval_status' => $status === 'cancelled' ? 'draft' : $status];
        if ($model instanceof PurchaseOrder) {
            $data['status'] = $status === 'approved' ? PurchaseOrderStatus::Approved : PurchaseOrderStatus::Draft;
        }
        if ($model instanceof SalesDcr) {
            $step = $approval->steps()->where('status', $status)->orderByDesc('step_order')->first();
            $data += [
                'approved_by' => $status === 'approved' ? $step?->assigned_user_id : null,
                'approved_at' => $status === 'approved' ? $approval->completed_at : null,
                'rejected_by' => $status === 'rejected' ? $step?->assigned_user_id : null,
                'rejected_at' => $status === 'rejected' ? $approval->completed_at : null,
                'total_expense_approved' => $status === 'approved' ? $approval->requested_amount : 0,
                'total_expense_rejected' => $status === 'rejected' ? $approval->requested_amount : 0,
            ];
        }
        $model->forceFill($data)->saveQuietly();
    }
}

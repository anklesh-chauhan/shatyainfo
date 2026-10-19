<?php

namespace App\Services\Inventory;

use App\Enums\DeliveryChallanStatus;
use App\Enums\GoodsReceiptNoteStatus;
use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryDocumentStatus;
use App\Enums\SampleIssueStatus;
use App\Enums\SgipStockSource;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAudit;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryTransfer;
use App\Models\PurchaseOrderLine;
use App\Models\SalesDocumentItem;
use App\Models\SampleIssue;
use App\Models\SampleRequestLine;
use App\Models\SgipDistribution;
use App\Models\VisitPreference;
use App\Services\Marketing\MarketingCampaignQuotaService;
use App\Services\SGIPComplianceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    public function postAdjustment(InventoryAdjustment $adjustment, ?int $userId = null): void
    {
        if ($adjustment->isPosted()) {
            return;
        }

        DB::transaction(function () use ($adjustment, $userId): void {
            $adjustment = $adjustment->newQuery()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->isPosted()) {
                return;
            }
            $adjustment->assertApprovalComplete();
            $quantity = (float) $adjustment->quantity;

            if ($quantity <= 0) {
                throw new RuntimeException('Adjustment quantity must be greater than zero.');
            }

            $adjustmentType = $adjustment->adjustment_type instanceof InventoryAdjustmentType
                ? $adjustment->adjustment_type
                : InventoryAdjustmentType::tryFrom((string) $adjustment->adjustment_type);

            if ($adjustmentType === null) {
                throw new RuntimeException('Invalid adjustment type.');
            }

            $direction = $adjustmentType->isInbound() ? 1 : -1;

            $this->postMovement(
                itemMasterId: $adjustment->item_master_id,
                locationMasterId: $adjustment->location_master_id,
                quantity: $quantity * $direction,
                movementType: 'adjustment_'.$adjustmentType->value,
                reference: $adjustment,
                unitCost: $adjustment->unit_cost !== null ? (float) $adjustment->unit_cost : null,
                remarks: $adjustment->reason.($adjustment->remarks ? ': '.$adjustment->remarks : null),
            );

            $adjustment->forceFill([
                'status' => InventoryDocumentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->saveQuietly();
        });
    }

    public function postTransfer(InventoryTransfer $transfer, ?int $userId = null): void
    {
        if ($transfer->isPosted()) {
            return;
        }

        DB::transaction(function () use ($transfer, $userId): void {
            $transfer = $transfer->newQuery()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->isPosted()) {
                return;
            }
            $transfer->assertApprovalComplete();
            if ((int) $transfer->from_location_master_id === (int) $transfer->to_location_master_id) {
                throw new RuntimeException('Source and destination locations must be different.');
            }

            $quantity = (float) $transfer->quantity;

            if ($quantity <= 0) {
                throw new RuntimeException('Transfer quantity must be greater than zero.');
            }

            $this->postMovement(
                itemMasterId: $transfer->item_master_id,
                locationMasterId: $transfer->from_location_master_id,
                quantity: -$quantity,
                movementType: 'transfer_out',
                reference: $transfer,
                unitCost: $transfer->unit_cost !== null ? (float) $transfer->unit_cost : null,
                remarks: $transfer->remarks,
            );

            $this->postMovement(
                itemMasterId: $transfer->item_master_id,
                locationMasterId: $transfer->to_location_master_id,
                quantity: $quantity,
                movementType: 'transfer_in',
                reference: $transfer,
                unitCost: $transfer->unit_cost !== null ? (float) $transfer->unit_cost : null,
                remarks: $transfer->remarks,
            );

            $transfer->forceFill([
                'status' => InventoryDocumentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->saveQuietly();
        });
    }

    public function postAudit(InventoryAudit $audit, ?int $userId = null): void
    {
        if ($audit->isPosted()) {
            return;
        }

        DB::transaction(function () use ($audit, $userId): void {
            $audit->loadMissing('lines');

            foreach ($audit->lines as $line) {
                $stock = $this->stockFor((int) $line->item_master_id, (int) $audit->location_master_id);
                $systemQuantity = (float) $stock->quantity_on_hand;
                $countedQuantity = (float) $line->counted_quantity;
                $varianceQuantity = $countedQuantity - $systemQuantity;

                $line->forceFill([
                    'system_quantity' => $systemQuantity,
                    'variance_quantity' => $varianceQuantity,
                ])->save();

                if ($varianceQuantity == 0.0) {
                    continue;
                }

                $this->postMovement(
                    itemMasterId: $line->item_master_id,
                    locationMasterId: $audit->location_master_id,
                    quantity: $varianceQuantity,
                    movementType: 'audit_variance',
                    reference: $audit,
                    unitCost: null,
                    remarks: $line->remarks,
                );
            }

            $audit->forceFill([
                'status' => InventoryDocumentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->save();
        });
    }

    public function postGrn(GoodsReceiptNote $grn, ?int $userId = null): void
    {
        if ($grn->isPosted()) {
            return;
        }

        DB::transaction(function () use ($grn, $userId): void {
            $grn = $grn->newQuery()->lockForUpdate()->findOrFail($grn->id);
            if ($grn->isPosted()) {
                return;
            }
            $grn->assertApprovalComplete();
            $grn->loadMissing(['lines.purchaseOrderLine', 'purchaseOrder']);
            $grn->purchaseOrder?->assertApprovalComplete();

            if ($grn->lines->isEmpty()) {
                throw new RuntimeException('GRN must have at least one line before posting.');
            }

            foreach ($grn->lines as $line) {
                $quantity = (float) $line->quantity_received;

                if ($quantity <= 0) {
                    throw new RuntimeException('GRN line quantity must be greater than zero.');
                }

                if ($line->purchase_order_line_id !== null) {
                    $poLine = $line->purchaseOrderLine;

                    if ($poLine === null) {
                        throw new RuntimeException('Linked purchase order line was not found.');
                    }

                    $remaining = $poLine->remainingQuantity();

                    if ($quantity > $remaining) {
                        throw new RuntimeException("Received quantity exceeds remaining PO quantity for {$poLine->item?->item_name}.");
                    }
                }

                $this->postMovement(
                    itemMasterId: $line->item_master_id,
                    locationMasterId: $grn->location_master_id,
                    quantity: $quantity,
                    movementType: 'grn_receipt',
                    reference: $grn,
                    unitCost: (float) $line->unit_cost,
                    remarks: $line->remarks ?? $line->batch_number,
                );

                if ($line->purchase_order_line_id !== null) {
                    $poLine = PurchaseOrderLine::query()->lockForUpdate()->find($line->purchase_order_line_id);

                    if ($poLine !== null) {
                        $poLine->forceFill([
                            'quantity_received' => (float) $poLine->quantity_received + $quantity,
                        ])->saveQuietly();
                    }
                }
            }

            $grn->forceFill([
                'status' => GoodsReceiptNoteStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->saveQuietly();

            $grn->purchaseOrder?->refreshReceiptStatus();
        });
    }

    public function postDeliveryChallan(DeliveryChallan $challan, ?int $userId = null): void
    {
        if ($challan->isPosted()) {
            return;
        }

        DB::transaction(function () use ($challan, $userId): void {
            $challan = $challan->newQuery()->lockForUpdate()->findOrFail($challan->id);
            if ($challan->isPosted()) {
                return;
            }
            $challan->assertApprovalComplete();
            $challan->loadMissing(['lines.salesDocumentItem', 'salesInvoice']);
            $challan->salesInvoice?->assertApprovalComplete();

            if ($challan->lines->isEmpty()) {
                throw new RuntimeException('Delivery challan must have at least one line before posting.');
            }

            foreach ($challan->lines as $line) {
                $quantity = (float) $line->quantity_delivered;

                if ($quantity <= 0) {
                    throw new RuntimeException('Delivery challan line quantity must be greater than zero.');
                }

                if ($line->sales_document_item_id !== null) {
                    $invoiceItem = $line->salesDocumentItem;

                    if ($invoiceItem === null) {
                        throw new RuntimeException('Linked sales invoice line was not found.');
                    }

                    $remaining = $invoiceItem->remainingQuantity();

                    if ($quantity > $remaining) {
                        throw new RuntimeException("Delivered quantity exceeds remaining invoice quantity for {$invoiceItem->itemMaster?->item_name}.");
                    }
                }

                $this->postMovement(
                    itemMasterId: $line->item_master_id,
                    locationMasterId: $challan->location_master_id,
                    quantity: -$quantity,
                    movementType: 'delivery_challan',
                    reference: $challan,
                    unitCost: $line->unit_cost !== null ? (float) $line->unit_cost : null,
                    remarks: $line->remarks ?? $line->batch_number,
                );

                if ($line->sales_document_item_id !== null) {
                    $invoiceItem = SalesDocumentItem::query()->lockForUpdate()->find($line->sales_document_item_id);

                    if ($invoiceItem !== null) {
                        $invoiceItem->forceFill([
                            'quantity_delivered' => (float) $invoiceItem->quantity_delivered + $quantity,
                        ])->saveQuietly();
                    }
                }
            }

            $challan->forceFill([
                'status' => DeliveryChallanStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->saveQuietly();

            $challan->salesInvoice?->refreshDeliveryStatus();
        });
    }

    public function postSampleIssue(SampleIssue $sampleIssue, ?int $userId = null): void
    {
        if ($sampleIssue->isPosted()) {
            return;
        }

        DB::transaction(function () use ($sampleIssue, $userId): void {
            $sampleIssue->loadMissing(['lines.sampleRequestLine.item', 'sampleRequest']);

            if ($sampleIssue->lines->isEmpty()) {
                throw new RuntimeException('Sample issue must have at least one line before posting.');
            }

            if ((int) $sampleIssue->from_location_id === (int) $sampleIssue->to_location_id) {
                throw new RuntimeException('Sample issue source and destination locations must be different.');
            }

            foreach ($sampleIssue->lines as $line) {
                $quantity = (float) $line->quantity;
                $requestLine = SampleRequestLine::query()
                    ->with('item')
                    ->lockForUpdate()
                    ->find($line->sample_request_line_id);

                if ($quantity <= 0) {
                    throw new RuntimeException('Sample issue line quantity must be greater than zero.');
                }

                if ($requestLine === null || (int) $requestLine->sample_request_id !== (int) $sampleIssue->sample_request_id) {
                    throw new RuntimeException('Sample issue line must belong to the selected sample request.');
                }

                if ((int) $requestLine->item_master_id !== (int) $line->item_master_id) {
                    throw new RuntimeException('Sample issue item must match the approved request line.');
                }

                if ($quantity > $requestLine->remainingApprovedQuantity()) {
                    throw new RuntimeException("Issued quantity exceeds the remaining approved quantity for {$requestLine->item?->item_name}.");
                }

                $this->postMovement(
                    itemMasterId: $line->item_master_id,
                    locationMasterId: $sampleIssue->from_location_id,
                    quantity: -$quantity,
                    movementType: 'sample_issue_out',
                    reference: $sampleIssue,
                    unitCost: $line->unit_cost !== null ? (float) $line->unit_cost : null,
                    remarks: $line->remarks,
                );

                $this->postMovement(
                    itemMasterId: $line->item_master_id,
                    locationMasterId: $sampleIssue->to_location_id,
                    quantity: $quantity,
                    movementType: 'sample_issue_in',
                    reference: $sampleIssue,
                    unitCost: $line->unit_cost !== null ? (float) $line->unit_cost : null,
                    remarks: $line->remarks,
                );

                $requestLine->forceFill([
                    'quantity_issued' => (float) $requestLine->quantity_issued + $quantity,
                ])->save();
            }

            $sampleIssue->forceFill([
                'status' => SampleIssueStatus::Posted,
                'issued_by' => $userId,
                'posted_at' => now(),
            ])->save();

            $sampleIssue->sampleRequest->refreshIssueStatus();
        });
    }

    public function postSgipDistribution(SgipDistribution $distribution): void
    {
        if ($distribution->isInventoryPosted()) {
            return;
        }

        DB::transaction(function () use ($distribution): void {
            $distribution->loadMissing(['items.item', 'sampleIssue.sampleRequest']);

            SGIPComplianceService::validate($distribution, true);

            if ($distribution->items->isEmpty()) {
                throw new RuntimeException('SGIP distribution must have at least one item.');
            }

            $preferences = VisitPreference::current();
            $source = $preferences->sgip_stock_source instanceof SgipStockSource
                ? $preferences->sgip_stock_source
                : SgipStockSource::tryFrom((string) $preferences->sgip_stock_source);

            $sourceLocationId = match ($source) {
                SgipStockSource::SampleIssue => $this->sampleIssueSourceLocation($distribution),
                SgipStockSource::Headquarters => $preferences->sgip_hq_location_id,
                default => null,
            };

            if ($sourceLocationId === null) {
                throw new RuntimeException('No inventory source location is configured for this SGIP distribution.');
            }

            foreach ($distribution->items as $item) {
                $quantity = (float) $item->quantity;

                if ($quantity <= 0) {
                    throw new RuntimeException('SGIP item quantity must be greater than zero.');
                }

                if ($item->item?->item_type === null) {
                    throw new RuntimeException("{$item->item?->item_name} is not classified as an SGIP item.");
                }

                $this->postMovement(
                    itemMasterId: $item->item_master_id,
                    locationMasterId: (int) $sourceLocationId,
                    quantity: -$quantity,
                    movementType: 'sgip_distribution',
                    reference: $distribution,
                    unitCost: (float) $item->unit_value,
                    remarks: 'Visit SGIP distribution',
                );
            }

            $distribution->forceFill([
                'approval_status' => 'approved',
                'inventory_source_location_id' => $sourceLocationId,
                'inventory_posted_at' => now(),
            ])->saveQuietly();

            app(MarketingCampaignQuotaService::class)->consumeQuota($distribution);
        });
    }

    private function sampleIssueSourceLocation(SgipDistribution $distribution): int
    {
        $sampleIssue = $distribution->sampleIssue;

        if ($sampleIssue === null || ! $sampleIssue->isPosted()) {
            throw new RuntimeException('A posted Sample Issue is required before distributing SGIP items.');
        }

        if ($distribution->employee_id !== null
            && (int) $sampleIssue->sampleRequest?->employee_id !== (int) $distribution->employee_id) {
            throw new RuntimeException('The selected Sample Issue belongs to a different employee.');
        }

        return (int) $sampleIssue->to_location_id;
    }

    public function stockFor(int $itemMasterId, int $locationMasterId): InventoryStock
    {
        return InventoryStock::query()
            ->where('item_master_id', $itemMasterId)
            ->where('location_master_id', $locationMasterId)
            ->lockForUpdate()
            ->firstOrCreate([
                'item_master_id' => $itemMasterId,
                'location_master_id' => $locationMasterId,
            ]);
    }

    private function postMovement(
        int $itemMasterId,
        int $locationMasterId,
        float $quantity,
        string $movementType,
        Model $reference,
        ?float $unitCost,
        ?string $remarks,
    ): InventoryMovement {
        if ($quantity == 0.0) {
            throw new RuntimeException('Inventory movement quantity cannot be zero.');
        }

        $stock = $this->stockFor($itemMasterId, $locationMasterId);
        $currentQuantity = (float) $stock->quantity_on_hand;
        $newQuantity = $currentQuantity + $quantity;

        if ($newQuantity < 0) {
            throw new RuntimeException('Insufficient stock for this inventory operation.');
        }

        $quantityIn = $quantity > 0 ? $quantity : 0;
        $quantityOut = $quantity < 0 ? abs($quantity) : 0;
        $resolvedUnitCost = $unitCost ?? ($stock->average_cost !== null ? (float) $stock->average_cost : null);
        $totalValue = $resolvedUnitCost !== null ? abs($quantity) * $resolvedUnitCost : null;
        $averageCost = $this->calculateWeightedAverageCost(
            currentQuantity: $currentQuantity,
            currentAverageCost: $stock->average_cost !== null ? (float) $stock->average_cost : null,
            incomingQuantity: $quantity,
            incomingUnitCost: $unitCost,
        );

        $stock->forceFill([
            'quantity_on_hand' => $newQuantity,
            'quantity_available' => $newQuantity - (float) $stock->quantity_reserved,
            'average_cost' => $averageCost,
            'last_movement_at' => now(),
        ])->save();

        DB::table('item_location')->updateOrInsert(
            [
                'item_master_id' => $itemMasterId,
                'location_master_id' => $locationMasterId,
            ],
            [
                'quantity' => $newQuantity,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return InventoryMovement::query()->create([
            'item_master_id' => $itemMasterId,
            'location_master_id' => $locationMasterId,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'movement_type' => $movementType,
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'balance_after' => $newQuantity,
            'unit_cost' => $resolvedUnitCost,
            'total_value' => $totalValue,
            'movement_at' => now(),
            'remarks' => $remarks,
        ]);
    }

    private function calculateWeightedAverageCost(
        float $currentQuantity,
        ?float $currentAverageCost,
        float $incomingQuantity,
        ?float $incomingUnitCost,
    ): ?float {
        if ($incomingQuantity <= 0 || $incomingUnitCost === null) {
            return $currentAverageCost;
        }

        if ($currentQuantity <= 0 || $currentAverageCost === null) {
            return round($incomingUnitCost, 4);
        }

        $existingValue = $currentQuantity * $currentAverageCost;
        $incomingValue = $incomingQuantity * $incomingUnitCost;
        $newQuantity = $currentQuantity + $incomingQuantity;

        if ($newQuantity <= 0) {
            return $currentAverageCost;
        }

        return round(($existingValue + $incomingValue) / $newQuantity, 4);
    }
}

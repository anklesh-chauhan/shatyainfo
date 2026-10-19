<?php

use App\Enums\InventoryAdjustmentType;
use App\Enums\PurchaseOrderStatus;
use App\Helpers\SalesDocumentHelper;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\DeliveryChallan;
use App\Models\ExpenseType;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryAdjustment;
use App\Models\InventoryTransfer;
use App\Models\JobRole;
use App\Models\Position;
use App\Models\PurchaseOrder;
use App\Models\SalesDcr;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Visit;
use App\Services\Approval\ApprovalService;
use App\Services\Approval\OperationalApprovalHandler;
use App\Services\Field\FieldDcrService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function operationalFlow(string $class): array
{
    $requester = reportingUser('Operational Requester');
    $manager = reportingUser('Operational Manager');
    $role = JobRole::query()->create(['name' => 'Approver', 'code' => 'APP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Approver Position', 'code' => 'APPPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [$class]]);
    $flow = ApprovalFlow::query()->create(['module' => class_basename($class), 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    test()->actingAs($requester);

    return [$requester, $manager, $flow];
}

it('exposes every integrated operational module in approval settings', function () {
    foreach (OperationalApprovalHandler::supports() as $class) {
        expect(ApprovalSetting::eligibleModuleOptions())->toHaveKey($class);
    }
});

it('locks sales documents and permits resubmission after rejection', function (string $class) {
    [$requester, $manager] = operationalFlow($class);
    $record = $class::query()->create(['contact_detail_id' => reportingContact('Test', 'Buyer')->id, 'account_master_id' => reportingDoctor($requester, 'Test Account')->id, 'document_number' => 'TEST-001', 'date' => today(), 'sales_person_id' => $requester->id, 'total' => 200, 'status' => 'draft']);
    $approval = $record->startApproval();
    expect($record->fresh()->approval_status)->toBe('pending');
    expect(fn () => $record->fresh()->update(['total' => 300]))->toThrow(ValidationException::class);
    app(ApprovalService::class)->reject($approval, $manager->id, 'Correct amount');
    expect($record->fresh()->approval_status)->toBe('rejected');
    $record->refresh()->update(['total' => 300]);
    $second = $record->fresh()->startApproval();
    expect($second->id)->not->toBe($approval->id);
    app(ApprovalService::class)->approve($second, $manager->id);
    expect($record->fresh()->approval_status)->toBe('approved')
        ->and($record->fresh()->canProceedAfterApproval())->toBeTrue();
    if ($class === SalesOrder::class) {
        $invoice = SalesDocumentHelper::createFrom($record->fresh(), SalesInvoice::class);
        expect($invoice->salesOrders()->whereKey($record->id)->exists())->toBeTrue();
    }
})->with([[SalesOrder::class], [SalesInvoice::class]]);

it('blocks stock posting until approval then posts only once', function () {
    [$requester, $manager] = operationalFlow(InventoryAdjustment::class);
    $item = reportingSampleItem('APP', 'Approval stock');
    $location = reportingLocation('Approval Warehouse');
    $record = InventoryAdjustment::query()->create(['item_master_id' => $item->id, 'location_master_id' => $location->id, 'adjustment_type' => InventoryAdjustmentType::Receipt, 'quantity' => 3, 'unit_cost' => 50, 'reason' => 'Opening']);
    expect(fn () => $record->post())->toThrow(ValidationException::class);
    $approval = $record->startApproval();
    expect((float) $approval->requested_amount)->toBe(150.0);
    expect(fn () => $record->fresh()->post())->toThrow(ValidationException::class);
    app(ApprovalService::class)->approve($approval, $manager->id);
    $record->fresh()->post();
    $record->fresh()->post();
    expect($record->fresh()->isPosted())->toBeTrue();
    $this->assertDatabaseCount('inventory_movements', 1);
});

it('requires approval even when no matching flow was configured', function () {
    [$requester] = operationalFlow(SalesOrder::class);
    $record = SalesOrder::query()->create(['contact_detail_id' => reportingContact('No', 'Flow')->id, 'account_master_id' => reportingDoctor($requester, 'Flow Account')->id, 'document_number' => 'NO-FLOW', 'date' => today(), 'sales_person_id' => $requester->id, 'total' => 200]);
    ApprovalFlow::query()->update(['active' => false]);
    expect(fn () => SalesDocumentHelper::createFrom($record, SalesInvoice::class))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('sales_invoices', 0);
});

it('submits a DCR expense claim and locks its expense lines', function () {
    [$requester, $manager] = operationalFlow(SalesDcr::class);
    $territory = reportingTerritory('DCR Approval');
    $patch = reportingPatch($territory, 'Approval Patch');
    $visit = Visit::query()->create(['document_number' => 'VIS-APP-001', 'employee_id' => $requester->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id, 'visit_date' => today(), 'visit_type' => 'unplanned', 'visit_status' => 'draft']);
    $dcr = $visit->salesDcr;
    $type = ExpenseType::query()->create(['name' => 'Travel', 'code' => 'TRV', 'is_active' => true]);
    $expense = $dcr->expenses()->create(['expense_type_id' => $type->id, 'amount' => 125]);
    app(FieldDcrService::class)->submit($requester, $dcr->fresh());
    $approval = $dcr->fresh()->approval;
    expect((float) $approval->requested_amount)->toBe(125.0);
    expect(fn () => $expense->update(['amount' => 500]))->toThrow(ValidationException::class);
    app(ApprovalService::class)->approve($approval, $manager->id);
    expect($dcr->fresh()->approval_status)->toBe('approved')
        ->and((float) $dcr->fresh()->total_expense_approved)->toBe(125.0);
});

it('maps purchase order totals and applies its approval result', function () {
    [$requester, $manager] = operationalFlow(PurchaseOrder::class);
    $supplier = reportingDoctor($requester, 'Supplier');
    $location = reportingLocation('Receiving');
    $item = reportingSampleItem('POA', 'Purchased sample');
    $po = PurchaseOrder::query()->create(['supplier_id' => $supplier->id, 'location_master_id' => $location->id, 'order_date' => today()]);
    $po->lines()->create(['item_master_id' => $item->id, 'quantity_ordered' => 2, 'unit_price' => 100, 'tax_rate' => 10]);
    $approval = $po->fresh()->startApproval();
    expect((float) $approval->requested_amount)->toBe(220.0);
    app(ApprovalService::class)->approve($approval, $manager->id);
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('blocks each stock document before approval and accepts its final decision', function (string $class) {
    [$requester, $manager] = operationalFlow($class);
    $item = reportingSampleItem('STK', 'Stock approval');
    $from = reportingLocation('Stock From');
    $to = reportingLocation('Stock To');
    $account = reportingDoctor($requester, 'Stock Account');
    $attributes = match ($class) {
        InventoryTransfer::class => ['item_master_id' => $item->id, 'from_location_master_id' => $from->id, 'to_location_master_id' => $to->id, 'quantity' => 2, 'unit_cost' => 10],
        GoodsReceiptNote::class => ['supplier_id' => $account->id, 'location_master_id' => $to->id, 'receipt_date' => today()],
        DeliveryChallan::class => ['customer_id' => $account->id, 'location_master_id' => $from->id, 'delivery_date' => today()],
    };
    $record = $class::query()->create($attributes);
    if ($class !== InventoryTransfer::class) {
        $record->lines()->create(['item_master_id' => $item->id, $class === GoodsReceiptNote::class ? 'quantity_received' : 'quantity_delivered' => 2, 'unit_cost' => 10]);
    }
    expect(fn () => $record->post())->toThrow(ValidationException::class);
    $approval = $record->startApproval();
    expect((float) $approval->requested_amount)->toBe(20.0);
    app(ApprovalService::class)->approve($approval, $manager->id);
    expect($record->fresh()->canProceedAfterApproval())->toBeTrue();
    if ($class !== GoodsReceiptNote::class) {
        app(InventoryService::class)->postAdjustment(InventoryAdjustment::query()->create(['item_master_id' => $item->id, 'location_master_id' => $from->id, 'adjustment_type' => InventoryAdjustmentType::Receipt, 'quantity' => 5, 'unit_cost' => 10, 'reason' => 'Seed stock']));
    }
    $record->fresh()->post();
    expect($record->fresh()->isPosted())->toBeTrue();
})->with([[InventoryTransfer::class], [GoodsReceiptNote::class], [DeliveryChallan::class]]);

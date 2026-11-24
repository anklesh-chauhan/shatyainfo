<?php

use App\Enums\SalesDocumentKind;
use App\Http\Controllers\Api\V1\AccountAddressController;
use App\Http\Controllers\Api\V1\AccountContactController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountTypeController;
use App\Http\Controllers\Api\V1\AddressTypeController;
use App\Http\Controllers\Api\V1\ApprovalRegisterController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CustomerPriceController;
use App\Http\Controllers\Api\V1\DashboardTodayController;
use App\Http\Controllers\Api\V1\DcrController;
use App\Http\Controllers\Api\V1\DcrExpenseController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\DeliveryChallanController;
use App\Http\Controllers\Api\V1\EDetailingController;
use App\Http\Controllers\Api\V1\EmployeeRegisterController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\FieldCoverageController;
use App\Http\Controllers\Api\V1\FieldDcrEventController;
use App\Http\Controllers\Api\V1\FieldDeviceController;
use App\Http\Controllers\Api\V1\FieldExpenseEventController;
use App\Http\Controllers\Api\V1\FieldSyncRecoveryController;
use App\Http\Controllers\Api\V1\FieldTeamAssignmentController;
use App\Http\Controllers\Api\V1\FieldVisitEventController;
use App\Http\Controllers\Api\V1\FlexiblePlanController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\GeoExceptionController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\GpsPointController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemRegisterController;
use App\Http\Controllers\Api\V1\LeadActivityController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LocationRegisterController;
use App\Http\Controllers\Api\V1\ManageEDetailingMaterialController;
use App\Http\Controllers\Api\V1\MarketingCampaignController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MobileNavigationController;
use App\Http\Controllers\Api\V1\MobileQuickLinksController;
use App\Http\Controllers\Api\V1\MonthlyTourPlanController;
use App\Http\Controllers\Api\V1\MyApprovalController;
use App\Http\Controllers\Api\V1\OrderCatalogController;
use App\Http\Controllers\Api\V1\OverdueFollowUpController;
use App\Http\Controllers\Api\V1\PatchController;
use App\Http\Controllers\Api\V1\PinCodeController;
use App\Http\Controllers\Api\V1\PositionRegisterController;
use App\Http\Controllers\Api\V1\PromotionalSchemeController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RcpaController;
use App\Http\Controllers\Api\V1\SalesDocumentController;
use App\Http\Controllers\Api\V1\SalesPerformanceController;
use App\Http\Controllers\Api\V1\SalespersonAiController;
use App\Http\Controllers\Api\V1\SampleIssueController;
use App\Http\Controllers\Api\V1\SampleLedgerController;
use App\Http\Controllers\Api\V1\SampleRequestController;
use App\Http\Controllers\Api\V1\SecondaryOcrController;
use App\Http\Controllers\Api\V1\SecondarySalesController;
use App\Http\Controllers\Api\V1\SecondarySalesCorrectionController;
use App\Http\Controllers\Api\V1\SgipRegisterController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\StockRegisterController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\TerritoryController;
use App\Http\Controllers\Api\V1\TodaysTourController;
use App\Http\Controllers\Api\V1\TravelSegmentController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Controllers\Api\V1\VisitSgipController;
use App\Http\Middleware\AuthorizeFieldModules;
use App\Http\Middleware\AuthorizeFieldReporting;
use App\Http\Middleware\FieldIdempotency;
use App\Services\Field\FieldTerritoryService;
use App\Services\HR\EmployeeRegister;
use App\Services\HR\PositionRegister;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('login/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('login/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', AuthorizeFieldModules::class, AuthorizeFieldReporting::class, FieldIdempotency::class])->group(function (): void {
        Route::post('field-visit-events', [FieldVisitEventController::class, 'store']);
        Route::post('field-expense-events', FieldExpenseEventController::class);
        Route::get('field-sync-recoveries', [FieldSyncRecoveryController::class, 'index']);
        Route::get('field-sync-recoveries/reviewers', [FieldSyncRecoveryController::class, 'reviewers']);
        Route::post('field-sync-recoveries/{operation}/request', [FieldSyncRecoveryController::class, 'requestReview'])->whereUuid('operation');
        Route::patch('field-sync-recoveries/{recovery}/review', [FieldSyncRecoveryController::class, 'review'])->whereNumber('recovery');
        Route::get('field-devices', [FieldDeviceController::class, 'index']);
        Route::put('field-devices/{device}', [FieldDeviceController::class, 'report'])->whereUuid('device');
        Route::post('field-devices/{device}/close', [FieldDeviceController::class, 'close'])->whereUuid('device');
        Route::post('field-devices/{registration}/retire', [FieldDeviceController::class, 'retire'])->whereNumber('registration');
        Route::get('sample-ledger', SampleLedgerController::class);
        Route::get('order-catalog', OrderCatalogController::class);
        Route::get('sales-targets', [SalesPerformanceController::class, 'index']);
        Route::get('sales-targets/territories', [SalesPerformanceController::class, 'territories']);
        Route::post('sales-targets', [SalesPerformanceController::class, 'store']);
        Route::patch('sales-targets/{target}', [SalesPerformanceController::class, 'update'])
            ->whereNumber('target')->withoutMiddleware(FieldIdempotency::class);
        Route::get('sales-targets/{target}/achievement', [SalesPerformanceController::class, 'achievement'])->whereNumber('target');
        Route::post('territory-sales-assignments', [SalesPerformanceController::class, 'assign']);
        Route::get('territory-sales-assignments', [SalesPerformanceController::class, 'assignments']);
        Route::get('territory-sales-assignments/territories', [SalesPerformanceController::class, 'assignmentTerritories']);
        Route::get('territory-sales-assignments/representatives', [SalesPerformanceController::class, 'assignmentRepresentatives']);
        Route::get('rcpa/options', [RcpaController::class, 'options']);
        Route::get('rcpa-observations', [RcpaController::class, 'index']);
        Route::get('rcpa-observations/due', [RcpaController::class, 'due']);
        Route::get('rcpa-observations/export', [RcpaController::class, 'export']);
        Route::get('rcpa-observations/{observation}', [RcpaController::class, 'show'])->whereNumber('observation');
        Route::post('rcpa-observations', [RcpaController::class, 'store']);
        Route::get('secondary-sales/template', [SecondarySalesController::class, 'template']);
        Route::get('secondary-sales/stockists', [SecondarySalesController::class, 'stockists']);
        Route::get('secondary-sales/ocr-options', [SecondarySalesController::class, 'ocrOptions']);
        Route::get('secondary-sales/reviewers', [SecondarySalesController::class, 'reviewers']);
        Route::get('secondary-sales', [SecondarySalesController::class, 'index']);
        Route::post('secondary-ocr', [SecondaryOcrController::class, 'store']);
        Route::get('secondary-ocr', [SecondaryOcrController::class, 'index']);
        Route::get('secondary-ocr/{source}', [SecondaryOcrController::class, 'show'])->whereNumber('source');
        Route::get('secondary-ocr/{source}/file', [SecondaryOcrController::class, 'file'])->whereNumber('source');
        Route::post('secondary-ocr/{source}/retry', [SecondaryOcrController::class, 'retry'])->whereNumber('source');
        Route::get('secondary-sales/corrections', [SecondarySalesCorrectionController::class, 'index']);
        Route::get('secondary-sales/corrections/{correction}', [SecondarySalesCorrectionController::class, 'show'])->whereNumber('correction');
        Route::get('secondary-sales/corrections/{correction}/evidence/{section}', [SecondarySalesCorrectionController::class, 'evidence'])
            ->whereNumber('correction')->whereIn('section', ['rows', 'balances', 'adjustments']);
        Route::get('secondary-sales/corrections/{correction}/source', [SecondarySalesCorrectionController::class, 'source'])->whereNumber('correction');
        Route::post('secondary-sales/corrections/{correction}/review', [SecondarySalesCorrectionController::class, 'review'])->whereNumber('correction');
        Route::post('secondary-sales/{statement}/corrections', [SecondarySalesCorrectionController::class, 'store'])->whereNumber('statement');
        Route::post('secondary-sales', [SecondarySalesController::class, 'store']);
        Route::get('secondary-sales/{statement}', [SecondarySalesController::class, 'show'])->whereNumber('statement');
        Route::get('secondary-sales/{statement}/evidence/{section}', [SecondarySalesController::class, 'evidence'])
            ->whereNumber('statement')->whereIn('section', ['rows', 'balances', 'adjustments']);
        Route::get('secondary-sales/{statement}/source', [SecondarySalesController::class, 'source'])->whereNumber('statement');
        Route::post('secondary-sales/{statement}/review', [SecondarySalesController::class, 'review'])->whereNumber('statement');
        Route::post('field-dcr-events', FieldDcrEventController::class);
        Route::get('geo-exceptions', [GeoExceptionController::class, 'index']);
        Route::patch('geo-exceptions/{assessment}', [GeoExceptionController::class, 'review'])->whereNumber('assessment');
        Route::get('accounts/reporting-options', [AccountController::class, 'reportingOptions']);
        Route::prefix('e-detailing/manage')->withoutMiddleware([AuthorizeFieldModules::class, FieldIdempotency::class])->group(function (): void {
            Route::get('/', [ManageEDetailingMaterialController::class, 'index']);
            Route::get('products', [ManageEDetailingMaterialController::class, 'products']);
            Route::post('/', [ManageEDetailingMaterialController::class, 'store']);
            Route::get('{material}', [ManageEDetailingMaterialController::class, 'show'])->whereNumber('material');
            Route::patch('{material}', [ManageEDetailingMaterialController::class, 'update'])->whereNumber('material');
            Route::post('{material}/file', [ManageEDetailingMaterialController::class, 'update'])->whereNumber('material');
            Route::get('{material}/file', [EDetailingController::class, 'managementFile'])->whereNumber('material');
        });
        Route::get('e-detailing', [EDetailingController::class, 'index']);
        Route::get('e-detailing/{material}/file', [EDetailingController::class, 'libraryFile'])->whereNumber('material');
        Route::get('visits/{visit}/e-detailing', [EDetailingController::class, 'index'])->whereNumber('visit');
        Route::get('visits/{visit}/e-detailing/{material}/file', [EDetailingController::class, 'file'])->whereNumber(['visit', 'material']);
        Route::post('visits/{visit}/e-detailing', [EDetailingController::class, 'store'])->whereNumber('visit');
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', MeController::class);
        Route::get('dashboard/today', DashboardTodayController::class);
        Route::get('dashboard/workspace', [DashboardTodayController::class, 'workspace']);
        Route::get('dashboard/overview', [DashboardTodayController::class, 'overview']);
        Route::delete('dashboard/setup-reminder', [DashboardTodayController::class, 'dismissSetupReminder']);
        Route::patch('dashboard/preferences', [DashboardTodayController::class, 'updatePreferences']);
        Route::get('mobile-navigation', MobileNavigationController::class);
        Route::prefix('positions')->group(function (): void {
            Route::get('/', [PositionRegisterController::class, 'index']);
            Route::get('form', [PositionRegisterController::class, 'form']);
            Route::get('options/{kind}', [PositionRegisterController::class, 'options'])->whereIn('kind', array_keys(PositionRegister::OPTIONS));
            Route::post('/', [PositionRegisterController::class, 'store']);
            Route::post('records/{action}', [PositionRegisterController::class, 'bulk'])->whereIn('action', ['submit', 'delete']);
            Route::get('{position}', [PositionRegisterController::class, 'show'])->whereNumber('position');
            Route::patch('{position}', [PositionRegisterController::class, 'update'])->whereNumber('position');
            Route::post('{position}/actions/{action}', [PositionRegisterController::class, 'action'])->whereNumber('position')->whereIn('action', ['submit', 'delete']);
        });

        Route::prefix('employees')->group(function (): void {
            Route::get('/', [EmployeeRegisterController::class, 'index']);
            Route::get('form', [EmployeeRegisterController::class, 'form']);
            Route::get('options/{kind}', [EmployeeRegisterController::class, 'options'])->whereIn('kind', array_keys(EmployeeRegister::OPTIONS));
            Route::post('options/{createKind}', [EmployeeRegisterController::class, 'createOption'])->whereIn('createKind', ['grades']);
            Route::post('/', [EmployeeRegisterController::class, 'store']);
            Route::post('records/{action}', [EmployeeRegisterController::class, 'bulk'])->whereIn('action', ['submit', 'delete', 'restore']);
            Route::get('{employee}', [EmployeeRegisterController::class, 'show'])->whereNumber('employee');
            Route::patch('{employee}', [EmployeeRegisterController::class, 'update'])->whereNumber('employee');
            Route::get('{employee}/image', [EmployeeRegisterController::class, 'image'])->whereNumber('employee');
            Route::post('{employee}/actions/{action}', [EmployeeRegisterController::class, 'action'])->whereNumber('employee')->whereIn('action', ['submit', 'delete', 'restore']);
            Route::post('{employee}/sections/{section}/records/{rowAction}', [EmployeeRegisterController::class, 'bulkRows'])->whereNumber('employee')->whereIn('section', array_keys(EmployeeRegister::SECTIONS))->whereIn('rowAction', ['delete']);
            Route::get('{employee}/sections/{section}', [EmployeeRegisterController::class, 'section'])->whereNumber('employee')->whereIn('section', array_keys(EmployeeRegister::SECTIONS));
            Route::post('{employee}/sections/{section}', [EmployeeRegisterController::class, 'changeSection'])->whereNumber('employee')->whereIn('section', array_keys(EmployeeRegister::SECTIONS));
            Route::match(['PATCH', 'DELETE'], '{employee}/sections/{section}/{row}', [EmployeeRegisterController::class, 'changeSection'])->whereNumber('employee')->whereNumber('row')->whereIn('section', array_keys(EmployeeRegister::SECTIONS));
            Route::post('{employee}/hr-changes/{changeType}', [EmployeeRegisterController::class, 'requestChange'])->whereNumber('employee')->whereIn('changeType', ['employee', 'employment']);
        });
        Route::prefix('location-masters')->group(function (): void {
            Route::get('/', [LocationRegisterController::class, 'index']);
            Route::get('options/{kind}', [LocationRegisterController::class, 'options'])->whereIn('kind', ['parents', 'types', 'subtypes', 'contacts', 'territories']);
            Route::post('records/{action}', [LocationRegisterController::class, 'bulk'])->whereIn('action', ['submit', 'delete']);
            Route::post('/', [LocationRegisterController::class, 'store']);
            Route::get('{location}', [LocationRegisterController::class, 'show'])->whereNumber('location');
            Route::patch('{location}', [LocationRegisterController::class, 'update'])->whereNumber('location');
            Route::post('{location}/actions/{action}', [LocationRegisterController::class, 'action'])->whereNumber('location')->whereIn('action', ['submit', 'delete']);
            Route::get('{location}/related/{relation}', [LocationRegisterController::class, 'related'])->whereNumber('location')->whereIn('relation', ['stocks', 'addresses']);
            Route::get('{location}/image', [LocationRegisterController::class, 'image'])->whereNumber('location');
            Route::post('{location}/addresses', [LocationRegisterController::class, 'address'])->whereNumber('location');
            Route::patch('{location}/addresses/{address}', [LocationRegisterController::class, 'address'])->whereNumber('location')->whereNumber('address');
            Route::delete('{location}/addresses/{address}', [LocationRegisterController::class, 'address'])->whereNumber('location')->whereNumber('address');
        });
        Route::get('inventory-movements', [StockLedgerController::class, 'index']);
        Route::get('inventory-movements/options/{type}', [StockLedgerController::class, 'options'])->whereIn('type', ['items', 'locations']);
        Route::get('inventory-stocks', [StockRegisterController::class, 'index']);
        Route::prefix('inventory-adjustments')->group(function (): void {
            Route::get('/', [StockAdjustmentController::class, 'index']);
            Route::get('options/{type}', [StockAdjustmentController::class, 'options'])->whereIn('type', ['items', 'locations']);
            Route::post('/', [StockAdjustmentController::class, 'store']);
            Route::post('records/{action}', [StockAdjustmentController::class, 'bulkDelete'])->whereIn('action', ['delete']);
            Route::get('{adjustment}', [StockAdjustmentController::class, 'show'])->whereNumber('adjustment');
            Route::patch('{adjustment}', [StockAdjustmentController::class, 'update'])->whereNumber('adjustment');
            Route::post('{adjustment}/{action}', [StockAdjustmentController::class, 'action'])->whereNumber('adjustment')->whereIn('action', ['submit', 'post', 'delete']);
        });
        Route::prefix('inventory-transfers')->group(function (): void {
            Route::get('/', [StockTransferController::class, 'index']);
            Route::get('options/{type}', [StockTransferController::class, 'options'])->whereIn('type', ['items', 'locations']);
            Route::post('/', [StockTransferController::class, 'store']);
            Route::get('{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer');
            Route::patch('{transfer}', [StockTransferController::class, 'update'])->whereNumber('transfer');
            Route::post('{transfer}/{action}', [StockTransferController::class, 'action'])->whereNumber('transfer')->whereIn('action', ['submit', 'post', 'delete']);
        });
        Route::prefix('delivery-challans')->group(function (): void {
            Route::get('/', [DeliveryChallanController::class, 'index']);
            Route::get('options/{type}', [DeliveryChallanController::class, 'options'])->whereIn('type', ['customers', 'locations', 'items', 'sales-invoices']);
            Route::get('sales-invoices/{salesInvoice}', [DeliveryChallanController::class, 'salesInvoice'])->whereNumber('salesInvoice');
            Route::post('/', [DeliveryChallanController::class, 'store']);
            Route::post('records/{action}', [DeliveryChallanController::class, 'bulk'])->whereIn('action', ['delete']);
            Route::get('{deliveryChallan}', [DeliveryChallanController::class, 'show'])->whereNumber('deliveryChallan');
            Route::patch('{deliveryChallan}', [DeliveryChallanController::class, 'update'])->whereNumber('deliveryChallan');
            Route::post('{deliveryChallan}/{action}', [DeliveryChallanController::class, 'action'])->whereNumber('deliveryChallan')->whereIn('action', ['submit', 'post', 'delete']);
        });
        Route::prefix('goods-receipt-notes')->group(function (): void {
            Route::get('/', [GoodsReceiptController::class, 'index']);
            Route::get('options/{type}', [GoodsReceiptController::class, 'options'])->whereIn('type', ['suppliers', 'locations', 'items', 'purchase-orders']);
            Route::get('purchase-orders/{purchaseOrder}', [GoodsReceiptController::class, 'purchaseOrder'])->whereNumber('purchaseOrder');
            Route::post('/', [GoodsReceiptController::class, 'store']);
            Route::post('records/{action}', [GoodsReceiptController::class, 'bulk'])->whereIn('action', ['delete']);
            Route::get('{goodsReceipt}', [GoodsReceiptController::class, 'show'])->whereNumber('goodsReceipt');
            Route::patch('{goodsReceipt}', [GoodsReceiptController::class, 'update'])->whereNumber('goodsReceipt');
            Route::post('{goodsReceipt}/{action}', [GoodsReceiptController::class, 'action'])->whereNumber('goodsReceipt')->whereIn('action', ['submit', 'post', 'delete']);
        });
        Route::prefix('purchase-orders')->group(function (): void {
            Route::get('/', [PurchaseOrderController::class, 'index']);
            Route::get('options/{type}', [PurchaseOrderController::class, 'options'])->whereIn('type', ['suppliers', 'locations', 'items', 'payment-terms']);
            Route::post('/', [PurchaseOrderController::class, 'store']);
            Route::post('records/{action}', [PurchaseOrderController::class, 'bulk'])->whereIn('action', ['submit', 'delete']);
            Route::get('{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder');
            Route::patch('{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder');
            Route::post('{purchaseOrder}/{action}', [PurchaseOrderController::class, 'action'])->whereNumber('purchaseOrder')->whereIn('action', ['submit', 'approve', 'create-grn', 'cancel', 'delete']);
        });
        Route::get('inventory-stocks/options/{type}', [StockRegisterController::class, 'options'])->whereIn('type', ['items', 'locations']);
        Route::prefix('item-masters')->group(function (): void {
            Route::get('/', [ItemRegisterController::class, 'index']);
            Route::get('options/{type}', [ItemRegisterController::class, 'options'])->whereIn('type', ['categories', 'brands', 'units', 'packaging', 'taxes', 'variants', 'suppliers', 'leads']);
            Route::post('/', [ItemRegisterController::class, 'store']);
            Route::post('records/{action}', [ItemRegisterController::class, 'bulk'])->whereIn('action', ['delete', 'submit']);
            Route::post('{parent}/variants', [ItemRegisterController::class, 'store'])->whereNumber('parent');
            Route::get('{item}', [ItemRegisterController::class, 'show'])->whereNumber('item');
            Route::patch('{item}', [ItemRegisterController::class, 'update'])->whereNumber('item');
            Route::post('{item}/actions/{action}', [ItemRegisterController::class, 'action'])->whereNumber('item')->whereIn('action', ['delete', 'submit', 'restore', 'force-delete', 'dissociate']);
            Route::get('{item}/related/{relation}', [ItemRegisterController::class, 'related'])->whereNumber('item')->whereIn('relation', ['variants', 'suppliers', 'leads', 'stocks']);
            Route::post('{item}/related/{relation}/{action}', [ItemRegisterController::class, 'relate'])->whereNumber('item')->whereIn('relation', ['variants', 'suppliers', 'leads'])
                ->whereIn('action', ['attach', 'detach', 'update', 'delete', 'restore', 'force-delete', 'dissociate']);
        });

        Route::get('approval-register', [ApprovalRegisterController::class, 'index']);
        Route::get('approval-register/{approval}', [ApprovalRegisterController::class, 'show'])->whereNumber('approval');
        Route::post('approval-register/lifecycle', [ApprovalRegisterController::class, 'lifecycle']);
        Route::get('my-approvals', [MyApprovalController::class, 'index']);
        Route::get('my-approvals/{step}', [MyApprovalController::class, 'show'])->whereNumber('step');
        Route::post('my-approvals/{step}/decision', [MyApprovalController::class, 'decide'])->whereNumber('step');
        Route::get('mobile-quick-links', [MobileQuickLinksController::class, 'show']);
        Route::patch('mobile-quick-links', [MobileQuickLinksController::class, 'update']);
        Route::get('flexible-plans', [FlexiblePlanController::class, 'index']);
        Route::post('flexible-plans', [FlexiblePlanController::class, 'store']);
        Route::get('field-coverage', FieldCoverageController::class);

        Route::get('attendance/today', [AttendanceController::class, 'today']);
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::post('attendance/punch-in', [AttendanceController::class, 'punchIn']);
        Route::post('attendance/punch-out', [AttendanceController::class, 'punchOut']);

        Route::get('leave-types', [LeaveController::class, 'types']);
        Route::get('leave-applications', [LeaveController::class, 'index']);
        Route::post('leave-applications', [LeaveController::class, 'store']);
        Route::post('leave-applications/{application}/cancel', [LeaveController::class, 'cancel'])->whereNumber('application');

        Route::get('todays-tour', TodaysTourController::class);
        Route::post('tour-plan-details/{detail}/visits', [VisitController::class, 'openPlanned'])->whereNumber('detail');
        Route::post('tour-plan-details/{detail}/visits/action', [VisitController::class, 'actPlanned'])->whereNumber('detail');
        Route::get('monthly-tour-plans/options', [MonthlyTourPlanController::class, 'options']);
        Route::get('monthly-tour-plans/options/employees/{employee}', [MonthlyTourPlanController::class, 'ownerOptions'])->whereNumber('employee');
        Route::get('monthly-tour-plans/inbox', [MonthlyTourPlanController::class, 'inbox']);
        Route::get('monthly-tour-plans', [MonthlyTourPlanController::class, 'index']);
        Route::post('monthly-tour-plans', [MonthlyTourPlanController::class, 'store']);
        Route::post('monthly-tour-plans/register-actions', [MonthlyTourPlanController::class, 'registerAction']);
        Route::get('monthly-tour-plans/{plan}', [MonthlyTourPlanController::class, 'show'])->whereNumber('plan');
        Route::get('monthly-tour-plans/{plan}/team-options', [MonthlyTourPlanController::class, 'teamEditOptions'])->whereNumber('plan');
        Route::put('monthly-tour-plans/{plan}', [MonthlyTourPlanController::class, 'update'])->whereNumber('plan');
        Route::post('monthly-tour-plans/{plan}/submit', [MonthlyTourPlanController::class, 'submit'])->whereNumber('plan');
        Route::post('monthly-tour-plans/{plan}/approve', [MonthlyTourPlanController::class, 'approve'])->whereNumber('plan');
        Route::post('monthly-tour-plans/{plan}/reject', [MonthlyTourPlanController::class, 'reject'])->whereNumber('plan');

        Route::get('dcr-expenses', [DcrExpenseController::class, 'register']);
        Route::get('dcr-expenses/options', [DcrExpenseController::class, 'registerOptions'])->name('dcr-expenses.options');
        Route::get('dcr-expenses/{expense}', [DcrExpenseController::class, 'registerDetail'])->whereNumber('expense');
        Route::post('dcr-expenses', [DcrExpenseController::class, 'registerStore']);
        Route::patch('dcr-expenses/{expense}', [DcrExpenseController::class, 'registerUpdate'])->whereNumber('expense')->withoutMiddleware(FieldIdempotency::class);
        Route::post('dcr-expenses/lifecycle', [DcrExpenseController::class, 'registerLifecycle'])->withoutMiddleware(FieldIdempotency::class);
        Route::post('dcr-expenses/recalculate', [DcrExpenseController::class, 'registerRecalculate'])
            ->name('dcr-expenses.recalculate')->withoutMiddleware(FieldIdempotency::class);
        Route::prefix('sgip-distributions')->group(function (): void {
            Route::get('/', [SgipRegisterController::class, 'index']);
            Route::get('options/{type}', [SgipRegisterController::class, 'options'])->whereIn('type', ['doctors', 'territories', 'campaigns', 'issues', 'items']);
            Route::post('/', [SgipRegisterController::class, 'store']);
            Route::post('records/{action}', [SgipRegisterController::class, 'bulk'])->whereIn('action', ['delete', 'restore', 'force-delete']);
            Route::get('{sgipDistribution}', [SgipRegisterController::class, 'show'])->whereNumber('sgipDistribution');
            Route::patch('{sgipDistribution}', [SgipRegisterController::class, 'update'])->whereNumber('sgipDistribution');
            Route::post('{sgipDistribution}/{action}', [SgipRegisterController::class, 'action'])->whereNumber('sgipDistribution')
                ->whereIn('action', ['submit', 'approve', 'return', 'delete', 'restore', 'force-delete']);
        });
        Route::get('dcrs/today', [DcrController::class, 'today']);
        Route::get('dcrs', [DcrController::class, 'index']);
        Route::post('dcrs/register-actions', [DcrController::class, 'registerAction'])
            ->withoutMiddleware(FieldIdempotency::class);
        Route::get('dcrs/{dcr}/register', [DcrController::class, 'registerDetail'])->whereNumber('dcr');
        Route::get('dcrs/{dcr}', [DcrController::class, 'show']);
        Route::post('dcrs/{dcr}/submit', [DcrController::class, 'submit']);

        Route::get('travel-segments', [TravelSegmentController::class, 'index']);
        Route::get('travel-segments/options', [TravelSegmentController::class, 'options'])->name('travel-segments.options');
        Route::get('travel-segments/{segment}', [TravelSegmentController::class, 'show'])->whereNumber('segment');
        Route::post('travel-segments', [TravelSegmentController::class, 'store']);
        Route::patch('travel-segments/{segment}', [TravelSegmentController::class, 'update'])->whereNumber('segment')->withoutMiddleware(FieldIdempotency::class);
        Route::post('travel-segments/lifecycle', [TravelSegmentController::class, 'lifecycle'])->withoutMiddleware(FieldIdempotency::class);

        Route::get('dcrs/{dcr}/expenses', [DcrExpenseController::class, 'index']);
        Route::post('dcrs/{dcr}/expenses', [DcrExpenseController::class, 'store']);
        Route::post('dcrs/{dcr}/expenses/{expense}/receipt', [DcrExpenseController::class, 'storeReceipt'])->whereNumber(['dcr', 'expense']);
        Route::get('dcrs/{dcr}/expenses/{expense}/receipts/{receipt}', [DcrExpenseController::class, 'receipt'])->whereNumber(['dcr', 'expense', 'receipt']);
        Route::patch('dcrs/{dcr}/expenses/{expense}', [DcrExpenseController::class, 'update']);
        Route::delete('dcrs/{dcr}/expenses/{expense}', [DcrExpenseController::class, 'destroy']);
        Route::post('dcrs/{dcr}/expenses/auto-calculate', [DcrExpenseController::class, 'autoCalculate']);

        Route::get('expense-types', ExpenseTypeController::class);
        Route::get('items', ItemController::class);

        Route::get('account-types', AccountTypeController::class);
        Route::get('address-types', AddressTypeController::class);
        Route::get('pin-codes', PinCodeController::class);

        Route::get('accounts', [AccountController::class, 'index']);
        Route::get('accounts/register-options', [AccountController::class, 'registerOptions']);
        Route::get('accounts/owner-options', [AccountController::class, 'ownerOptions']);
        Route::post('accounts/register-actions', [AccountController::class, 'registerAction'])->withoutMiddleware(FieldIdempotency::class);
        Route::post('accounts/{account}/related-actions', [AccountController::class, 'relatedAction'])->whereNumber('account')
            ->name('accounts.related-actions')->withoutMiddleware(FieldIdempotency::class);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::get('accounts/{account}', [AccountController::class, 'show']);
        Route::patch('accounts/{account}', [AccountController::class, 'update']);

        Route::get('accounts/{account}/addresses', [AccountAddressController::class, 'index']);
        Route::post('accounts/{account}/addresses', [AccountAddressController::class, 'store']);
        Route::get('accounts/{account}/addresses/{address}', [AccountAddressController::class, 'show']);
        Route::patch('accounts/{account}/addresses/{address}', [AccountAddressController::class, 'update']);
        Route::delete('accounts/{account}/addresses/{address}', [AccountAddressController::class, 'destroy']);
        Route::post('accounts/{account}/contacts', [AccountContactController::class, 'store'])->withoutMiddleware(FieldIdempotency::class);
        Route::patch('accounts/{account}/contacts/{contact}', [AccountContactController::class, 'update'])->whereNumber('contact')->withoutMiddleware(FieldIdempotency::class);
        Route::put('accounts/{account}/contacts/{contact}', [AccountContactController::class, 'attach'])->whereNumber('contact')->withoutMiddleware(FieldIdempotency::class);
        Route::delete('accounts/{account}/contacts/{contact}', [AccountContactController::class, 'detach'])->whereNumber('contact')->withoutMiddleware(FieldIdempotency::class);

        Route::post('visits/direct', [VisitController::class, 'openDirect']);
        Route::get('visits', [VisitController::class, 'index']);
        Route::get('visits/options', [VisitController::class, 'options']);
        Route::post('visits/register-actions', [VisitController::class, 'registerAction'])
            ->withoutMiddleware(FieldIdempotency::class);
        Route::get('visits/{visit}/register', [VisitController::class, 'registerDetail'])->whereNumber('visit');
        Route::get('visits/{visit}', [VisitController::class, 'show']);
        Route::patch('visits/{visit}', [VisitController::class, 'update']);
        Route::post('visits/{visit}/documents', [VisitController::class, 'createDocument']);
        Route::post('visits/{visit}/check-in', [VisitController::class, 'checkIn']);
        Route::post('visits/{visit}/check-out', [VisitController::class, 'checkOut']);
        Route::post('visits/{visit}/photos', [VisitController::class, 'storePhoto']);

        Route::get('visits/{visit}/sgip', [VisitSgipController::class, 'show']);
        Route::put('visits/{visit}/sgip', [VisitSgipController::class, 'upsert']);
        Route::post('visits/{visit}/sgip/submit', [VisitSgipController::class, 'submit']);

        Route::get('salesperson-ai/roadmap', [SalespersonAiController::class, 'roadmap']);
        Route::get('todays-priorities', [SalespersonAiController::class, 'priorities']);
        Route::get('tour-plan-draft', [SalespersonAiController::class, 'tourPlanDraft']);
        Route::get('day-route', [SalespersonAiController::class, 'dayRoute']);
        Route::get('visits/{visit}/briefing', [SalespersonAiController::class, 'briefing'])->whereNumber('visit');
        Route::post('visits/{visit}/voice-note', [SalespersonAiController::class, 'voiceNote'])->whereNumber('visit');
        Route::post('visits/{visit}/voice-note/apply', [SalespersonAiController::class, 'applyVoiceNote'])->whereNumber('visit');
        Route::get('visits/{visit}/sgip/suggestions', [SalespersonAiController::class, 'sgipSuggestions'])->whereNumber('visit');
        Route::get('follow-ups/{followUp}/script', [SalespersonAiController::class, 'followUpScript'])->whereNumber('followUp');
        Route::get('contacts', [ContactController::class, 'index']);
        Route::get('contacts/options/{kind}', [ContactController::class, 'options'])->whereIn('kind', ['companies', 'departments', 'designations']);
        Route::post('contacts/options/companies', [CompanyController::class, 'storeForContact'])->name('contacts.create-company')->withoutMiddleware(FieldIdempotency::class);
        Route::post('contacts/options/{kind}', [ContactController::class, 'createOption'])->whereIn('kind', ['departments', 'designations'])
            ->name('contacts.create-option')->withoutMiddleware(FieldIdempotency::class);
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->whereNumber('contact');
        Route::post('contacts', [ContactController::class, 'store'])->withoutMiddleware(FieldIdempotency::class);
        Route::patch('contacts/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->withoutMiddleware(FieldIdempotency::class);
        Route::post('contacts/register-actions', [ContactController::class, 'delete'])->name('contacts.delete')->withoutMiddleware(FieldIdempotency::class);
        Route::get('contacts/{contact}/addresses', [ContactController::class, 'addresses'])->whereNumber('contact');
        Route::post('contacts/{contact}/addresses', [ContactController::class, 'saveAddress'])->whereNumber('contact')->withoutMiddleware(FieldIdempotency::class);
        Route::patch('contacts/{contact}/addresses/{address}', [ContactController::class, 'saveAddress'])->whereNumber(['contact', 'address'])->withoutMiddleware(FieldIdempotency::class);
        Route::delete('contacts/{contact}/addresses/{address}', [ContactController::class, 'deleteAddress'])->whereNumber(['contact', 'address'])->withoutMiddleware(FieldIdempotency::class);

        Route::get('companies', [CompanyController::class, 'index']);
        Route::get('companies/options/industries', [CompanyController::class, 'industryOptions']);
        Route::get('companies/{company}', [CompanyController::class, 'show'])->whereNumber('company');
        Route::post('companies', [CompanyController::class, 'store'])->withoutMiddleware(FieldIdempotency::class);
        Route::patch('companies/{company}', [CompanyController::class, 'update'])->whereNumber('company')->withoutMiddleware(FieldIdempotency::class);
        Route::post('companies/register-actions', [CompanyController::class, 'delete'])->withoutMiddleware(FieldIdempotency::class);
        Route::post('companies/{company}/addresses', [CompanyController::class, 'saveAddress'])->whereNumber('company')->withoutMiddleware(FieldIdempotency::class);
        Route::patch('companies/{company}/addresses/{address}', [CompanyController::class, 'saveAddress'])->whereNumber(['company', 'address'])->withoutMiddleware(FieldIdempotency::class);
        Route::delete('companies/{company}/addresses/{address}', [CompanyController::class, 'deleteAddress'])->whereNumber(['company', 'address'])->withoutMiddleware(FieldIdempotency::class);
        Route::post('companies/{company}/address-order', [CompanyController::class, 'reorderAddresses'])->whereNumber('company')
            ->name('companies.address-order')->withoutMiddleware(FieldIdempotency::class);

        Route::get('territories', [TerritoryController::class, 'index']);
        Route::get('territories/options/{kind}', [TerritoryController::class, 'options'])->whereIn('kind', array_keys(FieldTerritoryService::OPTIONS));
        Route::get('territories/{territory}', [TerritoryController::class, 'show'])->whereNumber('territory');
        Route::post('territories', [TerritoryController::class, 'store'])->withoutMiddleware(FieldIdempotency::class);
        Route::patch('territories/{territory}', [TerritoryController::class, 'update'])->whereNumber('territory')->withoutMiddleware(FieldIdempotency::class);
        Route::post('territories/register-actions', [TerritoryController::class, 'delete'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('territories/{territory}/patches', [TerritoryController::class, 'patches'])->whereNumber('territory');
        Route::patch('territories/{territory}/patches/{patch}', [TerritoryController::class, 'updatePatch'])
            ->whereNumber(['territory', 'patch'])->withoutMiddleware(FieldIdempotency::class);

        Route::prefix('field-team-assignments')->withoutMiddleware(FieldIdempotency::class)->group(function (): void {
            Route::get('representatives', [FieldTeamAssignmentController::class, 'representatives']);
            Route::get('representatives/{representative}', [FieldTeamAssignmentController::class, 'representative'])->whereNumber('representative');
            Route::get('representatives/{representative}/hqs', [FieldTeamAssignmentController::class, 'hqs'])->whereNumber('representative');
            Route::get('customers', [FieldTeamAssignmentController::class, 'customers']);
            Route::post('hqs', [FieldTeamAssignmentController::class, 'createHq']);
            Route::post('/', [FieldTeamAssignmentController::class, 'store']);
        });

        Route::get('patches', [PatchController::class, 'index']);
        Route::post('patches', [PatchController::class, 'store'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('patches/options/territories', [PatchController::class, 'territories']);
        Route::get('patches/options/territories/{territory}/pin-codes', [PatchController::class, 'pinCodes'])->whereNumber('territory');
        Route::post('patches/register-actions', [PatchController::class, 'trash'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('patches/{patch}', [PatchController::class, 'show'])->whereNumber('patch');
        Route::patch('patches/{patch}', [PatchController::class, 'update'])->whereNumber('patch')->withoutMiddleware(FieldIdempotency::class);
        Route::get('patches/{patch}/customers', [PatchController::class, 'customers'])->whereNumber('patch');
        Route::get('patches/{patch}/customer-types', [PatchController::class, 'customerTypes'])->whereNumber('patch');
        Route::get('patches/{patch}/customer-options', [PatchController::class, 'eligibleCustomers'])->whereNumber('patch');
        Route::post('patches/{patch}/customer-actions', [PatchController::class, 'changeCustomers'])->whereNumber('patch')->withoutMiddleware(FieldIdempotency::class);
        Route::get('patches/import/template', [PatchController::class, 'workbookTemplate']);
        Route::get('patches/import/latest', [PatchController::class, 'latestWorkbook']);
        Route::post('patches/import/preview', [PatchController::class, 'previewWorkbook'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('patches/import/{token}', [PatchController::class, 'workbookStatus'])->whereUuid('token');
        Route::post('patches/import/{token}', [PatchController::class, 'importWorkbook'])->whereUuid('token')->withoutMiddleware(FieldIdempotency::class);

        Route::get('reports/overdue-follow-ups', [OverdueFollowUpController::class, 'index']);
        Route::get('reports/overdue-follow-ups/employees', [OverdueFollowUpController::class, 'employees']);
        Route::get('reports/overdue-follow-ups/export', [OverdueFollowUpController::class, 'export']);
        Route::get('follow-ups/options', [FollowUpController::class, 'options']);
        Route::get('follow-ups', [FollowUpController::class, 'index']);
        Route::post('follow-ups', [FollowUpController::class, 'store']);
        Route::post('follow-ups/bulk-delete', [FollowUpController::class, 'bulkDelete'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('follow-ups/{followUp}', [FollowUpController::class, 'show'])->whereNumber('followUp');
        Route::patch('follow-ups/{followUp}', [FollowUpController::class, 'update'])->whereNumber('followUp');
        Route::delete('follow-ups/{followUp}', [FollowUpController::class, 'destroy'])->whereNumber('followUp')->withoutMiddleware(FieldIdempotency::class);

        Route::get('leads/options', [LeadController::class, 'options']);
        Route::get('lead-activities', [LeadActivityController::class, 'index']);
        Route::get('lead-activities/users', [LeadActivityController::class, 'users']);
        Route::post('lead-activities', [LeadActivityController::class, 'store']);
        Route::post('lead-activities/bulk-delete', [LeadActivityController::class, 'bulkDelete'])->withoutMiddleware(FieldIdempotency::class);
        Route::patch('lead-activities/{activity}', [LeadActivityController::class, 'update'])->whereNumber('activity')->withoutMiddleware(FieldIdempotency::class);
        Route::delete('lead-activities/{activity}', [LeadActivityController::class, 'destroy'])->whereNumber('activity')->withoutMiddleware(FieldIdempotency::class);
        Route::get('leads', [LeadController::class, 'index']);
        Route::post('leads', [LeadController::class, 'store']);
        Route::post('leads/bulk-delete', [LeadController::class, 'bulkDelete'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead');
        Route::patch('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead');
        Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead')->withoutMiddleware(FieldIdempotency::class);
        Route::get('leads/{lead}/activities', [LeadActivityController::class, 'forLead'])->whereNumber('lead');
        Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->whereNumber('lead');
        Route::post('leads/{lead}/activities/bulk-delete', [LeadActivityController::class, 'bulkDelete'])->whereNumber('lead')->withoutMiddleware(FieldIdempotency::class);
        Route::patch('leads/{lead}/activities/{activity}', [LeadActivityController::class, 'update'])->whereNumber(['lead', 'activity'])->withoutMiddleware(FieldIdempotency::class);
        Route::delete('leads/{lead}/activities/{activity}', [LeadActivityController::class, 'destroy'])->whereNumber(['lead', 'activity'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('leads/{lead}/items', [LeadController::class, 'items'])->whereNumber('lead');
        Route::get('leads/{lead}/notes', [LeadController::class, 'notes'])->whereNumber('lead');
        Route::post('leads/{lead}/notes', [LeadController::class, 'storeNote'])->whereNumber('lead');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead');
        Route::get('leads/{lead}/next-action', [SalespersonAiController::class, 'leadNextAction'])->whereNumber('lead');

        Route::get('deals/options', [DealController::class, 'options']);
        Route::get('deals', [DealController::class, 'index']);
        Route::post('deals', [DealController::class, 'store']);
        Route::post('deals/bulk-delete', [DealController::class, 'bulkDelete'])->withoutMiddleware(FieldIdempotency::class);
        Route::get('deals/{deal}', [DealController::class, 'show'])->whereNumber('deal');
        Route::patch('deals/{deal}', [DealController::class, 'update'])->whereNumber('deal');
        Route::delete('deals/{deal}', [DealController::class, 'destroy'])->whereNumber('deal')->withoutMiddleware(FieldIdempotency::class);
        Route::get('deals/{deal}/next-action', [SalespersonAiController::class, 'dealNextAction'])->whereNumber('deal');

        Route::prefix('promotional-schemes')->withoutMiddleware(FieldIdempotency::class)->group(function (): void {
            Route::get('/', [PromotionalSchemeController::class, 'index']);
            Route::get('options/{type}', [PromotionalSchemeController::class, 'options'])->whereIn('type', ['customer', 'territory', 'item']);
            Route::post('/', [PromotionalSchemeController::class, 'store']);
            Route::post('records/{action}', [PromotionalSchemeController::class, 'records'])->whereIn('action', ['delete', 'restore', 'force-delete']);
            Route::get('{scheme}', [PromotionalSchemeController::class, 'show'])->whereNumber('scheme');
            Route::patch('{scheme}', [PromotionalSchemeController::class, 'update'])->whereNumber('scheme');
        });

        Route::prefix('marketing-campaigns')->withoutMiddleware(FieldIdempotency::class)->group(function (): void {
            Route::get('/', [MarketingCampaignController::class, 'index']);
            Route::get('options/{type}', [MarketingCampaignController::class, 'options'])->whereIn('type', ['items', 'territories', 'schemes']);
            Route::post('/', [MarketingCampaignController::class, 'store']);
            Route::post('records/{action}', [MarketingCampaignController::class, 'records'])->whereIn('action', ['delete', 'restore', 'force-delete']);
            Route::get('{campaign}', [MarketingCampaignController::class, 'show'])->whereNumber('campaign');
            Route::patch('{campaign}', [MarketingCampaignController::class, 'update'])->whereNumber('campaign');
        });

        Route::prefix('sample-requests')->group(function (): void {
            Route::get('/', [SampleRequestController::class, 'index']);
            Route::get('options/{type}', [SampleRequestController::class, 'options'])
                ->whereIn('type', ['employees', 'locations', 'territories', 'campaigns', 'items']);
            Route::post('/', [SampleRequestController::class, 'store']);
            Route::post('records/{action}', [SampleRequestController::class, 'bulk'])
                ->whereIn('action', ['delete', 'restore', 'force-delete']);
            Route::get('{sampleRequest}', [SampleRequestController::class, 'show'])->whereNumber('sampleRequest');
            Route::patch('{sampleRequest}', [SampleRequestController::class, 'update'])->whereNumber('sampleRequest');
            Route::post('{sampleRequest}/{action}', [SampleRequestController::class, 'action'])->whereNumber('sampleRequest')
                ->whereIn('action', ['submit', 'approve', 'cancel', 'delete', 'restore', 'force-delete']);
        });

        Route::prefix('sample-issues')->group(function (): void {
            Route::get('/', [SampleIssueController::class, 'index']);
            Route::get('options/{type}', [SampleIssueController::class, 'options'])
                ->whereIn('type', ['requests', 'locations']);
            Route::get('requests/{sampleRequest}', [SampleIssueController::class, 'requestOptions'])->whereNumber('sampleRequest');
            Route::post('/', [SampleIssueController::class, 'store']);
            Route::post('records/{action}', [SampleIssueController::class, 'bulk'])
                ->whereIn('action', ['delete', 'restore', 'force-delete']);
            Route::get('{sampleIssue}', [SampleIssueController::class, 'show'])->whereNumber('sampleIssue');
            Route::patch('{sampleIssue}', [SampleIssueController::class, 'update'])->whereNumber('sampleIssue');
            Route::post('{sampleIssue}/{action}', [SampleIssueController::class, 'action'])->whereNumber('sampleIssue')
                ->whereIn('action', ['post', 'delete', 'restore', 'force-delete']);
        });

        Route::prefix('customer-prices')->withoutMiddleware(FieldIdempotency::class)->group(function (): void {
            Route::get('/', [CustomerPriceController::class, 'index']);
            Route::get('options/{type}', [CustomerPriceController::class, 'options'])->whereIn('type', ['customers', 'items']);
            Route::post('/', [CustomerPriceController::class, 'store']);
            Route::post('bulk-delete', [CustomerPriceController::class, 'bulkDelete']);
            Route::get('{price}', [CustomerPriceController::class, 'show'])->whereNumber('price');
            Route::patch('{price}', [CustomerPriceController::class, 'update'])->whereNumber('price');
            Route::delete('{price}', [CustomerPriceController::class, 'destroy'])->whereNumber('price');
        });

        foreach (SalesDocumentKind::cases() as $kind) {
            Route::prefix($kind->value)->name($kind->value.'.')->group(function (): void {
                Route::get('options', [SalesDocumentController::class, 'options'])->name('options');
                Route::get('items', [SalesDocumentController::class, 'items'])->name('items');
                Route::get('sales-people', [SalesDocumentController::class, 'salesPeople'])->name('sales-people');
                Route::get('filter-options/{field}', [SalesDocumentController::class, 'filterOptions'])->name('filter-options');
                Route::get('/', [SalesDocumentController::class, 'index'])->name('index');
                Route::post('/', [SalesDocumentController::class, 'store'])->name('store');
                Route::post('actions', [SalesDocumentController::class, 'action'])->withoutMiddleware(FieldIdempotency::class)->name('action');
                Route::get('{documentId}', [SalesDocumentController::class, 'show'])->whereNumber('documentId')->name('show');
                Route::patch('{documentId}', [SalesDocumentController::class, 'update'])->whereNumber('documentId')->withoutMiddleware(FieldIdempotency::class)->name('update');
                Route::delete('{documentId}', [SalesDocumentController::class, 'destroy'])->whereNumber('documentId')->withoutMiddleware(FieldIdempotency::class)->name('destroy');
                Route::get('{documentId}/pdf', [SalesDocumentController::class, 'pdf'])->whereNumber('documentId')->name('pdf');
            });
        }

        Route::get('sales-invoices/{documentId}/delivery-locations', [SalesDocumentController::class, 'deliveryLocations'])
            ->whereNumber('documentId')->name('sales-invoices.delivery-locations');
        Route::post('sales-invoices/{documentId}/delivery-challans', [SalesDocumentController::class, 'storeDeliveryChallan'])
            ->whereNumber('documentId')->name('sales-invoices.delivery-challans');

        Route::post('gps-points', [GpsPointController::class, 'store']);
    });
});

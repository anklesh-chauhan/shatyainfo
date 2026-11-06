<?php

use App\Http\Controllers\Api\V1\AccountAddressController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountTypeController;
use App\Http\Controllers\Api\V1\AddressTypeController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DcrController;
use App\Http\Controllers\Api\V1\DcrExpenseController;
use App\Http\Controllers\Api\V1\EDetailingController;
use App\Http\Controllers\Api\V1\ExpenseTypeController;
use App\Http\Controllers\Api\V1\FieldDcrEventController;
use App\Http\Controllers\Api\V1\FieldDeviceController;
use App\Http\Controllers\Api\V1\FieldExpenseEventController;
use App\Http\Controllers\Api\V1\FieldSyncRecoveryController;
use App\Http\Controllers\Api\V1\FieldVisitEventController;
use App\Http\Controllers\Api\V1\GeoExceptionController;
use App\Http\Controllers\Api\V1\GpsPointController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MonthlyTourPlanController;
use App\Http\Controllers\Api\V1\OrderCatalogController;
use App\Http\Controllers\Api\V1\PinCodeController;
use App\Http\Controllers\Api\V1\RcpaController;
use App\Http\Controllers\Api\V1\SalesPerformanceController;
use App\Http\Controllers\Api\V1\SalespersonAiController;
use App\Http\Controllers\Api\V1\SampleLedgerController;
use App\Http\Controllers\Api\V1\SecondaryOcrController;
use App\Http\Controllers\Api\V1\SecondarySalesController;
use App\Http\Controllers\Api\V1\SecondarySalesCorrectionController;
use App\Http\Controllers\Api\V1\TodaysTourController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Controllers\Api\V1\VisitSgipController;
use App\Http\Middleware\FieldIdempotency;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('login/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('login/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', FieldIdempotency::class])->group(function (): void {
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
        Route::get('sales-targets/{target}/achievement', [SalesPerformanceController::class, 'achievement'])->whereNumber('target');
        Route::post('territory-sales-assignments', [SalesPerformanceController::class, 'assign']);
        Route::get('rcpa/options', [RcpaController::class, 'options']);
        Route::get('rcpa-observations', [RcpaController::class, 'index']);
        Route::post('rcpa-observations', [RcpaController::class, 'store']);
        Route::get('secondary-sales/template', [SecondarySalesController::class, 'template']);
        Route::get('secondary-sales/reviewers', [SecondarySalesController::class, 'reviewers']);
        Route::get('secondary-sales', [SecondarySalesController::class, 'index']);
        Route::post('secondary-ocr', [SecondaryOcrController::class, 'store']);
        Route::get('secondary-ocr', [SecondaryOcrController::class, 'index']);
        Route::get('secondary-ocr/{source}', [SecondaryOcrController::class, 'show'])->whereNumber('source');
        Route::get('secondary-ocr/{source}/file', [SecondaryOcrController::class, 'file'])->whereNumber('source');
        Route::get('secondary-sales/corrections', [SecondarySalesCorrectionController::class, 'index']);
        Route::post('secondary-sales/corrections/{correction}/review', [SecondarySalesCorrectionController::class, 'review'])->whereNumber('correction');
        Route::post('secondary-sales/{statement}/corrections', [SecondarySalesCorrectionController::class, 'store'])->whereNumber('statement');
        Route::post('secondary-sales', [SecondarySalesController::class, 'store']);
        Route::get('secondary-sales/{statement}', [SecondarySalesController::class, 'show'])->whereNumber('statement');
        Route::get('secondary-sales/{statement}/source', [SecondarySalesController::class, 'source'])->whereNumber('statement');
        Route::post('secondary-sales/{statement}/review', [SecondarySalesController::class, 'review'])->whereNumber('statement');
        Route::post('field-dcr-events', FieldDcrEventController::class);
        Route::get('geo-exceptions', [GeoExceptionController::class, 'index']);
        Route::patch('geo-exceptions/{assessment}', [GeoExceptionController::class, 'review'])->whereNumber('assessment');
        Route::get('accounts/reporting-options', [AccountController::class, 'reportingOptions']);
        Route::get('e-detailing', [EDetailingController::class, 'index']);
        Route::get('e-detailing/{material}/file', [EDetailingController::class, 'libraryFile'])->whereNumber('material');
        Route::get('visits/{visit}/e-detailing', [EDetailingController::class, 'index'])->whereNumber('visit');
        Route::get('visits/{visit}/e-detailing/{material}/file', [EDetailingController::class, 'file'])->whereNumber(['visit', 'material']);
        Route::post('visits/{visit}/e-detailing', [EDetailingController::class, 'store'])->whereNumber('visit');
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', MeController::class);

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
        Route::get('monthly-tour-plans/inbox', [MonthlyTourPlanController::class, 'inbox']);
        Route::get('monthly-tour-plans', [MonthlyTourPlanController::class, 'index']);
        Route::post('monthly-tour-plans', [MonthlyTourPlanController::class, 'store']);
        Route::get('monthly-tour-plans/{plan}', [MonthlyTourPlanController::class, 'show'])->whereNumber('plan');
        Route::put('monthly-tour-plans/{plan}', [MonthlyTourPlanController::class, 'update'])->whereNumber('plan');
        Route::post('monthly-tour-plans/{plan}/submit', [MonthlyTourPlanController::class, 'submit'])->whereNumber('plan');
        Route::post('monthly-tour-plans/{plan}/approve', [MonthlyTourPlanController::class, 'approve'])->whereNumber('plan');
        Route::post('monthly-tour-plans/{plan}/reject', [MonthlyTourPlanController::class, 'reject'])->whereNumber('plan');

        Route::get('dcrs/today', [DcrController::class, 'today']);
        Route::get('dcrs/{dcr}', [DcrController::class, 'show']);
        Route::post('dcrs/{dcr}/submit', [DcrController::class, 'submit']);

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
        Route::post('accounts', [AccountController::class, 'store']);
        Route::get('accounts/{account}', [AccountController::class, 'show']);
        Route::patch('accounts/{account}', [AccountController::class, 'update']);

        Route::get('accounts/{account}/addresses', [AccountAddressController::class, 'index']);
        Route::post('accounts/{account}/addresses', [AccountAddressController::class, 'store']);
        Route::get('accounts/{account}/addresses/{address}', [AccountAddressController::class, 'show']);
        Route::patch('accounts/{account}/addresses/{address}', [AccountAddressController::class, 'update']);
        Route::delete('accounts/{account}/addresses/{address}', [AccountAddressController::class, 'destroy']);

        Route::get('visits/options', [VisitController::class, 'options']);
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
        Route::get('leads/{lead}/next-action', [SalespersonAiController::class, 'leadNextAction'])->whereNumber('lead');
        Route::get('deals/{deal}/next-action', [SalespersonAiController::class, 'dealNextAction'])->whereNumber('deal');

        Route::post('gps-points', [GpsPointController::class, 'store']);
    });
});

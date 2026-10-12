<?php

use App\Http\Controllers\MarketingController;
use App\Http\Controllers\RegistrationAccessController;
use App\Http\Controllers\SalesDocumentPdfController;
use App\Services\Attendance\LeaveWorkflowService;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingController::class, 'home'])->name('home');

Route::middleware('landlord')->group(function () {
    Route::get('/register/captcha', [RegistrationAccessController::class, 'captcha'])->middleware('throttle:30,1')->name('signup.captcha');
    Route::get('/register/verify/{token}', [RegistrationAccessController::class, 'verify'])->middleware(['signed', 'throttle:8,1'])->name('signup.verify');
    Route::post('/register/{tenant}/resend', [RegistrationAccessController::class, 'resend'])->middleware(['signed', 'throttle:2,1'])->name('signup.resend');
    Route::get('/register', [MarketingController::class, 'create'])->name('signup.create');
    Route::post('/register', [MarketingController::class, 'store'])
        ->middleware('throttle:8,1')
        ->name('signup.store');
    Route::get('/register/{tenant}/status', [MarketingController::class, 'status'])
        ->middleware('signed')
        ->name('signup.status');
    Route::post('/register/{tenant}/retry', [MarketingController::class, 'retry'])
        ->middleware(['signed', 'throttle:8,1'])
        ->name('signup.retry');
    Route::get('/login', [MarketingController::class, 'loginForm'])->name('company.login');
    Route::post('/login', [MarketingController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('company.login.store');
});

Route::get('/registration/complete/{token}', [RegistrationAccessController::class, 'complete'])->middleware('throttle:8,1')->name('signup.complete');

Route::middleware(['web'])->group(function () {
    Route::get('/dashboard', function () {
        return 'Tenant Dashboard';
    })->name('tenant.dashboard');

    Route::get('/sales-documents/{type}/{id}/preview', [SalesDocumentPdfController::class, 'preview'])
        ->name('sales-documents.preview');

    Route::get('/sales-documents/{type}/{id}/download', [SalesDocumentPdfController::class, 'download'])
        ->name('sales-documents.download');

    Route::get('/leave/email/approve', function () {
        abort_unless(request()->hasValidSignature(), 403);

        app(LeaveWorkflowService::class)
            ->approveFromEmail(request('step'));

        return 'Leave approved successfully.';
    })->name('leave.email.approve');

    Route::get('/leave/email/reject', function () {
        abort_unless(request()->hasValidSignature(), 403);

        app(LeaveWorkflowService::class)
            ->rejectFromEmail(request('step'));

        return 'Leave rejected successfully.';
    })->name('leave.email.reject');

});

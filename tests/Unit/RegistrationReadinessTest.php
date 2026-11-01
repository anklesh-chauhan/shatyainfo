<?php

use App\Http\Controllers\MarketingController;
use App\Http\Controllers\RegistrationAccessController;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

test('registration waits for full workspace setup before offering a tenant redirect', function (): void {
    $tenant = new Tenant;
    $tenant->forceFill([
        'id' => 123,
        'domain' => 'example.it-erp.test',
        'provisioning_status' => 'ready',
        'master_setup_status' => 'running',
        'master_setup_progress' => 50,
    ]);

    $request = Request::create('/register/123/status', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $response = app(MarketingController::class)->status($request, $tenant);

    expect($tenant->onboardingProgress())->toBeLessThan(100)
        ->and(app(RegistrationAccessController::class)->redirectUrl($request, $tenant))->toBeNull()
        ->and($response->getData(true)['status'])->toBe('configuring')
        ->and($response->getData(true)['login_url'])->toBeNull();
});

test('a failed master setup can remain on the registration status page for retry', function (): void {
    $tenant = new Tenant;
    $tenant->forceFill([
        'id' => 124,
        'domain' => 'example.it-erp.test',
        'provisioning_status' => 'ready',
        'master_setup_status' => 'failed',
    ]);

    $request = Request::create('/register/124/status', 'GET');
    $response = app(MarketingController::class)->status($request, $tenant);

    expect($response->name())->toBe('marketing.status');
});

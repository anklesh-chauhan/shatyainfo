<?php

use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('keeps drafts separate when the same administrator switches company contexts', function () {
    $admin = reportingUser('Tenant Context Administrator');
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $first = new Tenant;
    $first->forceFill(['id' => 99991, 'name' => 'First Company', 'master_setup_status' => 'ready']);
    $second = new Tenant;
    $second->forceFill(['id' => 99992, 'name' => 'Second Company', 'master_setup_status' => 'ready']);
    $service = app(CompanyOnboardingService::class);
    $draft = ['step' => 1, 'organization' => ['name' => 'First draft'],
        'workflow' => '', 'workflow_version' => 0, 'approval' => []];
    try {
        $first->makeCurrent();
        $service->saveDraft($draft);
        $second->makeCurrent();
        expect($service->draft())->toBe([]);
        $service->saveDraft([...$draft, 'organization' => ['name' => 'Second draft']]);
        expect($service->draft()['organization']['name'])->toBe('Second draft');
        $first->makeCurrent();
        expect($service->draft()['organization']['name'])->toBe('First draft');
        $service->forgetDraft();
        expect($service->draft())->toBe([]);
        $second->makeCurrent();
        expect($service->draft()['organization']['name'])->toBe('Second draft');
    } finally {
        Tenant::forgetCurrent();
    }
    expect(fn () => $service->draft())->toThrow(HttpException::class);
});

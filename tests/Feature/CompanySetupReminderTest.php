<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Onboarding;
use App\Filament\Widgets\CompanySetupReminder;
use App\Http\Middleware\PromptCompanySetup;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function () {
    Tenant::forgetCurrent();
});

it('shows a continue setup reminder until completion and retains checklist access', function () {
    expect((new Dashboard)->getVisibleWidgets())->toContain(CompanySetupReminder::class);
    Livewire::test(CompanySetupReminder::class)
        ->assertSee('Continue setup')
        ->assertSee(Onboarding::getUrl(panel: 'admin'), false);

    $organization = Organization::create([
        'name' => 'Test Company',
        'metadata' => ['company_setup' => ['organization_saved' => true, 'deferred' => true]],
    ]);
    expect(CompanySetupReminder::canView())->toBeTrue();

    $organization->update(['metadata' => ['company_setup' => ['completed' => true]]]);
    expect((new Dashboard)->getVisibleWidgets())->not->toContain(CompanySetupReminder::class)
        ->and(Onboarding::canAccess())->toBeTrue()
        ->and(Onboarding::getNavigationGroup())->toBe('Global Configuration');
});

it('hides the reminder from users without setup access and outside a tenant', function () {
    $this->actingAs(User::factory()->create());
    expect(CompanySetupReminder::canView())->toBeFalse();
    Tenant::forgetCurrent();
    expect(CompanySetupReminder::canView())->toBeFalse();
});

it('remembers dismissal for only the current user while keeping setup accessible', function () {
    $user = auth()->user();

    Livewire::test(CompanySetupReminder::class)
        ->assertSee("Don't show again", false)
        ->call('dismiss')
        ->assertDontSee('Continue setup');

    $this->actingAs($user->fresh());
    expect($user->fresh()->company_setup_reminder_dismissed_at)->not->toBeNull()
        ->and(CompanySetupReminder::canView())->toBeFalse()
        ->and(Onboarding::canAccess())->toBeTrue();

    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    $this->actingAs($otherAdmin);
    expect(CompanySetupReminder::canView())->toBeTrue();
});

it('rejects dismissal by users without setup access', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(CompanySetupReminder::class)->call('dismiss')->assertForbidden();
    expect($user->fresh()->company_setup_reminder_dismissed_at)->toBeNull();
});

it('snoozes the reminder for the session without permanently dismissing it', function () {
    Livewire::test(CompanySetupReminder::class)
        ->assertSee('Remind me later')
        ->call('remindLater')
        ->assertDontSee('Continue setup');

    expect(CompanySetupReminder::canView())->toBeFalse()
        ->and(auth()->user()->fresh()->company_setup_reminder_dismissed_at)->toBeNull();

    session()->flush();
    expect(CompanySetupReminder::canView())->toBeTrue();
});

it('allows pending setup to reach the dashboard once the account is configured', function () {
    Organization::create(['name' => 'Pending Company', 'metadata' => ['company_setup' => ['pending' => true]]]);
    $request = Request::create('/admin', 'GET');
    $request->setUserResolver(fn () => auth()->user());
    $response = app(PromptCompanySetup::class)->handle($request, fn () => response('Dashboard'));
    expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toBe('Dashboard');

    Tenant::current()->forceFill(['master_setup_status' => 'pending']);
    $response = app(PromptCompanySetup::class)->handle($request, fn () => response('Dashboard'));
    expect($response->getStatusCode())->toBe(302);
});

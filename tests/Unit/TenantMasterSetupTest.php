<?php

use App\Filament\Pages\Onboarding;
use App\Http\Middleware\PromptCompanySetup;
use App\Jobs\ProvisionTenant;
use App\Jobs\SeedTenantMasters;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use App\Support\Tenancy\TenantProvisioner;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'database.default' => 'mysql', 'multitenancy.switch_tenant_tasks' => [], 'cache.default' => 'array', 'session.driver' => 'array']);
    DB::purge('mysql');
    DB::setDefaultConnection('mysql');
    Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('domain');
        $table->string('database');
        $table->string('provisioning_status')->default('pending');
        $table->text('provisioning_owner')->nullable();
        $table->text('provisioning_error')->nullable();
        $table->string('master_setup_status')->default('ready');
        $table->string('master_setup_step')->nullable();
        $table->text('master_setup_error')->nullable();
        $table->timestamps();
    });
    $this->tenant = Tenant::withoutEvents(function (): Tenant {
        $tenant = new Tenant;
        $tenant->forceFill(['name' => 'New Company', 'domain' => 'new.shatyainfo.com', 'database' => 'not_created', 'provisioning_status' => 'pending', 'provisioning_owner' => ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-value']])->save();

        return $tenant;
    });
    Queue::fake();
});

afterEach(function () {
    Tenant::forgetCurrent();
    DB::setDefaultConnection('mysql');
    DB::purge('tenant_setup');
    DB::purge('tenant');
    if (isset($this->setupDatabase)) {
        unlink($this->setupDatabase);
    }
});

test('minimal provisioning enables login before dispatching background masters', function () {
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldReceive('provision')->once()->withArgs(fn (Tenant $tenant, array $owner): bool => $tenant->id === $this->tenant->id && $owner['email'] === 'owner@example.test');
    $provisioner->shouldNotReceive('configureAccount');
    (new ProvisionTenant($this->tenant->id))->handle($provisioner);
    $tenant = $this->tenant->fresh();
    expect($tenant->provisioning_status)->toBe('ready')->and($tenant->master_setup_status)->toBe('pending')->and($tenant->provisioning_owner)->toBeNull();
    Queue::assertPushed(SeedTenantMasters::class, fn ($job): bool => $job->tenantId === $tenant->id);
});

test('unverified companies never provision or seed', function () {
    $this->tenant->forceFill(['provisioning_status' => 'awaiting_verification'])->saveQuietly();
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldNotReceive('provision');
    (new ProvisionTenant($this->tenant->id))->handle($provisioner);
    Queue::assertNothingPushed();
});

test('master setup runs while login stays ready and completes independently', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'pending'])->saveQuietly();
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldReceive('configureAccount')->once()->withArgs(function (Tenant $tenant): bool {
        expect($tenant->provisioning_status)->toBe('ready')->and($tenant->master_setup_status)->toBe('running');

        return true;
    });
    (new SeedTenantMasters($this->tenant->id))->handle($provisioner);
    expect($this->tenant->fresh()->accountIsConfigured())->toBeTrue();
});

test('seeding failure preserves the provisioned tenant and exposes a retryable state', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'pending'])->saveQuietly();
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldReceive('configureAccount')->once()->andThrow(new RuntimeException('Reference database unavailable'));
    expect(fn () => (new SeedTenantMasters($this->tenant->id))->handle($provisioner))->toThrow(RuntimeException::class);
    $tenant = $this->tenant->fresh();
    expect($tenant->provisioning_status)->toBe('ready')->and($tenant->master_setup_status)->toBe('failed')->and($tenant->database)->toBe('not_created');
});

test('duplicate running or completed master jobs do not reseed', function (string $status) {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => $status])->saveQuietly();
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldNotReceive('configureAccount');
    (new SeedTenantMasters($this->tenant->id))->handle($provisioner);
})->with(['running', 'ready']);

test('failed master jobs can complete without rebuilding a database', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'failed'])->saveQuietly();
    $provisioner = Mockery::mock(TenantProvisioner::class);
    $provisioner->shouldNotReceive('provision');
    $provisioner->shouldReceive('configureAccount')->once();
    (new SeedTenantMasters($this->tenant->id))->handle($provisioner);
    expect($this->tenant->fresh()->master_setup_status)->toBe('ready');
});

test('existing tenants remain configured by default', function () {
    expect($this->tenant->accountIsConfigured())->toBeTrue();
});

test('onboarding redirects the owner inside the admin panel during master setup', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'pending'])->saveQuietly();
    $this->tenant->makeCurrent();
    Schema::create('organizations', function (Blueprint $table): void {
        $table->id();
        $table->text('metadata')->nullable();
        $table->softDeletes();
    });
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('hasAnyRole')->andReturnTrue();
    $request = Request::create('/admin', 'GET');
    $request->setUserResolver(fn () => $user);
    $response = (new PromptCompanySetup)->handle($request, fn () => response('Dashboard'));
    expect($response->getTargetUrl())->toContain('/admin/onboarding');
});

function transactionalMasterProvisioner(): TenantProvisioner
{
    return new class(app(TenantDatabaseProvisioner::class)) extends TenantProvisioner
    {
        public bool $failSeed = false;

        public int $seedRuns = 0;

        protected function copyAccessControl(): void {}

        protected function deleteLandlordPermissions(): void {}

        protected function seedReferenceData(): void
        {
            $this->seedRuns++;
            DB::table('sample_masters')->insert(['name' => 'Reference master']);
            if ($this->failSeed) {
                throw new RuntimeException('Seed interrupted');
            }
        }
    };
}

function createMasterSetupDatabase(): void
{
    $test = test();
    $test->setupDatabase = tempnam(sys_get_temp_dir(), 'tenant_setup_');
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $test->setupDatabase, 'prefix' => '']]);
    DB::purge('tenant');
    Schema::connection('tenant')->create('organizations', function (Blueprint $table): void {
        $table->id();
        $table->text('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::connection('tenant')->create('sample_masters', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    DB::connection('tenant')->table('organizations')->insert(['metadata' => '{}']);
    $test->tenant->forceFill(['database' => $test->setupDatabase])->saveQuietly();
}

test('failed master seeding rolls back records and restores connection context', function () {
    createMasterSetupDatabase();
    $provisioner = transactionalMasterProvisioner();
    $provisioner->failSeed = true;
    expect(fn () => $provisioner->configureAccount($this->tenant))->toThrow(RuntimeException::class, 'Seed interrupted');
    expect(DB::connection('tenant')->table('sample_masters')->count())->toBe(0)
        ->and(DB::getDefaultConnection())->toBe('mysql')->and(Tenant::checkCurrent())->toBeFalse()
        ->and(config('multitenancy.geographic_reference_connection_name'))->toBeNull();
    $provisioner->failSeed = false;
    $provisioner->configureAccount($this->tenant);
    expect(DB::connection('tenant')->table('sample_masters')->count())->toBe(1);
});

test('master completion marker prevents reseeding after a worker interruption', function () {
    createMasterSetupDatabase();
    $provisioner = transactionalMasterProvisioner();
    $provisioner->configureAccount($this->tenant);
    $provisioner->configureAccount($this->tenant);
    expect($provisioner->seedRuns)->toBe(1)->and(DB::connection('tenant')->table('sample_masters')->count())->toBe(1);
});

test('onboarding shows configuration progress then reveals the organization form', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'pending', 'master_setup_step' => 'Waiting to configure your account'])->saveQuietly();
    $this->tenant->makeCurrent();
    $user = Mockery::mock(User::class)->makePartial();
    $user->forceFill(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test']);
    $user->shouldReceive('hasAnyRole')->andReturnTrue();
    $user->shouldReceive('hasRole')->andReturnTrue();
    $user->shouldReceive('canAccessPanel')->andReturnTrue();
    $this->actingAs($user, 'web');
    $service = Mockery::mock(CompanyOnboardingService::class);
    $service->shouldReceive('authorize')->andReturnNull();
    $organization = new Organization;
    $organization->forceFill(['name' => 'New Company', 'email' => 'owner@example.test', 'phone' => '1234567890']);
    $service->shouldReceive('organization')->andReturn($organization);
    app()->instance(CompanyOnboardingService::class, $service);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $page = Livewire::test(Onboarding::class)
        ->assertSee('We are configuring your account')->assertDontSee('Finish onboarding');
    $this->tenant->forceFill(['master_setup_status' => 'ready'])->saveQuietly();
    $page->call('refreshSetup')->assertSee('Finish onboarding')->assertDontSee('We are configuring your account')
        ->assertSet('organization.name', 'New Company');
});

test('onboarding retry queues only one new master job', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'failed'])->saveQuietly();
    $this->tenant->makeCurrent();
    $service = Mockery::mock(CompanyOnboardingService::class);
    $service->shouldReceive('authorize')->twice();
    app()->instance(CompanyOnboardingService::class, $service);
    $page = new Onboarding;
    $page->retrySetup();
    $page->retrySetup();
    Queue::assertPushed(SeedTenantMasters::class, 1);
    expect($this->tenant->fresh()->master_setup_status)->toBe('pending');
});

test('initial provisioning creates an authorized owner without running master seeders', function () {
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('tenant');
    Schema::connection('tenant')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
    });
    Schema::connection('tenant')->create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->unique(['role_id', 'model_type', 'model_id']);
    });
    Schema::connection('tenant')->create('organizations', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('status')->nullable();
        $table->string('timezone')->nullable();
        $table->string('language')->nullable();
        $table->text('metadata')->nullable();
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $databases = Mockery::mock(TenantDatabaseProvisioner::class);
    $databases->shouldReceive('createDatabase')->once();
    $databases->shouldReceive('createDedicatedUser')->once()->andReturnUsing(fn () => DB::setDefaultConnection('tenant'));
    Artisan::shouldReceive('call')->once()->with('migrate', Mockery::type('array'))->andReturn(0);
    $provisioner = new class($databases) extends TenantProvisioner
    {
        protected function seedReferenceData(): void
        {
            throw new RuntimeException('Masters must run in background');
        }

        protected function copyAccessControl(): void
        {
            throw new RuntimeException('Permission copying must run in background');
        }
    };
    $owner = ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-value', 'email_verified_at' => now()->toDateTimeString()];
    $provisioner->provision($this->tenant, $owner);
    $this->tenant->makeCurrent();
    $user = User::query()->firstOrFail();
    expect($user->email)->toBe('owner@example.test')->and($user->email_verified_at)->not->toBeNull()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(DB::connection('tenant')->table('organizations')->count())->toBe(1);
    Queue::assertNotPushed(SeedTenantMasters::class);
});

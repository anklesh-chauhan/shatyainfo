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
use App\Support\Tenancy\TenantSchemaMigrator;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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
        $table->unsignedTinyInteger('provisioning_progress')->nullable();
        $table->string('provisioning_step')->nullable();
        $table->unsignedTinyInteger('master_setup_progress')->nullable();
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
    Queue::assertPushed(SeedTenantMasters::class, fn (SeedTenantMasters $job): bool => $job->tenantId === $tenant->id && $job->queue === null && $job->connection === 'database');
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

        protected function migrateSchema(array $paths, callable $progress): void {}

        protected function assertMasterTablesExist(): void {}

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
    Schema::create('organizations', function (Blueprint $table): void {
        $table->id();
        $table->string('logo')->nullable();
        $table->softDeletes();
    });
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
    $organization->forceFill(['id' => 1, 'name' => 'New Company', 'email' => 'owner@example.test', 'phone' => '1234567890']);
    $service->shouldReceive('organization')->andReturn($organization);
    $companySaved = false;
    $employeesAdded = false;
    $service->shouldReceive('checklist')->andReturnUsing(function () use (&$companySaved, &$employeesAdded): array {
        return ['company' => $companySaved, 'structure' => false, 'employees' => $employeesAdded, 'review' => false, 'unit_count' => 0, 'employee_count' => $employeesAdded ? 4 : 0];
    });
    $service->shouldReceive('saveOrganization')->once()->withArgs(fn (array $data): bool => $data['name'] === 'New Company' && $data['email'] === 'owner@example.test' && $data['phone'] === '1234567890' && $data['legal_name'] === 'New Company Limited')->andReturnUsing(function () use (&$companySaved, $organization): Organization {
        $companySaved = true;
        $organization->metadata = ['company_setup' => ['organization_saved' => true]];

        return $organization;
    });
    app()->instance(CompanyOnboardingService::class, $service);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $page = Livewire::test(Onboarding::class)
        ->assertSee('We are configuring your account')->assertDontSee('Finish onboarding');
    $this->tenant->forceFill(['master_setup_status' => 'ready'])->saveQuietly();
    $page->call('refreshSetup')->assertSee('Get started')->assertDontSee('Save and continue')->assertDontSee('We are configuring your account')
        ->assertSet('organization.name', 'New Company');
    $page->call('next')->assertSet('step', 1)->assertSee('Save and continue')->assertDontSee('Import employees from Excel');
    $page->assertSee('GST number')->assertSee('Website')->set('organization.legal_name', 'New Company Limited');
    $page->call('saveOrganization')->assertHasNoErrors()->assertSee('Update full organization details and logo')->assertNoRedirect()->assertNotified('Company information saved')
        ->assertSet('step', 2)->assertSee('Import employees from Excel')->assertDontSee('Review positions and reporting')->assertSee('Continue later');
    $page->call('next')->assertSet('step', 2)->assertHasErrors(['checklist']);
    $employeesAdded = true;
    $page->call('next')->assertSet('step', 3)->assertHasNoErrors()->assertSee('Open my workspace')->assertSee('Review positions and reporting');
    $page->call('back')->assertSet('step', 2)->assertSee('Import employees from Excel');
    Livewire::test(Onboarding::class)->assertSet('step', 2)->assertSee('4 employees added');
});

test('onboarding queues master setup once while the account is still waiting', function () {
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
    app()->instance(CompanyOnboardingService::class, $service);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(Onboarding::class)->call('refreshSetup')->call('refreshSetup');
    Queue::assertPushed(SeedTenantMasters::class, 1);
    Queue::assertPushed(SeedTenantMasters::class, fn (SeedTenantMasters $job): bool => $job->tenantId === $this->tenant->id && $job->queue === null);
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

test('initial provisioning uses five real migrations and renders setup without business tables', function () {
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('tenant');
    $databases = Mockery::mock(TenantDatabaseProvisioner::class);
    $databases->shouldReceive('createDatabase')->once();
    $databases->shouldReceive('createDedicatedUser')->once()->andReturnUsing(fn () => DB::setDefaultConnection('tenant'));
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
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'pending', 'master_setup_progress' => 0])->saveQuietly();
    $this->tenant->makeCurrent();
    $user = User::query()->firstOrFail();
    expect($user->email)->toBe('owner@example.test')->and($user->email_verified_at)->not->toBeNull()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(DB::connection('tenant')->table('migrations')->count())->toBe(5)
        ->and(Schema::connection('tenant')->hasTable('employees'))->toBeFalse()
        ->and(Schema::connection('tenant')->hasTable('visits'))->toBeFalse();
    $this->actingAs($user, 'web');
    $this->get('http://new.shatyainfo.com/admin')->assertRedirect(route('filament.admin.pages.onboarding'));
    $this->get('http://new.shatyainfo.com/admin/onboarding')->assertOk()->assertSee('We are configuring your account')->assertSee('0% complete');
});

test('schema progress counts completed migrations and skips them on retry', function () {
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('tenant');
    $updates = [];
    $schema = app(TenantSchemaMigrator::class);
    $schema->run(TenantSchemaMigrator::LOGIN_MIGRATIONS, function (int $completed, int $total) use (&$updates): void {
        $updates[] = [$completed, $total];
    });
    expect($updates)->toBe([[0, 5], [1, 5], [2, 5], [3, 5], [4, 5], [5, 5]])
        ->and(DB::getDefaultConnection())->toBe('mysql');
    $updates = [];
    $schema->run(TenantSchemaMigrator::LOGIN_MIGRATIONS, function (int $completed, int $total) use (&$updates): void {
        $updates[] = [$completed, $total];
    });
    expect($updates)->toBe([[0, 0]])->and(DB::connection('tenant')->table('migrations')->count())->toBe(5);
});

test('background schema failure stops before copying permissions or seeding masters', function () {
    createMasterSetupDatabase();
    $schema = Mockery::mock(TenantSchemaMigrator::class);
    $schema->shouldReceive('run')->once()->with(['database/migrations/tenant'], Mockery::type('callable'))->andThrow(new RuntimeException('DDL failed'));
    app()->instance(TenantSchemaMigrator::class, $schema);
    $provisioner = new class(app(TenantDatabaseProvisioner::class)) extends TenantProvisioner
    {
        protected function copyAccessControl(): void
        {
            throw new LogicException('Permissions ran before tables');
        }

        protected function seedReferenceData(): void
        {
            throw new LogicException('Seeding ran before tables');
        }
    };
    expect(fn () => $provisioner->configureAccount($this->tenant))->toThrow(RuntimeException::class, 'DDL failed');
    expect(DB::connection('tenant')->table('sample_masters')->count())->toBe(0)
        ->and(DB::getDefaultConnection())->toBe('mysql')->and(Tenant::checkCurrent())->toBeFalse();
});

test('only completed setup reports one hundred percent', function () {
    $this->tenant->forceFill(['provisioning_status' => 'provisioning', 'provisioning_progress' => 40, 'master_setup_status' => 'running', 'master_setup_progress' => 63]);
    expect($this->tenant->provisioningProgress())->toBe(40)->and($this->tenant->masterSetupProgress())->toBe(63);
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'ready']);
    expect($this->tenant->provisioningProgress())->toBe(100)->and($this->tenant->masterSetupProgress())->toBe(100);
});

test('the automatic background job migrates missing geography tables before seeding', function () {
    $this->setupDatabase = tempnam(sys_get_temp_dir(), 'tenant_schema_');
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->setupDatabase, 'prefix' => '']]);
    DB::purge('tenant');
    $this->tenant->forceFill(['database' => $this->setupDatabase, 'provisioning_status' => 'ready', 'master_setup_status' => 'pending'])->saveQuietly();
    $schema = app(TenantSchemaMigrator::class);
    $schema->run(TenantSchemaMigrator::LOGIN_MIGRATIONS, fn () => null);
    expect(Schema::connection('tenant')->hasTable('countries'))->toBeFalse();
    DB::connection('tenant')->table('organizations')->insert(['name' => 'New Company', 'metadata' => '{}']);
    $relativeDirectory = 'storage/framework/testing/tenant-migrations-'.bin2hex(random_bytes(6));
    $directory = base_path($relativeDirectory);
    mkdir($directory, 0777, true);
    $files = [
        '2025_03_12_060244_create_countries_table.php',
        '2025_03_12_065058_create_states_table.php',
        '2025_03_12_065114_create_cities_table.php',
        '2025_03_12_065138_create_city_pin_codes_table.php',
    ];
    try {
        foreach ($files as $file) {
            copy(database_path('migrations/tenant/'.$file), $directory.'/'.$file);
        }
        $provisioner = new class(app(TenantDatabaseProvisioner::class), $relativeDirectory) extends TenantProvisioner
        {
            public function __construct(TenantDatabaseProvisioner $databases, private string $directory)
            {
                parent::__construct($databases);
            }

            protected function migrateSchema(array $paths, callable $progress): void
            {
                parent::migrateSchema([$this->directory], $progress);
            }

            protected function copyAccessControl(): void {}

            protected function deleteLandlordPermissions(): void {}

            protected function seedReferenceData(): void
            {
                foreach (['countries', 'states', 'cities', 'city_pin_codes'] as $table) {
                    expect(Schema::hasTable($table))->toBeTrue();
                }
                DB::table('countries')->insert(['name' => 'India']);
            }
        };
        (new SeedTenantMasters($this->tenant->id))->handle($provisioner);
        expect($this->tenant->fresh()->master_setup_status)->toBe('ready')
            ->and($this->tenant->fresh()->masterSetupProgress())->toBe(100)
            ->and(DB::connection('tenant')->table('countries')->value('name'))->toBe('India')
            ->and(DB::connection('tenant')->table('migrations')->count())->toBe(9);
    } finally {
        foreach ($files as $file) {
            if (is_file($directory.'/'.$file)) {
                unlink($directory.'/'.$file);
            }
        }
        rmdir($directory);
    }
});

test('missing deployed migration files stop automatic setup', function () {
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('tenant');
    expect(fn () => app(TenantSchemaMigrator::class)->run(['database/migrations/not-deployed'], fn () => null))
        ->toThrow(RuntimeException::class, 'No tenant migration files were found');
});

test('seeding is blocked when migration records exist but geography tables are missing', function () {
    createMasterSetupDatabase();
    $provisioner = new class(app(TenantDatabaseProvisioner::class)) extends TenantProvisioner
    {
        protected function migrateSchema(array $paths, callable $progress): void {}

        protected function copyAccessControl(): void
        {
            throw new LogicException('Should not copy before schema validation');
        }

        protected function seedReferenceData(): void
        {
            throw new LogicException('Should not seed an incomplete schema');
        }
    };
    expect(fn () => $provisioner->configureAccount($this->tenant))
        ->toThrow(RuntimeException::class, 'countries is missing');
});

test('onboarding allows setup pages after company information is saved', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready', 'master_setup_status' => 'ready'])->saveQuietly();
    $this->tenant->makeCurrent();
    Schema::create('organizations', function (Blueprint $table): void {
        $table->id();
        $table->text('metadata')->nullable();
        $table->softDeletes();
    });
    DB::table('organizations')->insert(['metadata' => json_encode(['company_setup' => ['pending' => true, 'organization_saved' => true]])]);
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('hasAnyRole')->andReturnTrue();
    $request = Request::create('/admin/data-import?module=employees', 'GET');
    $request->setUserResolver(fn () => $user);
    $response = (new PromptCompanySetup)->handle($request, fn () => response('Employee import'));
    expect($response->getContent())->toBe('Employee import');
});

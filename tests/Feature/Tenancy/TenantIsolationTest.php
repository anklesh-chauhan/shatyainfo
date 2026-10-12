<?php

use App\Models\Tenant;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('rejects unsafe database identifiers', function () {
    $provisioner = app(TenantDatabaseProvisioner::class);

    expect(fn () => $provisioner->quoteIdentifier('tenant_1; DROP DATABASE mysql'))
        ->toThrow(InvalidArgumentException::class);

    expect($provisioner->quoteIdentifier('tenant_abc123'))->toBe('`tenant_abc123`');
});

it('uses the landlord database when no company is selected', function () {
    Route::get('/__tenancy-probe', function () {
        return [
            'default' => DB::getDefaultConnection(),
            'domain' => Tenant::current()?->domain,
        ];
    });

    $this->getJson('/__tenancy-probe')
        ->assertOk()
        ->assertJsonPath('domain', null)
        ->assertJsonPath('default', 'mysql');
});

it('switches to the tenant database for a company domain', function () {
    $database = (string) config('database.connections.mysql.database');

    Tenant::withoutEvents(function () use ($database) {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Acme',
            'domain' => 'acme.test',
            'database' => $database,
            'provisioning_status' => 'ready',
        ])->save();
    });

    Route::get('/__tenancy-probe', function () {
        return [
            'default' => DB::getDefaultConnection(),
            'database' => config('database.connections.tenant.database'),
            'domain' => Tenant::current()?->domain,
        ];
    });

    $this->getJson('/__tenancy-probe', ['X-Tenant-Domain' => 'acme.test'])
        ->assertOk()
        ->assertJsonPath('domain', 'acme.test')
        ->assertJsonPath('database', $database)
        ->assertJsonPath('default', 'tenant');

    expect(DB::getDefaultConnection())->toBe('mysql');
    expect(config('database.connections.tenant.database'))->toBeNull();
});

it('does not let a header override the company host', function () {
    $database = (string) config('database.connections.mysql.database');

    Tenant::withoutEvents(function () use ($database) {
        $acme = new Tenant;
        $acme->forceFill([
            'name' => 'Acme',
            'domain' => 'acme.test',
            'database' => $database,
            'provisioning_status' => 'ready',
        ])->save();

        $beta = new Tenant;
        $beta->forceFill([
            'name' => 'Beta',
            'domain' => 'beta.test',
            'database' => 'tenant_beta_test',
            'provisioning_status' => 'ready',
        ])->save();
    });

    Route::get('/__tenancy-probe', fn () => [
        'domain' => Tenant::current()?->domain,
    ]);

    $this->getJson('http://acme.test/__tenancy-probe', [
        'X-Tenant-Domain' => 'beta.test',
    ])->assertOk()->assertJsonPath('domain', 'acme.test');
});

it('blocks landlord routes on a company host', function () {
    $database = (string) config('database.connections.mysql.database');

    Tenant::withoutEvents(function () use ($database) {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Acme',
            'domain' => 'acme.test',
            'database' => $database,
            'provisioning_status' => 'ready',
        ])->save();
    });

    $this->get('http://acme.test/landlord')->assertForbidden();
});

it('returns not found for an unknown company domain', function () {
    $this->getJson('/api/v1/login', ['X-Tenant-Domain' => 'missing.test'])
        ->assertNotFound();
});

it('blocks companies that are not ready before switching databases', function (string $status, bool $useHeader) {
    Tenant::withoutEvents(function () use ($status) {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Unavailable',
            'domain' => 'unavailable.test',
            'database' => 'tenant_database_that_does_not_exist',
            'provisioning_status' => $status,
        ])->save();
    });

    Route::get('/__tenancy-probe', fn () => ['reached' => true]);

    $this->getJson(
        $useHeader ? '/__tenancy-probe' : 'http://unavailable.test/__tenancy-probe',
        $useHeader ? ['X-Tenant-Domain' => 'unavailable.test'] : [],
    )->assertStatus(503)
        ->assertJsonPath('message', 'Company setup is not complete. Please try again later.');

    expect(Tenant::current())->toBeNull()
        ->and(DB::getDefaultConnection())->toBe('mysql')
        ->and(config('database.connections.tenant.database'))->toBeNull();
})->with(['pending', 'provisioning', 'failed'])->with([true, false]);

it('resolves the database queue on the landlord connection while a tenant is active', function () {
    $configuration = config('queue.connections.database');
    $resolver = Mockery::mock(ConnectionResolverInterface::class);
    $connection = Mockery::mock(Connection::class);
    $resolver->shouldReceive('connection')->once()->with('mysql')->andReturn($connection);

    DB::setDefaultConnection('tenant');

    try {
        $queue = (new DatabaseConnector($resolver))->connect($configuration);

        expect($queue)->toBeInstanceOf(DatabaseQueue::class);
    } finally {
        DB::setDefaultConnection('mysql');
    }
});

it('preserves an existing database when provisioning cannot create it', function () {
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 123, 'database' => 'existing_database', 'domain' => 'existing.test']);
    $databases = Mockery::mock(TenantDatabaseProvisioner::class);
    $databases->shouldReceive('createDatabase')->once()->with($tenant)
        ->andThrow(new RuntimeException('Database already exists'));
    $databases->shouldNotReceive('createDedicatedUser');
    $databases->shouldNotReceive('drop');

    expect(fn () => (new TenantProvisioner($databases))->provision($tenant))
        ->toThrow(RuntimeException::class, 'Database already exists');
});

it('cleans up a database created by a failed provisioning attempt', function () {
    $tenant = Mockery::mock(Tenant::class)->makePartial();
    $tenant->forceFill(['id' => 123, 'database' => 'new_database', 'domain' => 'new.test']);
    $tenant->shouldReceive('saveQuietly')->once()->andReturn(true);
    $databases = Mockery::mock(TenantDatabaseProvisioner::class);
    $databases->shouldReceive('createDatabase')->once()->with($tenant);
    $databases->shouldReceive('createDedicatedUser')->once()->with($tenant)
        ->andThrow(new RuntimeException('User creation failed'));
    $databases->shouldReceive('drop')->once()->with($tenant);

    expect(fn () => (new TenantProvisioner($databases))->provision($tenant))
        ->toThrow(RuntimeException::class, 'User creation failed');
});

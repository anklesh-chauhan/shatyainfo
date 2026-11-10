<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataImport\EmployeeOnboardingImporter;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['multitenancy.switch_tenant_tasks' => []]);
});

afterEach(function () {
    Tenant::forgetCurrent();
});

function activateRoleTestTenant(): void
{
    app('db')->connection('tenant')->setPdo(app('db')->connection('mysql')->getPdo())
        ->setReadPdo(app('db')->connection('mysql')->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Role guard test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
}

it('resolves permission names using the context in the web authenticated admin panel', function (bool $tenantContext) {
    if ($tenantContext) {
        activateRoleTestTenant();
    }
    $guard = $tenantContext ? 'tenant' : 'web';
    $otherGuard = $tenantContext ? 'web' : 'tenant';
    $permission = Permission::findOrCreate('ViewAny:Visit', $guard);
    Permission::findOrCreate('ViewAny:Visit', $otherGuard);
    $role = Role::findOrCreate('field_representative', $guard);
    $role->givePermissionTo($permission);
    Role::findOrCreate('field_representative', $otherGuard);
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $user->assignRole('field_representative');

    expect($user->roles->modelKeys())->toBe([$role->id])
        ->and($user->hasPermissionTo('ViewAny:Visit'))->toBeTrue()
        ->and($user->can('ViewAny:Visit'))->toBeTrue()
        ->and($user->hasPermissionTo('ViewAny:Visit', $otherGuard))->toBeFalse();
})->with([false, true]);

it('shows and saves roles for the current context in user access', function (bool $tenantContext) {
    if ($tenantContext) {
        activateRoleTestTenant();
    }
    $guard = $tenantContext ? 'tenant' : 'web';
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', $guard));
    $actor->givePermissionTo(Permission::findOrCreate('Update:User', $guard));
    $actor->givePermissionTo(Permission::findOrCreate('ViewAny:User', $guard));
    $this->actingAs($actor, 'web');
    $role = Role::findOrCreate('field_representative', $guard);
    $otherRole = Role::findOrCreate('field_representative', $tenantContext ? 'web' : 'tenant');
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSuccessful()
        ->assertFormFieldExists('roles', function (Select $field) use ($role, $otherRole): bool {
            $options = $field->getOptions();

            return isset($options[$role->id]) && ! isset($options[$otherRole->id]);
        })
        ->fillForm(['roles' => [$role->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->roles->modelKeys())->toBe([$role->id]);
})->with([false, true]);

it('assigns tenant roles and working permissions when employee logins are uploaded and reimported', function () {
    activateRoleTestTenant();
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    $this->actingAs($actor, 'web');
    $row = [
        'employee_id' => 'ROLE-001',
        'first_name' => 'Asha',
        'last_name' => 'Shah',
        'mobile_number' => '9000000001',
        'email' => 'role-import@example.test',
        'temporary_password' => 'Temporary-Secret-42',
        'job_role' => 'Sales Representative',
        'position' => 'Sales Representative West',
        'position_code' => 'SR-WEST',
    ];
    $importer = app(EmployeeOnboardingImporter::class);
    $importer->importRow($row);
    $employee = Employee::query()->where('employee_id', 'ROLE-001')->firstOrFail();
    $user = User::findOrFail($employee->login_id);
    $role = Role::findByName('sales_representative', 'tenant');

    expect($user->roles->modelKeys())->toBe([$role->id])
        ->and($user->can('Create:Visit'))->toBeTrue();

    $user->removeRole($role);
    unset($row['temporary_password']);
    $importer->importRow($row);

    expect($user->fresh()->roles->modelKeys())->toBe([$role->id])
        ->and($user->fresh()->can('Create:Visit'))->toBeTrue();
});

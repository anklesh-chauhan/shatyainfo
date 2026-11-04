<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    foreach (['ViewAny:User', 'View:User', 'Create:User', 'Update:User'] as $permission) {
        $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $this->actingAs($admin);
});

it('creates a user with an assigned role', function () {
    $role = Role::findOrCreate('sales_user', 'web');

    livewire(CreateUser::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Field Rep',
            'email' => 'field-rep@example.test',
            'password' => 'password',
            'roles' => [$role->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $user = User::query()->where('email', 'field-rep@example.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasRole('sales_user'))->toBeTrue();
});

it('updates a user role without requiring a new password', function () {
    $user = User::factory()->create([
        'name' => 'Existing User',
        'email' => 'existing@example.test',
    ]);
    $salesRole = Role::findOrCreate('sales_user', 'web');
    $hrRole = Role::findOrCreate('hr_user', 'web');
    $user->assignRole($salesRole);

    livewire(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => null,
            'roles' => [$hrRole->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->hasRole('hr_user'))->toBeTrue()
        ->and($user->fresh()->hasRole('sales_user'))->toBeFalse();
});

it('shows assigned roles on the users table', function () {
    $user = User::factory()->create([
        'name' => 'Visible Roles User',
        'email' => 'visible-roles@example.test',
    ]);
    $user->assignRole(Role::findOrCreate('sales_admin', 'web'));

    livewire(ListUsers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$user])
        ->assertSee('sales_admin');
});

<?php

use App\Models\AccountMaster;
use App\Models\TypeMaster;
use Database\Seeders\ConfigDrivenShieldPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lists visible account masters for sales users', function () {
    $user = fieldSalesUser('AccountList');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Listable');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/accounts')
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $doctor->id, 'name' => 'Dr Listable']);
});

it('searches accounts by name', function () {
    $user = fieldSalesUser('AccountSearch');
    $this->actingAs($user);

    reportingDoctor($user, 'Dr Alpha');
    $beta = reportingDoctor($user, 'Dr Beta Clinic');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/accounts?search=Beta')
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $beta->id])
        ->assertJsonMissing(['name' => 'Dr Alpha']);
});

it('shows a single account with type', function () {
    $user = fieldSalesUser('AccountShow');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Detail');

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/accounts/{$doctor->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $doctor->id)
        ->assertJsonPath('data.name', 'Dr Detail')
        ->assertJsonPath('data.type.name', 'Doctor');
});

it('allows sales users to create account masters from the app', function () {
    $user = fieldSalesUser('AccountCreate');
    $territory = reportingTerritory('Account Terr');
    $patch = reportingPatch($territory, 'Account Patch');
    $type = TypeMaster::query()->firstOrCreate(
        ['name' => 'Doctor', 'typeable_type' => AccountMaster::class],
        ['parent_id' => null],
    );

    expect($user->can('Create:AccountMaster'))->toBeTrue()
        ->and($user->can('create', AccountMaster::class))->toBeTrue();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/accounts', [
        'name' => 'Dr New Field',
        'type_master_id' => $type->id,
        'phone_number' => '9876543210',
        'territory_id' => $territory->id,
        'patch_ids' => [$patch->id],
        'contact' => [
            'first_name' => 'New',
            'last_name' => 'Field',
            'mobile_number' => '9876543210',
        ],
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Dr New Field')
        ->assertJsonPath('data.owner_id', $user->id)
        ->assertJsonPath('data.contacts.0.mobile_number', '9876543210');

    expect(AccountMaster::query()->where('name', 'Dr New Field')->exists())->toBeTrue();
});

it('forbids account create without Create:AccountMaster permission', function () {
    $this->seed(ConfigDrivenShieldPermissionSeeder::class);

    $user = reportingUser('AccountDenied');
    $type = TypeMaster::query()->firstOrCreate(
        ['name' => 'Doctor', 'typeable_type' => AccountMaster::class],
        ['parent_id' => null],
    );

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/accounts', [
        'name' => 'Dr Forbidden',
        'type_master_id' => $type->id,
    ])->assertForbidden();
});

it('allows sales users to update accounts they can see', function () {
    $user = fieldSalesUser('AccountUpdate');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Old Name');

    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/accounts/{$doctor->id}", [
        'name' => 'Dr Renamed',
        'phone_number' => '9000011111',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Dr Renamed')
        ->assertJsonPath('data.phone_number', '9000011111');
});

it('lists account types', function () {
    $user = fieldSalesUser('AccountTypes');
    $this->actingAs($user);

    reportingDoctor($user, 'Dr TypeSeed');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/account-types')
        ->assertSuccessful()
        ->assertJsonFragment(['name' => 'Doctor']);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/accounts')->assertUnauthorized();
});

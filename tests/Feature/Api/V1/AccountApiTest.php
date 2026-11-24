<?php

use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\ApprovalSetting;
use App\Models\ContactDetail;
use App\Models\JobRole;
use App\Models\Position;
use App\Models\TypeMaster;
use Database\Seeders\ConfigDrivenShieldPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('filters scoped register rows and keeps trash off the legacy list and detail', function () {
    $actor = grantPagePermissions(fieldSalesUser('Account Register'), 'Delete:AccountMaster', 'Restore:AccountMaster');
    $other = reportingUser('Hidden Register Owner');
    $outside = reportingDoctor($other, 'Outside Account');
    $territory = reportingTerritory('Visible Register Territory');
    $patch = reportingPatch($territory, 'Visible Register Patch');
    Sanctum::actingAs($actor);
    $account = reportingDoctor($actor, 'Visible Account');
    $contact = ContactDetail::query()->create(['first_name' => 'Linked']);
    $account->contactDetails()->attach($contact);
    $account->patches()->attach($patch);
    $specialty = AccountReportingMaster::query()->create(['kind' => 'specialty', 'code' => 'acc_sp', 'name' => 'Cardiology', 'is_active' => true]);
    $account->update(['specialty_id' => $specialty->id, 'territory_id' => $territory->id]);
    $url = '/api/v1/accounts';
    $this->getJson($url.'/register-options')->assertOk()
        ->assertJsonPath('data.territories.0.id', $territory->id)->assertJsonPath('data.patches.0.id', $patch->id);
    $this->getJson($url.'?register=1&specialty_id='.$specialty->id.'&patch_id='.$patch->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.specialty_name', 'Cardiology')
        ->assertJsonPath('data.0.actions', ['delete']);
    $this->getJson($url.'/'.$outside->id.'?register=1')->assertNotFound();
    $this->postJson($url.'/register-actions', ['action' => 'delete', 'ids' => [$account->id, $outside->id]])->assertNotFound();
    expect($account->refresh()->trashed())->toBeFalse();
    $this->postJson($url.'/register-actions', ['action' => 'delete', 'ids' => [$account->id]])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($url.'/'.$account->id)->assertNotFound();
    $this->getJson($url.'?register=1&trash=only')->assertOk()->assertJsonPath('data.0.trashed', true);
    $this->getJson($url.'/'.$account->id.'?register=1')->assertOk()->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.contacts.0.id', $contact->id);
    $this->postJson($url.'/register-actions', ['action' => 'restore', 'ids' => [$account->id]])->assertOk();
    expect($account->refresh()->contactDetails()->sole()->id)->toBe($contact->id);
});

it('checks approval locks and protects account history from permanent deletion', function () {
    $actor = grantPagePermissions(fieldSalesUser('Account Lifecycle'), 'Delete:AccountMaster', 'ForceDelete:AccountMaster');
    Sanctum::actingAs($actor);
    $locked = reportingDoctor($actor, 'Approved Account');
    $empty = reportingDoctor($actor, 'Unused Account');
    $linked = reportingDoctor($actor, 'Linked Account');
    $linked->callTargets()->create(['calls_per_month' => 2, 'effective_from' => today()->startOfMonth()]);
    AccountMaster::query()->whereKey($locked->id)->update(['approval_status' => 'approved']);
    $url = '/api/v1/accounts/register-actions';
    $this->postJson($url, ['action' => 'delete', 'ids' => [$empty->id, $locked->id]])->assertUnprocessable();
    expect($empty->refresh()->trashed())->toBeFalse();
    $this->postJson($url, ['action' => 'delete', 'ids' => [$empty->id, $linked->id]])->assertOk()->assertJsonPath('data.updated', 2);
    $this->postJson($url, ['action' => 'forceDelete', 'ids' => [$linked->id]])->assertUnprocessable();
    $this->postJson($url, ['action' => 'forceDelete', 'ids' => [$empty->id]])->assertOk();
    expect(AccountMaster::withTrashed()->find($empty->id))->toBeNull()
        ->and(ApprovalSetting::supportsModule(AccountMaster::class))->toBeFalse();
});

it('edits call targets and maintains scoped contact links on the account', function () {
    $actor = grantPagePermissions(fieldSalesUser('Account Relationships'), 'ViewAny:ContactDetail', 'View:ContactDetail', 'Create:ContactDetail', 'Update:ContactDetail');
    Sanctum::actingAs($actor);
    $account = reportingDoctor($actor, 'Account Relationships');
    $other = reportingDoctor($actor, 'Other Account Relationships');
    $url = '/api/v1/accounts/'.$account->id;
    $target = ['calls_per_month' => 4, 'effective_from' => '2026-11-01'];
    $saved = $this->patchJson($url, ['call_targets' => [$target]])->assertOk()->assertJsonPath('data.call_targets.0.calls_per_month', 4);
    $this->patchJson('/api/v1/accounts/'.$other->id, ['call_targets' => [[...$target, 'id' => $saved->json('data.call_targets.0.id')]]])
        ->assertUnprocessable()->assertJsonValidationErrors('call_targets');
    $contact = ['first_name' => 'Linked', 'mobile_number' => '9876543210', 'email' => 'linked@example.test'];
    $response = $this->postJson($url.'/contacts', $contact)->assertSuccessful();
    $id = $response->json('data.contacts.0.id');
    $this->getJson($url.'?register=1')->assertOk()->assertJsonPath('data.contact_actions.attach', true)->assertJsonPath('data.contacts.0.can_edit', true);
    $this->patchJson('/api/v1/accounts/'.$other->id.'/contacts/'.$id, $contact)->assertNotFound();
    $this->patchJson($url.'/contacts/'.$id, [...$contact, 'first_name' => 'Updated'])->assertOk()->assertJsonPath('data.contacts.0.first_name', 'Updated');
    $this->deleteJson($url.'/contacts/'.$id)->assertOk()->assertJsonCount(0, 'data.contacts');
    $this->putJson($url.'/contacts/'.$id)->assertOk()->assertJsonCount(1, 'data.contacts');
    $this->putJson($url.'/contacts/'.$id)->assertOk()->assertJsonCount(1, 'data.contacts');
    $this->putJson('/api/v1/accounts/'.$other->id.'/contacts/'.$id)->assertOk();
    $actor->givePermissionTo(Permission::findOrCreate('Delete:ContactDetail', 'web'));
    Sanctum::actingAs($actor->fresh());
    $this->postJson($url.'/related-actions', ['group' => 'contacts', 'action' => 'delete', 'ids' => [$id]])->assertUnprocessable();
    $this->postJson($url.'/related-actions', ['group' => 'contacts', 'action' => 'detach', 'ids' => [$id]])->assertOk();
    expect($other->contactDetails()->whereKey($id)->exists())->toBeTrue();
});

it('removes only records belonging to the selected account in a related batch', function () {
    $actor = fieldSalesUser('Account Financial Records');
    Sanctum::actingAs($actor);
    $account = reportingDoctor($actor, 'First Bank Account');
    $other = reportingDoctor($actor, 'Second Bank Account');
    $first = $account->bankDetail()->create(['bank_name' => 'Local', 'bank_account_number' => '110001']);
    $second = $other->bankDetail()->create(['bank_name' => 'Local', 'bank_account_number' => '110002']);
    $url = '/api/v1/accounts/'.$account->id.'/related-actions';
    $this->postJson($url, ['group' => 'bank', 'action' => 'delete', 'ids' => [$first->id, $second->id]])->assertNotFound();
    expect($first->refresh()->trashed())->toBeFalse();
    $this->postJson($url, ['group' => 'bank', 'action' => 'delete', 'ids' => [$first->id]])->assertOk();
    expect($first->refresh()->trashed())->toBeTrue()->and($second->refresh()->trashed())->toBeFalse();
});

it('lets field team managers assign owners and keeps ordinary account writes owner-scoped', function () {
    $rep = fieldSalesUser('Account Ownership Rep');
    $owner = reportingUser('Assigned Account Owner');
    $type = reportingDoctor($rep, 'Account Type Seed')->type_master_id;
    Sanctum::actingAs($rep);
    $url = '/api/v1/accounts';
    $payload = ['name' => 'Assigned Account', 'type_master_id' => $type, 'assign_owner_id' => $owner->id];
    $this->getJson($url.'/owner-options')->assertForbidden();
    $this->postJson($url, $payload)->assertForbidden();
    $manager = grantPagePermissions($rep, 'Manage:FieldTeam')->fresh();
    Sanctum::actingAs($manager);
    $this->getJson($url.'/owner-options?search=Assigned+Account')->assertOk()
        ->assertJsonPath('data', [['id' => $owner->id, 'label' => $owner->name]]);
    $created = $this->postJson($url, $payload)->assertSuccessful()->assertJsonPath('data.owner_id', $owner->id);
    $id = $created->json('data.id');
    $this->getJson($url.'/'.$id.'?register=1')->assertOk()->assertJsonPath('data.can_assign_owner', true);
    $this->travel(1)->seconds();
    $this->patchJson($url.'/'.$id, ['assign_owner_id' => $manager->id])->assertOk()
        ->assertJsonPath('data.owner_id', $manager->id)
        ->assertJsonCount(2, 'data.assignment_history');
    $this->patchJson($url.'/'.$id, ['owner_id' => $owner->id])->assertOk()->assertJsonPath('data.owner_id', $manager->id);
});

it('lists visible account masters for sales users', function () {
    $user = fieldSalesUser('AccountList');
    $this->actingAs($user);

    $doctor = reportingDoctor($user, 'Dr Listable');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/accounts')
        ->assertSuccessful()
        ->assertJsonPath('can_create', true)
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
    $role = JobRole::query()->create(['name' => 'Account Rep', 'code' => 'ACCTREP', 'level' => 1]);
    $position = Position::query()->create(['name' => 'Account Rep', 'code' => 'ACCTREP', 'job_role_id' => $role->id]);
    $position->territories()->attach($territory->id);
    $user->employee->positions()->attach($position->id, ['is_primary' => true]);
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
        'geo_latitude' => 19.076,
        'geo_longitude' => 72.877,
        'geo_radius_meters' => 250,
        'rcpa_tier' => 'A',
        'accept_secondary_statements' => true,
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

    $created = AccountMaster::query()->where('name', 'Dr New Field')->sole();
    $outside = reportingTerritory('Outside Account Territory');
    $outsidePatch = reportingPatch($outside, 'Outside Account Patch');
    $this->patchJson("/api/v1/accounts/{$created->id}", ['territory_id' => $outside->id])
        ->assertUnprocessable()->assertJsonValidationErrors('territory_id');
    $this->patchJson("/api/v1/accounts/{$created->id}", ['patch_ids' => [$outsidePatch->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('patch_ids');
    $other = reportingUser('Other Account Owner');
    Sanctum::actingAs($other);
    $unrelated = reportingDoctor($other, 'Dr Outside Scope');
    Sanctum::actingAs($user);
    $this->patchJson("/api/v1/accounts/{$created->id}", ['parent_id' => $unrelated->id])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

    expect((float) $created->geo_latitude)->toBe(19.076)
        ->and((float) $created->geo_longitude)->toBe(72.877)
        ->and($created->geo_radius_meters)->toBe(250)
        ->and($created->rcpa_tier)->toBe('A')
        ->and($created->accept_secondary_statements)->toBeTrue();
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
        'geo_latitude' => 20.5,
        'geo_longitude' => 73.5,
        'geo_radius_meters' => 150,
        'rcpa_tier' => 'B',
        'accept_secondary_statements' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.name', 'Dr Renamed')
        ->assertJsonPath('data.phone_number', '9000011111');

    expect((float) $doctor->fresh()->geo_latitude)->toBe(20.5)
        ->and($doctor->fresh()->rcpa_tier)->toBe('B')
        ->and($doctor->fresh()->accept_secondary_statements)->toBeTrue();
    $this->patchJson("/api/v1/accounts/{$doctor->id}", [
        'geo_latitude' => 91, 'geo_longitude' => 181, 'geo_radius_meters' => 251, 'rcpa_tier' => 'C',
    ])->assertUnprocessable()->assertJsonValidationErrors(['geo_latitude', 'geo_longitude', 'geo_radius_meters', 'rcpa_tier']);
    expect((float) $doctor->fresh()->geo_latitude)->toBe(20.5);
});

it('denies a direct account update without Update:AccountMaster', function () {
    $user = reportingUser('AccountDirectDenied');
    $this->actingAs($user);
    $doctor = reportingDoctor($user, 'Dr Untouched');

    Sanctum::actingAs(grantPagePermissions($user, 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'));

    $this->getJson('/api/v1/accounts')->assertSuccessful()->assertJsonPath('can_create', false);
    $this->getJson("/api/v1/accounts/{$doctor->id}")->assertSuccessful()->assertJsonPath('data.can_update', false);

    $this->patchJson("/api/v1/accounts/{$doctor->id}", [
        'name' => 'Dr Forced',
    ])->assertForbidden();

    expect($doctor->fresh()->name)->toBe('Dr Untouched');
});

it('rejects an invalid account email without renaming the account', function () {
    $user = fieldSalesUser('AccountInvalidEmail');
    $this->actingAs($user);
    $doctor = reportingDoctor($user, 'Dr Valid');

    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/accounts/{$doctor->id}", [
        'email' => 'not-an-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($doctor->fresh()->name)->toBe('Dr Valid')
        ->and($doctor->fresh()->email)->toBeNull();
});

it('denies account update after the sales role is removed', function () {
    $user = fieldSalesUser('AccountRoleRemoved');
    $this->actingAs($user);
    $doctor = reportingDoctor($user, 'Dr Kept');

    $user->removeRole('sales_user');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Sanctum::actingAs($user->fresh());

    $this->patchJson("/api/v1/accounts/{$doctor->id}", [
        'name' => 'Dr Changed',
    ])->assertForbidden();

    expect($doctor->fresh()->name)->toBe('Dr Kept');
});

it('does not update an account outside the sales user visibility', function () {
    $owner = reportingUser('AccountHiddenOwner');
    $this->actingAs($owner);
    $hidden = reportingDoctor($owner, 'Dr Hidden');

    $user = fieldSalesUser('AccountHiddenViewer');

    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/accounts/{$hidden->id}", [
        'name' => 'Dr Stolen',
    ])->assertNotFound();

    expect($hidden->fresh()->name)->toBe('Dr Hidden');
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

<?php

use App\Models\Address;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Company;
use App\Models\ContactDetail;
use App\Models\Country;
use App\Models\State;
use App\Models\TypeMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function contactApiCreatedBy(User $creator, string $firstName, ?string $mobile = null): ContactDetail
{
    $contact = ContactDetail::query()->create([
        'first_name' => $firstName,
        'last_name' => 'Contact',
        'mobile_number' => $mobile,
    ]);
    $contact->forceFill(['created_by' => $creator->id])->save();

    return $contact->fresh();
}

it('forbids the contact list without ViewAny:ContactDetail', function () {
    Sanctum::actingAs(reportingUser('ContactApiDenied'));

    $this->getJson('/api/v1/contacts')->assertForbidden();
});

it('lists only visible contacts with picker fields', function () {
    $user = reportingUser('ContactApiViewer');
    $other = reportingUser('ContactApiOther');
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:ContactDetail', 'web'));
    $mine = contactApiCreatedBy($user, 'Asha', '9000000101');
    $hidden = contactApiCreatedBy($other, 'Hidden');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/contacts')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.full_name', 'Asha Contact')
        ->assertJsonPath('data.0.mobile_number', '9000000101')
        ->assertJsonMissing(['id' => $hidden->id])
        ->assertJsonMissingPath('data.0.notes')
        ->assertJsonPath('meta.total', 1);
});

it('searches visible contacts by name or mobile', function () {
    $user = reportingUser('ContactApiSearch');
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:ContactDetail', 'web'));
    $asha = contactApiCreatedBy($user, 'Asha', '9000000201');
    $bhavna = contactApiCreatedBy($user, 'Bhavna', '9000000202');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/contacts?search=Bhav')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $bhavna->id);

    $this->getJson('/api/v1/contacts?search=0201')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asha->id);
});

it('lists every contact for AccessAllRecords', function () {
    $user = reportingUser('ContactApiAll');
    $other = reportingUser('ContactApiAllOther');
    $user->givePermissionTo([
        Permission::findOrCreate('ViewAny:ContactDetail', 'web'),
        Permission::findOrCreate('AccessAllRecords', 'web'),
    ]);
    $foreign = contactApiCreatedBy($other, 'Foreign');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/contacts?search=Foreign')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $foreign->id);
});

it('lists only a visible contact’s linked addresses for CRM selection', function () {
    $actor = grantPagePermissions(reportingUser('Contact Address Picker'), 'ViewAny:ContactDetail');
    $other = reportingUser('Other Address Picker');
    $contact = contactApiCreatedBy($actor, 'Selected');
    $otherContact = contactApiCreatedBy($actor, 'Unselected');
    $hidden = contactApiCreatedBy($other, 'Hidden');
    $fields = ['pin_code' => 380001, 'address_type' => 'Contact'];
    $legacy = Address::query()->create([...$fields, 'contact_detail_id' => $contact->id, 'street' => 'Legacy Lane', 'sort' => 1]);
    $owned = $contact->addresses()->create([...$fields, 'street' => 'Contact Street', 'sort' => 2]);
    $dual = $contact->addresses()->create([...$fields, 'contact_detail_id' => $contact->id, 'street' => 'Dual Link Road', 'sort' => 3]);
    $otherContact->addresses()->create([...$fields, 'street' => 'Unselected Address']);
    $hidden->addresses()->create([...$fields, 'street' => 'Hidden Address']);
    $contact->addresses()->create([...$fields, 'street' => 'Removed Address'])->delete();

    Sanctum::actingAs($actor);
    $endpoint = '/api/v1/contacts/';
    $this->getJson($endpoint.$contact->id.'/addresses')->assertForbidden();

    grantPagePermissions($actor, 'View:ContactDetail');
    $this->getJson($endpoint.$contact->id.'/addresses')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $legacy->id)
        ->assertJsonPath('data.1.id', $owned->id)
        ->assertJsonPath('data.2.id', $dual->id);
    $this->getJson($endpoint.$hidden->id.'/addresses')->assertNotFound();
});

it('edits a standalone contact with scoped company and designation choices', function () {
    $actor = grantPagePermissions(reportingUser('Contact Register'), 'ViewAny:ContactDetail', 'View:ContactDetail',
        'Create:ContactDetail', 'Update:ContactDetail', 'ViewAny:Company', 'View:Company',
        'ViewAny:Designation', 'View:Designation', 'Create:Designation');
    $other = reportingUser('Other Contact Register');
    Sanctum::actingAs($other);
    $hidden = Company::query()->create(['name' => 'Hidden Company']);
    Sanctum::actingAs($actor);
    $visible = Company::query()->create(['name' => 'Visible Company']);
    $url = '/api/v1/contacts';
    $this->getJson($url.'/options/companies')->assertOk()->assertJsonPath('data', [['id' => $visible->id, 'label' => $visible->name]]);
    $this->getJson($url.'?register=1&company_id='.$hidden->id)->assertNotFound();
    $this->postJson($url.'/options/departments', ['name' => 'Unpermitted'])->assertForbidden();
    $designation = $this->postJson($url.'/options/designations', ['name' => 'Coordinator'])->assertCreated()->json('data.id');
    $input = ['first_name' => 'Asha', 'last_name' => 'Shah', 'mobile_number' => '9876543210', 'email' => 'asha@example.test', 'birthday' => '1990-03-04'];
    $this->postJson($url, [...$input, 'company_id' => $hidden->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');
    $created = $this->postJson($url, [...$input, 'company_id' => $visible->id, 'designation_id' => $designation, 'notes' => 'Private details'])
        ->assertCreated()->assertJsonPath('data.whatsapp_number', '9876543210')->assertJsonPath('data.designation.label', 'Coordinator');
    $id = $created->json('data.id');
    $this->getJson($url.'?register=1&search=Asha+Shah&company_id='.$visible->id)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.company.label', $visible->name)->assertJsonPath('data.0.birthday', '1990-03-04')->assertJsonMissingPath('data.0.notes');
    $this->getJson($url)->assertOk()->assertJsonMissingPath('data.0.company');
    $this->patchJson($url.'/'.$id, ['notes' => 'Updated', 'last_name' => null])->assertOk()
        ->assertJsonPath('data.notes', 'Updated')->assertJsonPath('data.last_name', null)->assertJsonPath('data.email', $input['email']);
    Sanctum::actingAs(grantPagePermissions($other, 'ViewAny:ContactDetail', 'View:ContactDetail', 'Update:ContactDetail'));
    $this->getJson($url.'/'.$id)->assertNotFound();
    $this->patchJson($url.'/'.$id, ['notes' => 'Unauthorized'])->assertNotFound();
});

it('keeps bulk deletion atomic and protects linked or approved contacts', function () {
    $actor = grantPagePermissions(fieldSalesUser('Contact Delete'), 'ViewAny:ContactDetail', 'View:ContactDetail', 'Delete:ContactDetail', 'Update:ContactDetail');
    $free = contactApiCreatedBy($actor, 'Free');
    $linked = contactApiCreatedBy($actor, 'Linked');
    $approved = contactApiCreatedBy($actor, 'Approved');
    $account = reportingDoctor($actor, 'Contact Account');
    $account->contactDetails()->attach($linked);
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'active' => false]);
    $approved->approval()->create(['approval_flow_id' => $flow->id, 'requested_by' => $actor->id,
        'record_type' => ContactDetail::class, 'record_id' => $approved->id, 'module' => 'ContactDetail', 'approval_status' => 'approved']);
    Sanctum::actingAs($actor);
    $url = '/api/v1/contacts';
    $this->postJson($url.'/register-actions', ['ids' => [$free->id, $linked->id]])->assertUnprocessable();
    $this->postJson($url.'/register-actions', ['ids' => [$free->id, $approved->id]])->assertUnprocessable();
    $this->patchJson($url.'/'.$approved->id, ['notes' => 'Locked'])->assertUnprocessable();
    $account->contactDetails()->attach($approved);
    $this->patchJson('/api/v1/accounts/'.$account->id.'/contacts/'.$approved->id, [
        'first_name' => 'Locked Contact', 'email' => 'locked@example.test', 'mobile_number' => '9876543210',
    ])->assertUnprocessable();
    expect($free->fresh()->trashed())->toBeFalse();
    $this->postJson($url.'/register-actions', ['ids' => [$free->id]])->assertOk()->assertJsonPath('data.updated', 1);
    $this->getJson($url.'/'.$free->id)->assertNotFound();
    expect($account->contactDetails()->whereKey($linked->id)->exists())->toBeTrue()
        ->and(ApprovalSetting::supportsModule(ContactDetail::class))->toBeFalse();
});

it('creates and edits contact-owned addresses without accepting another parent or pin mismatch', function () {
    $actor = grantPagePermissions(reportingUser('Contact Address'), 'ViewAny:ContactDetail', 'View:ContactDetail', 'Update:ContactDetail');
    $contact = contactApiCreatedBy($actor, 'Address');
    $other = contactApiCreatedBy($actor, 'Other Address');
    $country = Country::query()->create(['name' => 'India']);
    $state = State::query()->create(['name' => 'Gujarat', 'country_id' => $country->id]);
    $city = City::query()->create(['name' => 'Ahmedabad', 'state_id' => $state->id, 'country_id' => $country->id]);
    $area = CityPinCode::query()->create(['area_town' => 'Test Area', 'pin_code' => '380001', 'city_id' => $city->id, 'state_id' => $state->id, 'country_id' => $country->id]);
    $type = TypeMaster::query()->create(['typeable_type' => Address::class, 'name' => 'Office']);
    $payload = ['type_master_id' => $type->id, 'street' => 'First Street', 'area_town_id' => $area->id, 'pin_code' => '380001'];
    Sanctum::actingAs($actor);
    $url = '/api/v1/contacts/'.$contact->id;
    $this->postJson($url.'/addresses', [...$payload, 'pin_code' => '400001'])->assertUnprocessable()->assertJsonValidationErrors('area_town_id');
    $id = $this->postJson($url.'/addresses', $payload)->assertCreated()->assertJsonPath('data.street', 'First Street')->json('data.id');
    $this->patchJson('/api/v1/contacts/'.$other->id.'/addresses/'.$id, $payload)->assertNotFound();
    $this->patchJson($url.'/addresses/'.$id, [...$payload, 'street' => 'Second Street'])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonPath('data.addresses.0.street', 'Second Street');
    $this->deleteJson($url.'/addresses/'.$id)->assertOk();
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.addresses');
    $legacy = $contact->addresses()->create(['street' => 'Legacy Street', 'pin_code' => 380002, 'city_id' => $city->id]);
    $this->patchJson($url.'/addresses/'.$legacy->id, ['street' => 'Renamed Street'])->assertOk()
        ->assertJsonPath('data.area_town_id', null)->assertJsonPath('data.city_id', $city->id);
});

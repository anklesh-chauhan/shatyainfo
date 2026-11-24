<?php

use App\Filament\Resources\LocationMasters\Pages\CreateLocationMaster;
use App\Filament\Resources\LocationMasters\Pages\EditLocationMaster;
use App\Filament\Resources\LocationMasters\Pages\ListLocationMasters;
use App\Filament\Resources\LocationMasters\RelationManagers\AddressesRelationManager;
use App\Models\Address;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\EmploymentDetail;
use App\Models\InventoryStock;
use App\Models\JobRole;
use App\Models\LocationMaster;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\TypeMaster;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\LocationRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Location editor');
    $this->outside = reportingUser('Other location editor');
    $grants = ['ViewAny:LocationMaster', 'View:LocationMaster', 'Create:LocationMaster', 'Update:LocationMaster', 'Delete:LocationMaster', 'DeleteAny:LocationMaster',
        'ViewAny:Territory', 'View:Territory', 'ViewAny:ContactDetail', 'View:ContactDetail', 'Update:ContactDetail',
        'ViewAny:Address', 'View:Address', 'Create:Address', 'Update:Address', 'Delete:Address', 'ViewAny:InventoryStock', 'View:InventoryStock'];
    foreach ([...$grants, 'AccessAllRecords', 'OverrideApproval:LocationMaster', 'OverrideApproval:Address'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo($grants);
    Sanctum::actingAs($this->outside);
    $this->hidden = reportingLocation('Hidden warehouse');
    $this->hiddenType = TypeMaster::query()->create(['name' => 'Private type', 'typeable_type' => LocationMaster::class]);
    $this->hiddenTerritory = reportingTerritory('Private territory');
    $this->hiddenContact = reportingContact('Hidden', 'Contact');
    Sanctum::actingAs($this->actor);
    $this->type = TypeMaster::query()->create(['name' => 'Warehouse', 'typeable_type' => LocationMaster::class]);
    $this->territory = reportingTerritory('Local territory');
    $this->contact = reportingContact('Local', 'Contact');
    $this->parent = reportingLocation('Central warehouse');
    $this->locations = app(LocationRegister::class);
    $this->input = ['name' => 'New warehouse', 'typeable_id' => $this->type->id, 'parent_id' => $this->parent->id, 'contact_id' => null,
        'territory_ids' => [$this->territory->id], 'latitude' => '-23.1234567', 'longitude' => '179.1234567', 'is_active' => true];
    $this->path = '/api/v1/location-masters';
});

it('negotiates a scoped location register outside the optional inventory toggle', function () {
    Organization::query()->create(['name' => 'No inventory', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonFragment(['key' => 'g07-09', 'screen_key' => 'location-masters']);
    $this->getJson($this->path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->parent->id);
    $this->getJson($this->path.'/'.$this->hidden->id)->assertNotFound();
    $this->getJson($this->path.'?search=Hidden')->assertJsonCount(0, 'data');
    foreach (['parents', 'types', 'territories', 'contacts'] as $kind) {
        $this->getJson($this->path.'/options/'.$kind.'?search=Private')->assertJsonCount(0, 'data');
    }
    $this->getJson($this->path.'/options/parents?location_id='.$this->hidden->id)->assertNotFound();
    $this->getJson($this->path.'/'.$this->parent->id.'/related/stocks')->assertForbidden();
    $this->actor->revokePermissionTo('View:LocationMaster');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/types')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-09']);
});

it('creates with a server number and idempotency and rejects forged lifecycle fields and stale edits', function () {
    $data = $this->input + ['location_code' => 'FORGED', 'created_by' => $this->outside->id, 'approval_status' => 'approved'];
    $created = $this->postJson($this->path, $data, ['Idempotency-Key' => 'd6bf4bbb-e1b0-4d65-97a4-270be44aaa99'])->assertCreated();
    $id = $created->json('data.id');
    $this->postJson($this->path, $data, ['Idempotency-Key' => 'd6bf4bbb-e1b0-4d65-97a4-270be44aaa99'])->assertCreated()->assertJsonPath('data.id', $id);
    $record = LocationMaster::query()->findOrFail($id);
    expect($record->created_by)->toBe($this->actor->id)->and($record->location_code)->not->toBe('FORGED');
    $detail = $this->getJson($this->path.'/'.$id)->assertOk()->assertJsonPath('data.latitude', '-23.1234567')->json('data');
    $this->patchJson($this->path.'/'.$id, array_replace($this->input, ['name' => 'Renamed', 'revision' => $detail['revision']]))->assertOk();
    $this->patchJson($this->path.'/'.$id, $this->input + ['revision' => $detail['revision']])->assertConflict();
    $this->postJson($this->path.'/'.$id.'/actions/delete', ['revision' => $detail['revision']])->assertConflict();
    $this->getJson($this->path.'?search=Renamed&active=1')->assertJsonCount(1, 'data');
    $this->getJson($this->path.'?active=0')->assertJsonCount(0, 'data');
});

it('validates scoped selections coordinates subtype families and hierarchy cycles', function () {
    foreach (['parent_id' => $this->hidden->id, 'typeable_id' => $this->hiddenType->id, 'contact_id' => $this->hiddenContact->id,
        'territory_ids' => [$this->hiddenTerritory->id], 'latitude' => 91, 'longitude' => -181] as $field => $value) {
        $this->postJson($this->path, array_replace($this->input, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $subtype = TypeMaster::query()->create(['name' => 'Cold storage', 'typeable_type' => LocationMaster::class, 'parent_id' => $this->type->id]);
    $this->postJson($this->path, $this->input)->assertUnprocessable()->assertJsonValidationErrors('typeable_id');
    $record = $this->locations->save($this->actor, null, array_replace($this->input, ['typeable_id' => $subtype->id]));
    $this->getJson($this->path.'/options/subtypes?parent_type_id='.$this->type->id)->assertJsonPath('data.0.id', $subtype->id);
    $this->getJson($this->path.'/options/parents?location_id='.$this->parent->id)->assertJsonMissing(['id' => $record->id])->assertJsonMissing(['id' => $this->parent->id]);
    $this->parent->update(['typeable_id' => $subtype->id]);
    $this->patchJson($this->path.'/'.$this->parent->id, array_replace($this->input, ['typeable_id' => $subtype->id, 'parent_id' => $record->id, 'revision' => $this->locations->revision($this->parent->fresh())]))->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    foreach (['page=0', 'active=no', 'search[]=bad'] as $query) {
        $this->getJson($this->path.'?'.$query)->assertUnprocessable();
    }
});

it('links available contacts without stealing them and preserves inaccessible existing territory links', function () {
    $record = $this->locations->save($this->actor, null, array_replace($this->input, ['contact_id' => $this->contact->id]));
    expect($record->contactDetail->id)->toBe($this->contact->id);
    $this->postJson($this->path, array_replace($this->input, ['contact_id' => $this->contact->id]))->assertUnprocessable();
    $record->territories()->attach($this->hiddenTerritory->id);
    $detail = $this->getJson($this->path.'/'.$record->id)->assertOk()->assertJsonPath('data.contact', 'Local Contact')->assertJsonCount(1, 'data.territories')->json('data');
    $this->patchJson($this->path.'/'.$record->id, array_replace($this->input, ['contact_id' => $this->contact->id, 'revision' => $detail['revision']]))->assertOk();
    expect($record->territories()->whereKey($this->hiddenTerritory->id)->exists())->toBeTrue();
    $this->actor->revokePermissionTo('Update:ContactDetail');
    $this->patchJson($this->path.'/'.$record->id, $this->input + ['revision' => $this->locations->revision($record->fresh())])->assertUnprocessable();
    expect($this->contact->fresh()->contactable_id)->toBe($record->id);
});

it('stores validated private images and enforces location scope on image reads and removal', function () {
    Storage::fake();
    $image = UploadedFile::fake()->image('warehouse.jpg', 20, 20);
    $record = $this->locations->save($this->actor, null, $this->input + ['image_base64' => base64_encode($image->get())]);
    Storage::assertExists($record->image);
    $this->getJson($this->path.'/'.$record->id)->assertJsonPath('data.has_image', true)->assertJsonMissingPath('data.image');
    $this->get($this->path.'/'.$record->id.'/image')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
    Sanctum::actingAs($this->outside);
    $this->outside->givePermissionTo(['ViewAny:LocationMaster', 'View:LocationMaster']);
    $this->get($this->path.'/'.$record->id.'/image')->assertNotFound();
    Sanctum::actingAs($this->actor);
    $this->patchJson($this->path.'/'.$record->id, $this->input + ['revision' => $this->locations->revision($record), 'remove_image' => true])->assertOk()->assertJsonPath('data.has_image', false);
    $this->get($this->path.'/'.$record->id.'/image')->assertNotFound();
    $this->postJson($this->path, $this->input + ['image_base64' => base64_encode('<svg onload="alert(1)"></svg>')])->assertUnprocessable();
});

it('owns addresses by the parent location and rejects stale cross-parent and locked address writes', function () {
    $record = $this->locations->save($this->actor, null, $this->input);
    $type = TypeMaster::query()->create(['name' => 'Office address', 'typeable_type' => Address::class]);
    $input = ['type_master_id' => $type->id, 'street' => 'Main road', 'pin_code' => '380001', 'area_town_id' => null, 'is_primary' => true];
    $revision = $this->locations->revision($record);
    $this->postJson($this->path.'/'.$record->id.'/addresses', $input + ['revision' => $revision])->assertOk();
    $address = $record->addresses()->sole();
    expect($address->company_id)->toBeNull()->and($address->addressable_type)->toBe($record->getMorphClass());
    $this->patchJson($this->path.'/'.$record->id.'/addresses/'.$address->id, $input + ['revision' => $revision])->assertConflict();
    $this->getJson($this->path.'/'.$record->id.'/related/addresses')->assertOk()->assertJsonPath('data.0.street', 'Main road');
    $this->patchJson($this->path.'/'.$this->parent->id.'/addresses/'.$address->id, $input + ['revision' => $this->locations->revision($this->parent->fresh())])->assertNotFound();
    $this->actor->revokePermissionTo('Delete:Address');
    $this->deleteJson($this->path.'/'.$record->id.'/addresses/'.$address->id, ['revision' => $this->locations->revision($record->fresh())])->assertForbidden();
    $address->approval()->create(['approval_flow_id' => ApprovalFlow::query()->create(['module' => 'Address', 'active' => true])->id, 'approval_status' => 'pending', 'requested_by' => $this->actor->id, 'module' => 'Address']);
    $this->patchJson($this->path.'/'.$record->id.'/addresses/'.$address->id, $input + ['revision' => $this->locations->revision($record->fresh())])->assertUnprocessable();
});

it('deletes whole selections atomically and retains locations with stock hierarchy or document links', function () {
    $first = $this->locations->save($this->actor, null, array_replace($this->input, ['parent_id' => null]));
    $second = $this->locations->save($this->actor, null, array_replace($this->input, ['parent_id' => null]));
    $item = reportingSampleItem('LOCAL', 'Stocked sample');
    InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $second->id, 'quantity_on_hand' => 0]);
    $this->postJson($this->path.'/records/delete', ['ids' => [$first->id, $second->id]])->assertConflict();
    expect($first->fresh())->not->toBeNull();
    $this->postJson($this->path.'/records/delete', ['ids' => [$first->id, $this->hidden->id]])->assertNotFound();
    $this->actor->revokePermissionTo('DeleteAny:LocationMaster');
    $this->postJson($this->path.'/records/delete', ['ids' => [$first->id]])->assertForbidden();
    $this->postJson($this->path.'/'.$first->id.'/actions/delete', ['revision' => $this->locations->revision($first)])->assertOk();
    expect($first->fresh()->trashed())->toBeTrue();
});

it('submits rejects corrects and resubmits with pending and approved locks', function () {
    $manager = reportingUser('Location approver');
    $role = JobRole::query()->create(['name' => 'Location approver', 'code' => 'LOCAPP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Location review', 'code' => 'LOCPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [LocationMaster::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'LocationMaster', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $record = $this->locations->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/actions/submit', ['revision' => $this->locations->revision($record)])->assertOk();
    $record->refresh();
    $this->patchJson($path, $this->input + ['revision' => $this->locations->revision($record)])->assertForbidden();
    $this->postJson($path.'/actions/delete', ['revision' => $this->locations->revision($record)])->assertForbidden();
    Permission::findOrCreate('View:MyApprovals', 'web');
    $manager->givePermissionTo('View:MyApprovals');
    Sanctum::actingAs($manager);
    $step = $record->approval->steps()->sole();
    $this->getJson('/api/v1/my-approvals')->assertOk()->assertJsonFragment(['module' => 'LocationMaster']);
    $this->postJson('/api/v1/my-approvals/'.$step->id.'/decision', ['decision' => 'reject', 'comments' => 'Review location'])->assertOk();
    Sanctum::actingAs($this->actor);
    $record->refresh();
    $this->patchJson($path, $this->input + ['revision' => $this->locations->revision($record)])->assertOk();
    $this->postJson($this->path.'/records/submit', ['ids' => [$record->id]])->assertOk();
    $record->refresh();
    app(ApprovalService::class)->approve($record->approval, $manager->id);
    $this->getJson($path)->assertJsonPath('data.can_edit', false);
    $this->actor->givePermissionTo('OverrideApproval:LocationMaster');
    $this->patchJson($path, $this->input + ['revision' => $this->locations->revision($record->fresh())])->assertOk();
    $this->assertDatabaseHas('approval_overrides', ['model_type' => LocationMaster::class, 'model_id' => $record->id, 'user_id' => $this->actor->id]);
});

it('shares scoped Admin forms creation editing and a location-specific address relation', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateLocationMaster::class)->fillForm($this->input + ['parent_type_id' => $this->type->id])->call('create')->assertHasNoFormErrors();
    $record = LocationMaster::query()->where('name', 'New warehouse')->sole();
    $record->territories()->attach($this->hiddenTerritory->id);
    Livewire::test(EditLocationMaster::class, ['record' => $record->id])->fillForm(['name' => 'Admin warehouse'])->call('save')->assertHasNoFormErrors();
    expect($record->fresh()->name)->toBe('Admin warehouse')->and($record->territories()->whereKey($this->hiddenTerritory->id)->exists())->toBeTrue();
    Livewire::test(ListLocationMasters::class)->assertCanSeeTableRecords([$record, $this->parent])->assertCanNotSeeTableRecords([$this->hidden]);
    $addressType = TypeMaster::query()->create(['name' => 'Office', 'typeable_type' => Address::class]);
    Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $record, 'pageClass' => EditLocationMaster::class])->assertSuccessful()
        ->callTableAction('create', data: ['type_master_id' => $addressType->id, 'street' => 'Admin road', 'pin_code' => '380001', 'area_town_id' => null, 'is_primary' => true])
        ->assertHasNoTableActionErrors();
    expect($record->addresses()->sole()->company_id)->toBeNull();
});

it('rechecks permissions before replaying a successful location creation', function () {
    $headers = ['Idempotency-Key' => 'd6bf4bbb-e1b0-4d65-97a4-270be44aaa97'];
    $this->postJson($this->path, $this->input, $headers)->assertCreated();
    $this->actor->revokePermissionTo('Create:LocationMaster');
    $this->postJson($this->path, $this->input, $headers)->assertForbidden();
});

it('pages searches and scopes by creator OU while retaining explicit read grants', function () {
    for ($index = 0; $index < 30; $index++) {
        reportingLocation('Page location '.$index);
    }
    $this->getJson($this->path.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('last_page', 2);
    $this->getJson($this->path.'?search=Central')->assertJsonCount(1, 'data');
    $type = TypeMaster::query()->firstOrCreate(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $unit = OrganizationalUnit::query()->create(['name' => 'Location division', 'code' => 'LOCATION-DIV', 'type_master_id' => $type->id]);
    foreach ([$this->actor, $this->outside] as $user) {
        $employment = EmploymentDetail::query()->firstOrCreate(['employee_id' => $user->employee->id]);
        $employment->organizationalUnits()->attach($unit->id, ['is_primary' => true]);
    }
    Permission::findOrCreate('ViewOwnOU:LocationMaster', 'web');
    $this->actor->givePermissionTo('ViewOwnOU:LocationMaster');
    $this->actor->unsetRelation('employee');
    $this->getJson($this->path.'/'.$this->hidden->id)->assertOk();
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->actor->revokePermissionTo('ViewAny:LocationMaster');
    $this->getJson($this->path)->assertForbidden();
});

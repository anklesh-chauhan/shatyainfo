<?php

use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\State;
use App\Models\Territory;
use App\Models\TypeMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->url = '/api/v1/territories';
    $country = Country::create(['name' => 'India']);
    $state = State::create(['name' => 'Maharashtra', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Mumbai', 'state_id' => $state->id, 'country_id' => $country->id]);
    $this->pin = CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Andheri', 'pin_code' => 400058]);
    $this->actor = grantPagePermissions(reportingUser('Territory Editor'),
        'ViewAny:Territory', 'View:Territory', 'Create:Territory', 'Update:Territory', 'Delete:Territory');
    Sanctum::actingAs($this->actor);
});

it('scopes the register, filters and dependent hierarchy selectors', function () {
    Sanctum::actingAs(reportingUser('Other Territory Owner'));
    $hidden = reportingTerritory('Hidden Market');
    Sanctum::actingAs(grantPagePermissions($this->actor, 'ViewAny:TypeMaster', 'View:TypeMaster')->fresh());
    Organization::create(['name' => 'Territory Company', 'metadata' => ['uses_divisions' => false]]);
    $root = reportingTerritory('North Market');
    $type = TypeMaster::create(['name' => 'Metro market', 'typeable_type' => Territory::class]);
    $input = ['name' => 'Central Market', 'parent_territory_id' => $root->id, 'type_master_id' => $type->id,
        'status' => 'inactive', 'city_pin_code_ids' => [$this->pin->id]];
    $this->postJson($this->url, [...$input, 'parent_territory_id' => $hidden->id])->assertUnprocessable()->assertJsonValidationErrors('parent_territory_id');
    $id = $this->postJson($this->url, $input)->assertCreated()->assertJsonPath('data.parent.id', $root->id)
        ->assertJsonPath('data.city_pin_codes.0.label', 'Andheri, Mumbai, 400058')->json('data.id');
    $this->getJson($this->url.'?search=Metro&status=inactive&parent_territory_id='.$root->id.'&type_master_id='.$type->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson($this->url.'?search=Hidden')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->url.'/'.$hidden->id)->assertNotFound();
    $this->patchJson($this->url.'/'.$hidden->id, ['name' => 'Out of scope'])->assertNotFound();
    $this->getJson($this->url.'/options/parents?territory_id='.$root->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->url.'/options/city-pin-codes?search=400058')->assertOk()->assertJsonPath('data.0.id', $this->pin->id);
    $this->patchJson($this->url.'/'.$root->id, ['parent_territory_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('parent_territory_id');
    $this->patchJson($this->url.'/'.$id, ['parent_territory_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('parent_territory_id');
    $this->patchJson($this->url.'/'.$id, ['parent_territory_id' => null])->assertOk()->assertJsonPath('data.parent', null);
});

it('validates required links and preserves associations outside the current options', function () {
    $organization = Organization::create(['name' => 'Division enabled']);
    $actor = grantPagePermissions($this->actor, 'ViewAny:OrganizationalUnit', 'View:OrganizationalUnit', 'ViewAny:Position', 'View:Position');
    Sanctum::actingAs(reportingUser('Other Position Owner'));
    $role = JobRole::create(['name' => 'Field role', 'code' => 'FIELD', 'level' => 1]);
    $hidden = Position::create(['name' => 'Hidden position', 'code' => 'H-1', 'job_role_id' => $role->id]);
    Sanctum::actingAs($actor->fresh());
    $type = TypeMaster::create(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $division = OrganizationalUnit::create(['name' => 'West', 'code' => 'WEST', 'type_master_id' => $type->id]);
    $position = Position::create(['name' => 'Visible position', 'code' => 'V-1', 'job_role_id' => $role->id]);
    $input = ['name' => 'Linked Territory', 'city_pin_code_ids' => [$this->pin->id], 'division_ids' => [$division->id]];
    $this->postJson($this->url, ['name' => 'Missing PIN'])->assertUnprocessable()->assertJsonValidationErrors('city_pin_code_ids');
    $this->postJson($this->url, [...$input, 'division_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('division_ids');
    $this->postJson($this->url, [...$input, 'position_ids' => [$hidden->id]])->assertUnprocessable()->assertJsonValidationErrors('position_ids');
    $this->assertDatabaseMissing('territories', ['name' => $input['name']]);
    $id = $this->postJson($this->url, [...$input, 'position_ids' => [$position->id]])->assertCreated()->assertJsonPath('data.divisions.0.id', $division->id)->json('data.id');
    $territory = Territory::findOrFail($id);
    $territory->positions()->attach($hidden->id);
    $this->getJson($this->url.'/options/positions')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson($this->url.'/'.$id, ['position_ids' => []])->assertOk()->assertJsonCount(0, 'data.positions');
    expect($territory->positions()->sole()->id)->toBe($hidden->id);
    $organization->update(['metadata' => ['uses_divisions' => false]]);
    $this->patchJson($this->url.'/'.$id, ['description' => 'Updated'])->assertOk()->assertJsonPath('data.divisions.0.id', $division->id);
    $this->patchJson($this->url.'/'.$id, ['division_ids' => []])->assertUnprocessable();
    expect($territory->divisions()->sole()->id)->toBe($division->id);
});

it('checks approval and hierarchy before trashing the entire selection', function () {
    $root = reportingTerritory('Parent');
    $child = reportingTerritory('Child');
    $child->update(['parent_territory_id' => $root->id]);
    $free = reportingTerritory('Free');
    $pending = reportingTerritory('Pending');
    $pending->update(['approval_status' => 'pending']);
    $delete = $this->url.'/register-actions';
    $this->postJson($delete, ['ids' => [$root->id, $free->id]])->assertUnprocessable();
    $this->postJson($delete, ['ids' => [$free->id, $pending->id]])->assertUnprocessable();
    $this->patchJson($this->url.'/'.$pending->id, ['description' => 'Locked'])->assertUnprocessable();
    expect($free->fresh()->trashed())->toBeFalse();
    $this->postJson($delete, ['ids' => [$root->id, $child->id]])->assertOk()->assertJsonPath('data.updated', 2);
    $this->getJson($this->url.'?trash=trashed')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.can_delete', false);
    $this->getJson($this->url.'/'.$root->id)->assertOk()->assertJsonPath('data.trashed', true);
    $this->patchJson($this->url.'/'.$root->id, ['description' => 'Trashed'])->assertNotFound();
});

it('restricts related patch edits by territory, patch visibility and approval', function () {
    $territory = reportingTerritory('Patch Parent');
    $other = reportingTerritory('Other Patch Parent');
    $patch = reportingPatch($territory, 'Visible patch');
    $patch->update(['approval_status' => 'draft']);
    $otherPatch = reportingPatch($other, 'Wrong territory');
    $endpoint = $this->url.'/'.$territory->id.'/patches';
    $this->getJson($endpoint)->assertForbidden();
    Sanctum::actingAs(reportingUser('Hidden Patch Owner'));
    reportingPatch($territory, 'Hidden patch');
    Sanctum::actingAs(grantPagePermissions($this->actor, 'ViewAny:Patch', 'View:Patch', 'Update:Patch')->fresh());
    $this->getJson($endpoint)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_edit', true);
    $this->patchJson($endpoint.'/'.$otherPatch->id, ['description' => 'Wrong territory'])->assertNotFound();
    $this->patchJson($endpoint.'/'.$patch->id, ['territory_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('territory_id');
    $this->patchJson($endpoint.'/'.$patch->id, ['description' => 'Revised', 'color' => '#143456', 'city_pin_code_id' => $this->pin->id])
        ->assertOk()->assertJsonPath('data.description', 'Revised')->assertJsonPath('data.city_pin_code.id', $this->pin->id);
    $territory->update(['approval_status' => 'pending']);
    $this->getJson($endpoint)->assertOk()->assertJsonPath('data.0.can_edit', false);
    $this->patchJson($endpoint.'/'.$patch->id, ['description' => 'Locked'])->assertUnprocessable();
});

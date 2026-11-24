<?php

use App\Http\Middleware\IdentifyTenant;
use App\Jobs\ProcessFieldTravelImport;
use App\Models\AccountMaster;
use App\Models\ApprovalFlow;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\JobRole;
use App\Models\Patch;
use App\Models\Position;
use App\Models\State;
use App\Models\Tenant;
use App\Models\TypeMaster;
use App\Services\DataImport\FieldTravelImport;
use App\Services\DataImport\FieldTravelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = grantPagePermissions(reportingUser('Patch Editor'), 'ViewAny:Patch', 'View:Patch', 'Create:Patch', 'Update:Patch', 'Delete:Patch');
    Sanctum::actingAs($this->actor);
    $country = Country::create(['name' => 'India']);
    $state = State::create(['name' => 'Gujarat', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Ahmedabad', 'state_id' => $state->id, 'country_id' => $country->id]);
    $this->pin = CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Navrangpura', 'pin_code' => 380009]);
    $this->otherPin = CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Paldi', 'pin_code' => 380007]);
    $this->territory = reportingTerritory('West Market');
    $this->territory->cityPinCodes()->attach($this->pin->id);
    $this->url = '/api/v1/patches';
});

function patchWorkbook(array $sheets): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'patch-api-');
    $writer = new Writer;
    $writer->openToFile($path);
    try {
        foreach ($sheets as $sheet => $data) {
            if ($writer->getCurrentSheet()->getName() !== 'Sheet1') {
                $writer->addNewSheetAndMakeItCurrent();
            }
            $writer->getCurrentSheet()->setName($sheet);
            $writer->addRow(Row::fromValues(FieldTravelTemplate::COLUMNS[$sheet]));
            foreach ($data as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
    } finally {
        $writer->close();
    }
    try {
        return UploadedFile::fake()->createWithContent('patches.xlsx', file_get_contents($path));
    } finally {
        unlink($path);
    }
}

function patchCustomer(string $name, int $pin): AccountMaster
{
    $type = TypeMaster::query()->firstOrCreate(['name' => 'Customer', 'typeable_type' => AccountMaster::class]);
    $customer = AccountMaster::create(['name' => $name, 'type_master_id' => $type->id, 'owner_id' => auth()->id()]);
    $customer->addresses()->create(['street' => 'Market Road', 'pin_code' => $pin, 'address_type' => 'Account']);

    return $customer;
}

it('scopes patch search, direct access and destination choices and validates dependent PINs', function () {
    Sanctum::actingAs(reportingUser('Hidden Patch Owner'));
    $hidden = reportingPatch($this->territory, 'Hidden Route');
    Sanctum::actingAs($this->actor);
    $mine = reportingPatch($this->territory, 'My Route');
    $this->getJson($this->url.'?search=Route')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson($this->url.'/'.$hidden->id)->assertNotFound();
    $this->patchJson($this->url.'/'.$hidden->id, ['description' => 'Outside scope'])->assertNotFound();
    $this->getJson($this->url.'/options/territories')->assertOk()->assertJsonCount(0, 'data');
    $input = ['name' => 'New West Route', 'code' => 'WEST', 'territory_id' => $this->territory->id];
    $this->postJson($this->url, $input)->assertUnprocessable()->assertJsonValidationErrors('territory_id');
    Sanctum::actingAs(grantPagePermissions($this->actor, 'AccessAllRecords')->fresh());
    $this->getJson($this->url.'/options/territories/'.$this->territory->id.'/pin-codes?search=Navrangpura')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->pin->id);
    $this->postJson($this->url, [...$input, 'city_pin_code_id' => $this->otherPin->id])->assertUnprocessable()->assertJsonValidationErrors('city_pin_code_id');
    $id = $this->postJson($this->url, [...$input, 'city_pin_code_id' => $this->pin->id])->assertCreated()
        ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.city_pin_code.id', $this->pin->id)->json('data.id');
    $this->getJson($this->url.'?search=West%20Market')->assertOk()->assertJsonCount(3, 'data');
    $this->patchJson($this->url.'/'.$id, ['description' => 'Revised', 'color' => '#233456'])->assertOk()->assertJsonPath('data.description', 'Revised');
});

it('applies customer eligibility, appends sequence and preserves assignments on failed changes', function () {
    Sanctum::actingAs(reportingUser('Hidden Customer Owner'));
    $hidden = patchCustomer('Hidden but eligible', $this->pin->pin_code);
    Sanctum::actingAs($this->actor);
    $role = JobRole::create(['name' => 'Route manager', 'code' => 'ROUTE-MGR', 'level' => 1]);
    $position = Position::create(['name' => 'West manager', 'code' => 'WEST-MGR', 'job_role_id' => $role->id, 'hq_territory_id' => $this->territory->id]);
    $this->actor->employee->positions()->attach($position->id, ['is_primary' => true]);
    Sanctum::actingAs(grantPagePermissions($this->actor, 'ViewOwnTerritory:Patch', 'ViewAny:AccountMaster', 'View:AccountMaster', 'Update:AccountMaster')->fresh());
    $patch = reportingPatch($this->territory, 'Customer Route');
    $first = patchCustomer('Eligible One', $this->pin->pin_code);
    $second = patchCustomer('Eligible Two', $this->pin->pin_code);
    $outside = patchCustomer('Wrong PIN', $this->otherPin->pin_code);
    $base = $this->url.'/'.$patch->id;
    $this->getJson($base.'/customer-options')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson($base.'/customer-actions', ['action' => 'attach', 'ids' => [$hidden->id]])->assertUnprocessable();
    $this->postJson($base.'/customer-actions', ['action' => 'attach', 'ids' => [$first->id, $outside->id]])->assertUnprocessable();
    $this->assertDatabaseCount('account_master_patch', 0);
    $this->postJson($base.'/customer-actions', ['action' => 'attach', 'ids' => [$first->id]])->assertOk()->assertJsonPath('data.updated', 1);
    $this->postJson($base.'/customer-actions', ['action' => 'attach_all'])->assertOk()->assertJsonPath('data.updated', 1);
    $this->postJson($base.'/customer-actions', ['action' => 'attach_all'])->assertOk()->assertJsonPath('data.updated', 0);
    $this->getJson($base.'/customers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.sequence_no', 1)
        ->assertJsonPath('data.1.sequence_no', 2);
    $this->getJson($base.'/customer-types')->assertOk()->assertJsonPath('data.0.label', 'Customer');
    $this->assertDatabaseHas('account_master_patch', ['patch_id' => $patch->id, 'account_master_id' => $first->id, 'created_by' => $this->actor->id]);
    $this->postJson($base.'/customer-actions', ['action' => 'sequence', 'ids' => [$first->id], 'sequence_no' => 2])->assertUnprocessable();
    $this->postJson($base.'/customer-actions', ['action' => 'sequence', 'ids' => [$second->id], 'sequence_no' => 12])->assertOk();
    $this->postJson($base.'/customer-actions', ['action' => 'detach', 'ids' => [$first->id, $outside->id]])->assertUnprocessable();
    $this->assertDatabaseCount('account_master_patch', 2);
    $this->postJson($base.'/customer-actions', ['action' => 'detach', 'ids' => [$first->id, $second->id]])->assertOk()->assertJsonPath('data.updated', 2);
    $this->assertDatabaseCount('account_master_patch', 0);
});

it('protects approved and linked patches and stamps the actor on an atomic trash action', function () {
    Sanctum::actingAs(grantPagePermissions($this->actor, 'AccessAllRecords')->fresh());
    $free = reportingPatch($this->territory, 'Free Route');
    $linked = reportingPatch($this->territory, 'Assigned Route');
    $linked->companies()->attach(patchCustomer('Linked Customer', $this->pin->pin_code)->id);
    $locked = reportingPatch($this->territory, 'Pending Route');
    $flow = ApprovalFlow::create(['module' => 'SalesTourPlan', 'active' => false]);
    $locked->approval()->create(['approval_flow_id' => $flow->id, 'requested_by' => $this->actor->id, 'module' => 'Patch',
        'record_type' => Patch::class, 'record_id' => $locked->id, 'approval_status' => 'pending']);
    $this->patchJson($this->url.'/'.$locked->id, ['description' => 'Locked'])->assertUnprocessable();
    $this->postJson($this->url.'/register-actions', ['action' => 'trash', 'ids' => [$free->id, $locked->id]])->assertUnprocessable();
    $this->postJson($this->url.'/register-actions', ['action' => 'trash', 'ids' => [$free->id, $linked->id]])->assertUnprocessable();
    expect($free->fresh()->trashed())->toBeFalse();
    $this->postJson($this->url.'/register-actions', ['action' => 'trash', 'ids' => [$free->id]])->assertOk();
    $this->getJson($this->url.'?trash=trashed')->assertOk()->assertJsonPath('data.0.id', $free->id)->assertJsonPath('data.0.can_edit', false);
    $this->assertDatabaseHas('patches', ['id' => $free->id, 'deleted_by' => $this->actor->id]);
});

it('previews, queues and resumes workbook imports with company and actor isolation', function () {
    $this->post($this->url.'/import/preview', ['file' => patchWorkbook(['Patches' => [['NEW', 'Imported Route', $this->territory->code]]])], ['Accept' => 'application/json'])->assertForbidden();
    config(['cache.stores.file' => ['driver' => 'array']]);
    $this->withoutMiddleware(IdentifyTenant::class);
    Storage::fake('local');
    Queue::fake();
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 9001]));
    $role = Role::findOrCreate('administration_admin', 'tenant');
    foreach (['ViewAny:Patch', 'View:Patch', 'Create:Patch', 'Update:Patch'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'tenant'));
    }
    $this->actor->fresh()->assignRole($role);
    Sanctum::actingAs($this->actor->fresh());
    $template = $this->getJson($this->url.'/import/template')->assertOk()->assertDownload('patches.xlsx');
    expect($template->streamedContent())->toStartWith('PK');
    $this->post($this->url.'/import/preview', [
        'file' => patchWorkbook(['Standard Fares' => [[$this->territory->code, '', 'Ahmedabad', 'Navrangpura', '380009', 'Ahmedabad', 'Paldi', '380007', '5', '', '', '1']]]),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $preview = $this->post($this->url.'/import/preview', [
        'file' => patchWorkbook(['Patches' => [['NEW', 'Imported Route', $this->territory->code]]]),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'preview')
        ->assertJsonPath('data.plans.0.action', 'Create')->json('data.token');
    $this->assertDatabaseMissing('patches', ['code' => 'NEW']);
    $endpoint = $this->url.'/import/'.$preview;
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 9002]));
    $this->getJson($endpoint)->assertNotFound();
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 9001]));
    $other = reportingUser('Other Import Administrator')->assignRole($role);
    Sanctum::actingAs($other);
    $this->getJson($endpoint)->assertNotFound();
    Sanctum::actingAs($this->actor->fresh());
    $this->postJson($endpoint)->assertOk()->assertJsonPath('data.status', 'queued');
    $this->postJson($endpoint)->assertOk()->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(ProcessFieldTravelImport::class, 1);
    $job = Queue::pushed(ProcessFieldTravelImport::class)->sole();
    $job->handle(app(FieldTravelImport::class));
    $this->getJson($this->url.'/import/latest')->assertOk()->assertJsonPath('data.status', 'complete')->assertJsonPath('data.result.Create', 1);
    $this->assertDatabaseHas('patches', ['code' => 'NEW', 'created_by' => $this->actor->id]);

    $updated = $this->post($this->url.'/import/preview', [
        'file' => patchWorkbook(['Patches' => [['NEW', 'Revised Route', $this->territory->code]]]),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.plans.0.action', 'Update')->json('data.token');
    Patch::where('code', 'NEW')->sole()->update(['description' => 'Updated after preview']);
    $this->postJson($this->url.'/import/'.$updated)->assertOk()->assertJsonPath('data.status', 'queued');
    Queue::pushed(ProcessFieldTravelImport::class)->last()->handle(app(FieldTravelImport::class));
    $this->getJson($this->url.'/import/'.$updated)->assertOk()->assertJsonPath('data.status', 'failed');
    $this->assertDatabaseHas('patches', ['code' => 'NEW', 'name' => 'Imported Route', 'description' => 'Updated after preview']);
    app()->forgetInstance('currentTenant');
});

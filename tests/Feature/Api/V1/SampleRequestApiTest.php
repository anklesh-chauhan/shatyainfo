<?php

use App\Enums\SampleRequestStatus;
use App\Filament\Resources\SampleIssues\Pages\CreateSampleIssue;
use App\Filament\Resources\SampleRequests\Pages\CreateSampleRequest;
use App\Filament\Resources\SampleRequests\Pages\EditSampleRequest;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Services\Inventory\InventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Sample requester');
    $this->outside = reportingUser('Other requester');
    $permissions = [
        'ViewAny:SampleRequest', 'View:SampleRequest', 'Create:SampleRequest', 'Update:SampleRequest',
        'Delete:SampleRequest', 'DeleteAny:SampleRequest', 'Restore:SampleRequest', 'RestoreAny:SampleRequest',
        'ForceDelete:SampleRequest', 'ForceDeleteAny:SampleRequest', 'ViewAny:SampleIssue', 'Create:SampleIssue',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo($permissions);
    $this->outside->givePermissionTo(['ViewAny:SampleRequest', 'View:SampleRequest']);
    Sanctum::actingAs($this->outside);
    $this->hidden = reportingLocation('Outside destination');
    $this->hiddenRequest = SampleRequest::factory()->create([
        'location_master_id' => $this->hidden->id, 'employee_id' => $this->outside->employee->id,
    ]);
    Sanctum::actingAs($this->actor);
    $this->destination = reportingLocation('Representative destination');
    $this->item = reportingSampleItem('SREQ', 'Requested sample');
    $this->path = '/api/v1/sample-requests';
    $this->payload = [
        'employee_id' => $this->actor->employee->id,
        'location_master_id' => $this->destination->id,
        'request_date' => today()->toDateString(),
        'purpose' => 'Upcoming doctor visits',
        'lines' => [['item_master_id' => $this->item->id, 'quantity_requested' => '5.250']],
    ];
});

it('negotiates navigation and uses the same scope for the register and draft choices', function () {
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g06-05', 'screen_key' => 'sample-requests']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('can_create', true);
    $this->getJson($this->path.'/options/employees')->assertOk()
        ->assertJsonPath('data.0.id', $this->actor->employee->id)->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/options/locations')->assertOk()
        ->assertJsonPath('data.0.id', $this->destination->id)->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/'.$this->hiddenRequest->id)->assertNotFound();
    $this->postJson($this->path, [...$this->payload, 'location_master_id' => $this->hidden->id])
        ->assertUnprocessable()->assertJsonValidationErrors('location_master_id');
    $this->postJson($this->path, [...$this->payload, 'employee_id' => $this->outside->employee->id])
        ->assertUnprocessable()->assertJsonValidationErrors('employee_id');

    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $id = $this->postJson($this->path, $this->payload, $headers)->assertCreated()->json('data.id');
    $this->postJson($this->path, $this->payload, $headers)->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->getJson($this->path.'?search=doctor&status=draft')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
});

it('validates transitions and approves each line atomically against the reviewed revision', function () {
    $id = $this->postJson($this->path, [...$this->payload, 'lines' => []])->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $empty = $this->getJson($path)->assertJsonPath('data.status', 'draft')->json('data');
    $this->postJson($path.'/submit', ['revision' => $empty['revision']])
        ->assertUnprocessable()->assertJsonValidationErrors('lines');

    $draft = $this->patchJson($path, [...$this->payload, 'revision' => $empty['revision']])->assertOk()->json('data');
    $this->postJson($path.'/submit', ['revision' => $empty['revision']])->assertConflict();
    $this->postJson($path.'/submit', ['revision' => $draft['revision']])->assertOk();
    $submitted = $this->getJson($path)->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.issue_url', null)->json('data');
    $this->patchJson($path, [...$this->payload, 'revision' => $submitted['revision']])->assertConflict();

    $payload = ['revision' => $submitted['revision']];
    $lineId = $submitted['lines'][0]['id'];
    $this->postJson($path.'/approve', [...$payload, 'approved' => [$lineId => '5.251']])
        ->assertUnprocessable()->assertJsonValidationErrors('approved');
    $this->postJson($path.'/approve', [...$payload, 'approved' => [$lineId + 1 => '3.500']])
        ->assertUnprocessable()->assertJsonValidationErrors('approved');
    $this->postJson($path.'/approve', [...$payload, 'approved' => [$lineId => '3.500']])->assertOk();

    $approved = $this->getJson($path)->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.lines.0.quantity_approved', '3.500')
        ->assertJsonPath('data.lines.0.quantity_issued', '0.000')->json('data');
    expect($approved['issue_url'])->toContain('/admin/sample-issues/create?sample_request_id='.$id);

    $this->actor->revokePermissionTo('ViewAny:SampleIssue');
    $this->getJson($path)->assertJsonPath('data.issue_url', null);
    $this->actor->givePermissionTo('ViewAny:SampleIssue')->revokePermissionTo('Create:SampleIssue');
    $this->getJson($path)->assertJsonPath('data.issue_url', null);
    $this->postJson($path.'/delete', ['revision' => $approved['revision']])->assertConflict();
    $this->postJson($path.'/cancel', ['revision' => $approved['revision']])->assertOk();
    $this->getJson($path)->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.issue_url', null);
});

it('keeps record permissions separate from bulk permissions and rolls back a mixed bulk selection', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $submitted = SampleRequest::factory()->create([
        'employee_id' => $this->actor->employee->id, 'location_master_id' => $this->destination->id,
        'status' => SampleRequestStatus::Submitted,
    ]);
    $this->postJson($this->path.'/records/delete', ['ids' => [$id, $submitted->id]])->assertConflict();
    expect(SampleRequest::find($id))->not->toBeNull();

    $this->actor->revokePermissionTo('DeleteAny:SampleRequest');
    $this->postJson($this->path.'/records/delete', ['ids' => [$id]])->assertForbidden();
    $path = $this->path.'/'.$id;
    $draft = $this->getJson($path)->json('data');
    $this->postJson($path.'/delete', ['revision' => $draft['revision']])->assertOk();
    $this->getJson($this->path.'?trash=trashed')->assertJsonPath('data.0.id', $id);
    $this->postJson($this->path.'/records/restore', ['ids' => [$id]])->assertOk();
    expect(SampleRequest::find($id))->not->toBeNull();

    Sanctum::actingAs($this->outside);
    $visible = $this->getJson($this->path.'/'.$this->hiddenRequest->id)->assertOk()->json('data');
    $this->postJson($this->path.'/'.$this->hiddenRequest->id.'/submit', ['revision' => $visible['revision']])->assertForbidden();
});

it('keeps the Admin draft editor and approval action on the same request workflow', function () {
    $this->withoutExceptionHandling();
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateSampleRequest::class)->assertSuccessful()
        ->fillForm($this->payload)->call('create')->assertHasNoFormErrors();
    $request = SampleRequest::query()->where('purpose', $this->payload['purpose'])->sole();
    $line = $request->lines()->sole();

    Livewire::test(EditSampleRequest::class, ['record' => $request->id])
        ->callAction('submit')->assertNotified('Sample request updated');
    Livewire::test(EditSampleRequest::class, ['record' => $request->id])
        ->callAction('approve', data: ['approved' => [$line->id => '4.000']])
        ->assertNotified('Sample request updated');
    expect($request->refresh()->status)->toBe(SampleRequestStatus::Approved)
        ->and($line->refresh()->quantity_approved)->toBe('4.000');

    $source = reportingLocation('Admin warehouse');
    Livewire::withQueryParams(['sample_request_id' => $request->id])->test(CreateSampleIssue::class)
        ->assertSuccessful()
        ->assertSet('data.sample_request_id', $request->id)
        ->assertSet('data.to_location_id', $this->destination->id)
        ->fillForm(['from_location_id' => $source->id])->call('create')->assertHasNoFormErrors();
    $issue = $request->issues()->sole();
    expect($issue->lines()->sole()->quantity)->toBe('4.000');
});

it('enforces the request at issue posting and handles repeated posting with stale issue instances', function () {
    $source = reportingLocation('Warehouse');
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $request = SampleRequest::findOrFail($id);
    $line = $request->lines()->sole();
    $line->update(['quantity_approved' => '5.250']);
    $issue = SampleIssue::factory()->create([
        'sample_request_id' => $id, 'from_location_id' => $source->id, 'to_location_id' => $this->destination->id,
    ]);
    $issue->lines()->create([
        'sample_request_line_id' => $line->id, 'item_master_id' => $this->item->id, 'quantity' => '2.000',
    ]);
    InventoryStock::query()->create([
        'item_master_id' => $this->item->id, 'location_master_id' => $source->id, 'quantity_on_hand' => '10.000',
    ]);

    $service = app(InventoryService::class);
    expect(fn () => $service->postSampleIssue($issue))->toThrow(RuntimeException::class, 'Only an approved or partially issued sample request can be posted.');
    $request->update(['status' => SampleRequestStatus::Approved]);
    $stale = $issue->fresh();
    $service->postSampleIssue($issue);
    $service->postSampleIssue($stale);

    expect($stale->isPosted())->toBeTrue()
        ->and($line->refresh()->quantity_issued)->toBe('2.000')
        ->and($request->refresh()->status)->toBe(SampleRequestStatus::PartiallyIssued)
        ->and(InventoryMovement::query()->count())->toBe(2);
    $path = $this->path.'/'.$id;
    $detail = $this->getJson($path)->assertOk()->json('data');
    $this->postJson($path.'/cancel', ['revision' => $detail['revision']])->assertConflict();
});

<?php

use App\Enums\SampleIssueStatus;
use App\Enums\SampleRequestStatus;
use App\Filament\Resources\SampleIssues\Pages\CreateSampleIssue;
use App\Filament\Resources\SampleIssues\Pages\EditSampleIssue;
use App\Filament\Resources\SampleIssues\Pages\ListSampleIssues;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Services\Inventory\InventoryService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Sample issuer');
    $this->outside = reportingUser('Other issuer');
    $permissions = ['ViewAny:SampleRequest', 'View:SampleRequest'];
    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny'] as $ability) {
        $permissions[] = $ability.':SampleIssue';
    }
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo($permissions);
    $this->outside->givePermissionTo($permissions);
    Sanctum::actingAs($this->actor);
    $this->source = reportingLocation('Warehouse');
    $this->destination = reportingLocation('Representative bag');
    $this->item = reportingSampleItem('ISSUE', 'Sample pack');
    $this->sampleRequest = SampleRequest::factory()->create([
        'employee_id' => $this->actor->employee->id, 'location_master_id' => $this->destination->id,
        'status' => SampleRequestStatus::Approved,
    ]);
    $this->requestLine = $this->sampleRequest->lines()->create([
        'item_master_id' => $this->item->id, 'quantity_requested' => '5.250', 'quantity_approved' => '5.250',
    ]);
    InventoryStock::query()->create([
        'item_master_id' => $this->item->id, 'location_master_id' => $this->source->id, 'quantity_on_hand' => '10.000',
    ]);
    $this->path = '/api/v1/sample-issues';
    $this->payload = [
        'sample_request_id' => $this->sampleRequest->id, 'from_location_id' => $this->source->id,
        'issue_date' => today()->toDateString(), 'notes' => 'Doctor visit supply',
        'lines' => [[
            'sample_request_line_id' => $this->requestLine->id, 'item_master_id' => $this->item->id,
            'quantity' => '2.125', 'unit_cost' => '1.2500',
        ]],
    ];
});

it('negotiates the scoped issue register and creates request-linked drafts idempotently', function () {
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g06-06', 'screen_key' => 'sample-issues']);
    $this->getJson($this->path.'/options/requests')->assertOk()->assertJsonPath('data.0.id', $this->sampleRequest->id);
    $this->getJson($this->path.'/requests/'.$this->sampleRequest->id)->assertOk()
        ->assertJsonPath('data.lines.0.sample_request_line_id', $this->requestLine->id);
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $id = $this->postJson($this->path, $this->payload, $headers)->assertCreated()->json('data.id');
    $this->postJson($this->path, $this->payload, $headers)->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->getJson($this->path.'?search=Doctor&status=draft')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/'.$id)->assertOk()->assertJsonPath('data.to_location_id', $this->destination->id);
    Sanctum::actingAs($this->outside);
    $this->getJson($this->path)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/'.$id)->assertNotFound();
    $this->getJson($this->path.'/requests/'.$this->sampleRequest->id)->assertNotFound();
    $this->getJson($this->path.'/options/locations')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson($this->path, $this->payload)->assertNotFound();
});

it('rejects stale issue edits and validates line ownership and aggregate approvals', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $detail = $this->getJson($path)->json('data');
    $saved = $this->patchJson($path, [...$this->payload, 'revision' => $detail['revision'], 'notes' => 'Revised'])
        ->assertOk()->json('data');
    $this->patchJson($path, [...$this->payload, 'revision' => $detail['revision']])->assertConflict();
    $this->postJson($path.'/post', ['revision' => $detail['revision']])->assertConflict();
    $this->patchJson($path, [...$this->payload, 'revision' => $saved['revision'], 'lines' => [
        [...$this->payload['lines'][0], 'id' => 999999],
    ]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.id');
    $this->postJson($this->path, [...$this->payload, 'lines' => [
        [...$this->payload['lines'][0], 'quantity' => '3.000'],
        [...$this->payload['lines'][0], 'quantity' => '3.000'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('lines.1.quantity');
    foreach (['item_master_id' => 999999, 'quantity' => '1.0001'] as $field => $value) {
        $this->postJson($this->path, [...$this->payload, 'lines' => [[...$this->payload['lines'][0], $field => $value]]])->assertUnprocessable();
    }
    $this->postJson($this->path, [...$this->payload, 'from_location_id' => $this->destination->id])
        ->assertUnprocessable()->assertJsonValidationErrors('from_location_id');
});

it('posts partial and remaining approvals once and retains posted issues', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $revision = $this->getJson($path)->json('data.revision');
    $stale = SampleIssue::findOrFail($id);
    $this->postJson($path.'/post', ['revision' => $revision])->assertOk();
    app(InventoryService::class)->postSampleIssue($stale, $this->actor->id);
    expect(InventoryMovement::query()->count())->toBe(2)
        ->and($this->requestLine->refresh()->quantity_issued)->toBe('2.125')
        ->and($this->sampleRequest->refresh()->status)->toBe(SampleRequestStatus::PartiallyIssued);
    $posted = $this->getJson($path)->assertJsonPath('data.can_edit', false)->assertJsonPath('data.actions', [])
        ->assertJsonPath('data.issued_by', $this->actor->id)->json('data');
    foreach (['post', 'delete', 'force-delete', 'restore'] as $action) {
        $response = $this->postJson($path.'/'.$action, ['revision' => $posted['revision']]);
        $action === 'post' ? $response->assertConflict() : $response->assertForbidden();
    }
    expect($stale->refresh()->delete())->toBeFalse();
    $remaining = [...$this->payload, 'lines' => [[...$this->payload['lines'][0], 'quantity' => '3.125']]];
    $next = $this->postJson($this->path, $remaining)->assertCreated()->json('data.id');
    $nextPath = $this->path.'/'.$next;
    $this->postJson($nextPath.'/post', ['revision' => $this->getJson($nextPath)->json('data.revision')])->assertOk();
    expect($this->sampleRequest->refresh()->status)->toBe(SampleRequestStatus::Fulfilled)
        ->and(InventoryMovement::query()->count())->toBe(4);
});

it('rolls back all movements and approval usage on insufficient stock', function () {
    $this->requestLine->update(['quantity_approved' => '20.000', 'quantity_requested' => '20.000']);
    $id = $this->postJson($this->path, [...$this->payload, 'lines' => [
        $this->payload['lines'][0], [...$this->payload['lines'][0], 'quantity' => '9.000'],
    ]])->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->postJson($path.'/post', ['revision' => $this->getJson($path)->json('data.revision')])
        ->assertUnprocessable()->assertJsonValidationErrors('stock');
    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($this->requestLine->refresh()->quantity_issued)->toBe('0.000')
        ->and(SampleIssue::findOrFail($id)->isPosted())->toBeFalse()
        ->and((float) InventoryStock::query()->where('location_master_id', $this->source->id)->value('quantity_on_hand'))->toBe(10.0);
});

it('rechecks request approval and location availability at issue posting', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $revision = $this->getJson($path)->json('data.revision');
    $this->source->update(['is_active' => false]);
    $this->postJson($path.'/post', ['revision' => $revision])->assertUnprocessable();
    $this->source->update(['is_active' => true]);
    $this->sampleRequest->update(['status' => SampleRequestStatus::Cancelled]);
    $this->postJson($path.'/post', ['revision' => $revision])->assertNotFound();
    expect(InventoryMovement::query()->count())->toBe(0);
});

it('keeps issue trash permissions separate and mixed bulk selections atomic', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $posted = SampleIssue::factory()->create([
        'sample_request_id' => $this->sampleRequest->id, 'from_location_id' => $this->source->id,
        'to_location_id' => $this->destination->id, 'status' => SampleIssueStatus::Posted, 'posted_at' => now(),
    ]);
    $this->postJson($this->path.'/records/delete', ['ids' => [$id, $posted->id]])->assertForbidden();
    expect(SampleIssue::find($id))->not->toBeNull();
    $this->actor->revokePermissionTo('DeleteAny:SampleIssue');
    $this->postJson($this->path.'/records/delete', ['ids' => [$id]])->assertForbidden();
    $path = $this->path.'/'.$id;
    $this->postJson($path.'/delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->getJson($this->path.'?trash=trashed')->assertJsonPath('data.0.id', $id);
    $this->postJson($this->path.'/records/restore', ['ids' => [$id]])->assertOk();
    $this->postJson($path.'/delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->postJson($path.'/force-delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->getJson($path)->assertNotFound();
});

it('enforces revoked issue permissions without granting actions through scope bypass', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $revision = $this->getJson($path)->json('data.revision');
    $this->actor->revokePermissionTo('Update:SampleIssue');
    $this->postJson($path.'/post', ['revision' => $revision])->assertForbidden();
    $this->patchJson($path, [...$this->payload, 'revision' => $revision])->assertForbidden();
    $this->actor->revokePermissionTo('View:SampleRequest');
    $this->getJson($this->path.'/options/requests')->assertJsonCount(0, 'data');
    $this->actor->revokePermissionTo('ViewAny:SampleIssue');
    Permission::findOrCreate('AccessAllRecords', 'web');
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($path)->assertForbidden();
    $this->postJson($this->path, $this->payload)->assertForbidden();
});

it('uses shared issue saves posting and stale revision checks in Admin', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::withQueryParams(['sample_request_id' => $this->sampleRequest->id])->test(CreateSampleIssue::class)
        ->assertSuccessful()->fillForm(['from_location_id' => $this->source->id])->call('create')->assertHasNoFormErrors();
    $issue = SampleIssue::query()->sole();
    $editor = Livewire::test(EditSampleIssue::class, ['record' => $issue->id])
        ->fillForm(['notes' => 'Admin draft revision'])->call('save')->assertHasNoFormErrors();
    expect($issue->refresh()->notes)->toBe('Admin draft revision');
    $issue->update(['notes' => 'Changed elsewhere']);
    $editor->callAction('post')->assertNotified('The issue changed. Reload it before continuing.');
    Livewire::test(EditSampleIssue::class, ['record' => $issue->id])->callAction('post')->assertNotified('Sample issue updated')
        ->assertSet('data.sample_request_id', $this->sampleRequest->id);
    expect($issue->refresh()->isPosted())->toBeTrue();
    Livewire::test(ListSampleIssues::class)->selectTableRecords([$issue])->callAction(TestAction::make('delete')->table()->bulk())
        ->assertForbidden();
    expect($issue->refresh()->trashed())->toBeFalse();
});

it('prevents two outstanding issue drafts from consuming the same remaining approval', function () {
    $payload = [...$this->payload, 'lines' => [[...$this->payload['lines'][0], 'quantity' => '4.000']]];
    $first = $this->postJson($this->path, $payload)->assertCreated()->json('data.id');
    $second = $this->postJson($this->path, $payload)->assertCreated()->json('data.id');
    $firstPath = $this->path.'/'.$first;
    $secondPath = $this->path.'/'.$second;
    $firstRevision = $this->getJson($firstPath)->json('data.revision');
    $secondRevision = $this->getJson($secondPath)->json('data.revision');
    $this->postJson($firstPath.'/post', ['revision' => $firstRevision])->assertOk();
    $this->postJson($secondPath.'/post', ['revision' => $secondRevision])->assertUnprocessable()->assertJsonValidationErrors('stock');
    expect(InventoryMovement::query()->count())->toBe(2)
        ->and($this->requestLine->refresh()->quantity_issued)->toBe('4.000')
        ->and(SampleIssue::findOrFail($second)->isPosted())->toBeFalse();
});

it('rejects a hidden warehouse even when the approved request is visible', function () {
    Sanctum::actingAs($this->outside);
    $hidden = reportingLocation('Private warehouse');
    Sanctum::actingAs($this->actor);
    $this->postJson($this->path, [...$this->payload, 'from_location_id' => $hidden->id])
        ->assertUnprocessable()->assertJsonValidationErrors('from_location_id');
});

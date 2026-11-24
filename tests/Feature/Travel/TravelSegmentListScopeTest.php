<?php

use App\Filament\Resources\TravelSegments\Pages\ListTravelSegments;
use App\Models\SalesDcr;
use App\Models\TravelSegment;
use App\Models\Visit;
use App\Services\Travel\TravelSegmentValidation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('hides travel segments when their parent report is outside the users scope', function () {
    $owner = reportingUser('TravelSegmentOwner');
    $viewer = reportingUser('TravelSegmentViewer');
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:TravelSegment', 'web'));

    $dcr = SalesDcr::query()->create([
        'user_id' => $owner->id,
        'dcr_date' => today()->toDateString(),
        'approval_status' => 'draft',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $segment = TravelSegment::query()->create([
        'sales_dcr_id' => $dcr->id,
        'distance_km' => 12,
        'distance_source' => 'manual',
        'is_auto_generated' => false,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    expect($viewer->can('AccessAllRecords'))->toBeFalse()
        ->and($viewer->hasAnyRole(['super_admin', 'administration_admin']))->toBeFalse()
        ->and($dcr->user_id)->toBe($owner->id)
        ->and($segment->sales_dcr_id)->toBe($dcr->id);

    $this->actingAs($viewer, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListTravelSegments::class)
        ->assertCanNotSeeTableRecords([$segment]);

    $viewer->givePermissionTo(Permission::findOrCreate('AccessAllRecords', 'web'));
    $this->actingAs($viewer->fresh(), 'web');
    Livewire::test(ListTravelSegments::class)->assertCanSeeTableRecords([$segment]);
});

it('rejects an unrelated customer in travel without a patch', function () {
    $owner = reportingUser('Travel Reporter');
    $other = reportingUser('Other Travel Customer Owner');
    $customer = reportingDoctor($other, 'Outside Travel Customer');
    $report = SalesDcr::forceCreate(['user_id' => $owner->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->actingAs($owner, 'web');

    expect(fn () => app(TravelSegmentValidation::class)->validate([
        'sales_dcr_id' => $report->id,
        'from_account_id' => $customer->id,
    ]))->toThrow(ValidationException::class);
});

it('qualifies the mobile register and selectors through the parent report and saves manual distance once', function () {
    $actor = grantPagePermissions(reportingUser('Mobile Travel Reporter'),
        'ViewAny:TravelSegment', 'View:TravelSegment', 'Create:TravelSegment', 'Update:TravelSegment', 'ViewOwn:AccountMaster');
    $other = reportingUser('Other Mobile Travel Reporter');
    $territory = reportingTerritory('Mobile travel territory');
    $patch = reportingPatch($territory, 'Mobile travel patch');
    $customer = reportingDoctor($actor, 'Scoped Start Customer');
    $outsideCustomer = reportingDoctor($other, 'Outside Mobile Travel Customer');
    $patch->companies()->attach($customer->id);
    $patch->companies()->attach($outsideCustomer->id);
    $report = SalesDcr::forceCreate([
        'user_id' => $actor->id, 'created_by' => $actor->id,
        'dcr_date' => today(), 'approval_status' => 'draft', 'territory_id' => $territory->id,
    ]);
    $outside = SalesDcr::forceCreate(['user_id' => $other->id, 'created_by' => $other->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $hidden = TravelSegment::create(['sales_dcr_id' => $outside->id, 'distance_km' => 20]);
    $visit = Visit::withoutEvents(fn (): Visit => Visit::forceCreate([
        'document_number' => 'VIS-TRAVEL-MOBILE', 'sales_dcr_id' => $report->id,
        'employee_id' => $actor->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'visit_date' => today(),
    ]));

    $base = '/api/v1/travel-segments';
    Sanctum::actingAs($actor);
    $this->getJson($base)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.can_create', true);
    $this->getJson($base.'/'.$hidden->id)->assertNotFound();
    $this->getJson($base.'/options?kind=reports&search='.$report->id)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $report->id);
    $this->getJson($base.'/options?kind=visits&sales_dcr_id='.$report->id)->assertOk()->assertJsonPath('data.0.id', $visit->id);
    $this->getJson($base.'/options?kind=accounts&sales_dcr_id='.$report->id.'&patch_id='.$patch->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->id);
    $this->getJson($base.'/options?kind=accounts&sales_dcr_id='.$outside->id)->assertNotFound();

    $input = ['sales_dcr_id' => $report->id, 'visit_id' => $visit->id, 'patch_id' => $patch->id, 'from_account_id' => $customer->id, 'distance_km' => '12.50'];
    $this->postJson($base, [...$input, 'from_account_id' => $outsideCustomer->id])->assertUnprocessable()->assertJsonValidationErrors('from_account_id');
    $this->postJson($base, [...$input, 'is_auto_generated' => true])->assertUnprocessable();
    $this->postJson($base, [...$input, 'sales_dcr_id' => $outside->id])->assertNotFound();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $saved = $this->postJson($base, $input, $headers)->assertCreated()
        ->assertJsonPath('data.distance_source', 'manual')->assertJsonPath('data.origin', 'manual')->json('data');
    $this->postJson($base, $input, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $saved['id']);
    expect(TravelSegment::query()->where('sales_dcr_id', $report->id)->count())->toBe(1);
    $this->getJson($base.'?origin=manual&search=Scoped%20Start')->assertOk()->assertJsonCount(1, 'data');
    $edit = [...$input, 'revision' => $saved['revision'], 'distance_km' => '13.25'];
    $this->patchJson($base.'/'.$saved['id'], $edit)->assertOk()->assertJsonPath('data.distance_km', 13.25);
    $this->patchJson($base.'/'.$saved['id'], $edit)->assertConflict();
    $actor->revokePermissionTo('Create:TravelSegment');
    Sanctum::actingAs($actor->fresh());
    $this->postJson($base, $input, $headers)->assertForbidden();
});

it('validates the full travel selection before changing trash state and protects generated and imported rows', function () {
    $actor = grantPagePermissions(reportingUser('Travel Lifecycle'),
        'ViewAny:TravelSegment', 'View:TravelSegment', 'Update:TravelSegment', 'Delete:TravelSegment',
        'Restore:TravelSegment', 'ForceDelete:TravelSegment');
    $report = SalesDcr::forceCreate(['user_id' => $actor->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $locked = SalesDcr::forceCreate(['user_id' => $actor->id, 'dcr_date' => today()->subDay(), 'approval_status' => 'submitted']);
    $make = fn (array $data): TravelSegment => TravelSegment::create([
        'sales_dcr_id' => $report->id, 'distance_km' => 5, 'distance_source' => 'manual',
        'is_auto_generated' => false, ...$data,
    ]);
    $manual = $make([]);
    $second = $make([]);
    $generated = $make(['is_auto_generated' => true, 'gps_distance_km' => 5]);
    $imported = $make(['import_reference' => 'TEST-TRAVEL-IMPORT']);
    $closed = $make(['sales_dcr_id' => $locked->id]);
    Sanctum::actingAs($actor);
    $base = '/api/v1/travel-segments';
    $run = fn (string $action, array $ids) => $this->postJson($base.'/lifecycle', compact('action', 'ids'));
    foreach ([$generated, $imported, $closed] as $protected) {
        $row = $this->getJson($base.'/'.$protected->id)->assertOk()
            ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.actions', [])->json('data');
        $this->patchJson($base.'/'.$protected->id, ['sales_dcr_id' => $report->id, 'distance_km' => 6, 'revision' => $row['revision']])->assertUnprocessable();
        $run('delete', [$manual->id, $protected->id])->assertUnprocessable();
        $this->assertNotSoftDeleted('travel_segments', ['id' => $manual->id]);
    }
    $run('delete', [$manual->id, $second->id])->assertOk()->assertJsonPath('data.updated', 2);
    $this->getJson($base.'?trash=only')->assertOk()->assertJsonCount(2, 'data');
    $run('restore', [$manual->id, $second->id])->assertForbidden();
    $run('restore', [$manual->id])->assertOk();
    $run('forceDelete', [$second->id])->assertOk();
    $this->assertDatabaseMissing('travel_segments', ['id' => $second->id]);
});

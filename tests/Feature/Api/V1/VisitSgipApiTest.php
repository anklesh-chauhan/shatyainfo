<?php

use App\Models\AccountMaster;
use App\Models\SgipDistribution;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('SgipApiRep');
    Sanctum::actingAs($this->rep);
    $territory = reportingTerritory('Sgip territory');
    $patch = reportingPatch($territory, 'Sgip patch');
    $doctor = reportingDoctor($this->rep, 'Doctor');
    $this->item = reportingSampleItem('SAMPLE', 'Sample');
    $this->visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'SGIP-'.Str::uuid(), 'employee_id' => $this->rep->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id, 'visit_date' => today(),
        'visit_type' => 'unplanned', 'visit_status' => 'draft', 'approval_status' => 'draft',
        'created_by' => $this->rep->id, 'updated_by' => $this->rep->id,
    ]));
    $this->visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);
});

it('saves an offline SGIP draft once with server version information', function () {
    $path = '/api/v1/visits/'.$this->visit->id.'/sgip';
    $payload = ['expected_updated_at' => null, 'items' => [['item_master_id' => $this->item->id, 'quantity' => 2]]];
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $first = $this->putJson($path, $payload, $headers)->assertSuccessful()->assertJsonStructure(['data' => ['updated_at', 'items']]);
    $this->putJson($path, $payload, $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(SgipDistribution::query()->where('visit_id', $this->visit->id)->count())->toBe(1)
        ->and($first->json('data.items.0.quantity'))->toEqual(2);
});

it('rejects a stale offline SGIP edit without replacing sample items', function () {
    $path = '/api/v1/visits/'.$this->visit->id.'/sgip';
    $payload = ['expected_updated_at' => null, 'items' => [['item_master_id' => $this->item->id, 'quantity' => 2]]];
    $this->putJson($path, $payload)->assertSuccessful();
    $payload['items'][0]['quantity'] = 8;
    $this->putJson($path, $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    $this->getJson($path)->assertSuccessful()->assertJsonPath('data.items.0.quantity', 2);
});

it('rejects another users SGIP access', function () {
    Sanctum::actingAs(reportingUser('SgipOther'));
    $this->getJson('/api/v1/visits/'.$this->visit->id.'/sgip')->assertNotFound();
});

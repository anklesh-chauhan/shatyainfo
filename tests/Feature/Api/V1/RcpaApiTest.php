<?php

use App\Filament\Resources\RcpaCompetitors\Pages\ManageRcpaCompetitors;
use App\Filament\Resources\RcpaObservations\Pages\ManageRcpaObservations;
use App\Models\AccountReportingMaster;
use App\Models\RcpaCompetitor;
use App\Models\RcpaObservation;
use App\Models\SalesDcr;
use App\Models\Visit;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('RcpaRep');
    foreach (['Capture:Rcpa', 'View:Rcpa', 'Manage:RcpaCompetitor', 'View:AccountMaster', 'AccessAllRecords'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->rep->givePermissionTo(['Capture:Rcpa', 'View:AccountMaster', 'AccessAllRecords']);
    Sanctum::actingAs($this->rep);
    $this->chemist = reportingDoctor($this->rep, 'Tier A Chemist');
    $this->chemist->update(['rcpa_tier' => 'A']);
    $segment = AccountReportingMaster::query()->create(['kind' => 'therapy_area', 'code' => 'rcpa_cardio', 'name' => 'Cardiac', 'is_active' => true]);
    $competitors = [];
    for ($i = 1; $i <= 5; $i++) {
        $brand = RcpaCompetitor::query()->create(['therapy_area_id' => $segment->id, 'rank' => $i, 'name' => 'Approved test competitor '.$i]);
        $competitors[] = ['competitor_id' => $brand->id, 'present' => $i % 2 === 0];
    }
    $item = reportingSampleItem('RCPA-OWN', 'Own RCPA Brand');
    $this->observation = ['event_id' => (string) Str::uuid(), 'captured_at' => now()->utc()->toIso8601String(), 'observed_on' => today()->toDateString(),
        'chemist_id' => $this->chemist->id, 'therapy_area_id' => $segment->id, 'item_master_id' => $item->id,
        'stock_status' => 'low', 'doctor_rx_share' => 35.5, 'offtake_quantity' => 120, 'offtake_days' => 7,
        'competitors' => $competitors, 'remarks' => 'Field estimate only'];
});

it('retains immutable estimated observations once and prevents verified-label spoofing or locked-DCR edits', function () {
    $data = $this->observation;
    $send = fn () => $this->postJson('/api/v1/rcpa-observations', $data, ['Idempotency-Key' => $data['event_id']]);
    $first = $send()->assertCreated()->assertJsonPath('data.data_flag', 'ESTIMATED_FIELD');
    $send()->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect(RcpaObservation::query()->count())->toBe(1)
        ->and(fn () => RcpaObservation::query()->sole()->update(['doctor_rx_share' => 99]))->toThrow(LogicException::class);
    $spoof = [...$data, 'event_id' => (string) Str::uuid(), 'data_flag' => 'VERIFIED_INVOICE'];
    $this->postJson('/api/v1/rcpa-observations', $spoof, ['Idempotency-Key' => $spoof['event_id']])->assertUnprocessable();
    $dcrId = $first->json('data.sales_dcr_id');
    $this->postJson('/api/v1/dcrs/'.$dcrId.'/submit')->assertSuccessful();
    $new = [...$data, 'event_id' => (string) Str::uuid()];
    $this->postJson('/api/v1/rcpa-observations', $new, ['Idempotency-Key' => $new['event_id']])->assertUnprocessable();
    expect(RcpaObservation::query()->count())->toBe(1);
});

it('applies weekly and fortnightly due windows and rejects incomplete competitor coverage', function () {
    $a = $this->observation;
    $this->postJson('/api/v1/rcpa-observations', $a, ['Idempotency-Key' => $a['event_id']])->assertCreated();
    $bChemist = reportingDoctor($this->rep, 'Tier B Chemist');
    $bChemist->update(['rcpa_tier' => 'B']);
    $b = [...$a, 'event_id' => (string) Str::uuid(), 'chemist_id' => $bChemist->id];
    $this->postJson('/api/v1/rcpa-observations', $b, ['Idempotency-Key' => $b['event_id']])->assertCreated();
    $bad = [...$a, 'event_id' => (string) Str::uuid(), 'competitors' => array_slice($a['competitors'], 0, 4)];
    $this->postJson('/api/v1/rcpa-observations', $bad, ['Idempotency-Key' => $bad['event_id']])->assertUnprocessable();
    $this->travel(7)->days();
    $chemists = collect($this->getJson('/api/v1/rcpa/options')->assertSuccessful()->json('data.chemists'))->keyBy('id');
    expect($chemists[$this->chemist->id]['is_due'])->toBeTrue()->and($chemists[$bChemist->id]['is_due'])->toBeFalse();
    $this->travel(7)->days();
    $chemists = collect($this->getJson('/api/v1/rcpa/options')->assertSuccessful()->json('data.chemists'))->keyBy('id');
    expect($chemists[$bChemist->id]['is_due'])->toBeTrue();
});

it('renders scoped RCPA estimates, due monitoring and central competitor maintenance', function () {
    $this->rep->givePermissionTo(['View:Rcpa', 'Manage:RcpaCompetitor']);
    $this->rep->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageRcpaObservations::class)->assertSuccessful();
    $this->get('/sfa/rcpa/export')->assertSuccessful()->assertDownload('rcpa-estimates.csv');
    Livewire::test(ManageRcpaCompetitors::class)->assertSuccessful();
});

it('keeps a visited chemist DCR editable until its mandatory RCPA has synchronized', function () {
    $dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $territory = reportingTerritory('RCPA visit territory');
    $patch = reportingPatch($territory, 'RCPA visit patch');
    $visit = Visit::query()->create(['document_number' => 'RCPA-VISIT', 'employee_id' => $this->rep->id,
        'visit_date' => today(), 'visit_status' => 'draft', 'sales_dcr_id' => $dcr->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id]);
    $visit->visitables()->create(['visitable_type' => $this->chemist::class, 'visitable_id' => $this->chemist->id]);
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('rcpa');
    expect($dcr->fresh()->approval_status)->toBe('draft');
    $data = $this->observation;
    $this->postJson('/api/v1/rcpa-observations', $data, ['Idempotency-Key' => $data['event_id']])->assertCreated();
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertSuccessful()->assertJsonPath('data.approval_status', 'approved');
});

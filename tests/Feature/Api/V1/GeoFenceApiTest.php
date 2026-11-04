<?php

use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitGeoAssessment;
use App\Models\VisitPreference;
use App\Services\Field\FieldVisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('GeoRep');
    $this->asm = reportingUser('GeoASM');
    $company = reportingDoctor($this->rep, 'Geo Clinic');
    $territory = reportingTerritory('Geo Territory');
    $patch = reportingPatch($territory, 'Geo Patch');
    $company->update(['geo_latitude' => 19, 'geo_longitude' => 72, 'geo_radius_meters' => 100]);
    $this->dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->visit = Visit::query()->create(['document_number' => 'GEO-TEST', 'employee_id' => $this->rep->id,
        'visit_date' => today(), 'visit_status' => 'draft', 'sales_dcr_id' => $this->dcr->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id]);
    $this->visit->visitables()->create(['visitable_type' => $company::class, 'visitable_id' => $company->id]);
    VisitPreference::current()->update(['enforce_geo_fencing' => true, 'geo_asm_user_id' => $this->asm->id,
        'enable_check_in' => true, 'enable_check_out' => true, 'require_gps' => false,
        'require_check_in_image' => false, 'require_check_out_image' => false,
        'require_general_visit_image' => false, 'enforce_minimum_duration' => false, 'field_rules' => []]);
    Sanctum::actingAs($this->rep);
});

it('flags accuracy separately and requires the assigned ASM to approve an outside call before DCR lock', function () {
    $this->postJson('/api/v1/visits/'.$this->visit->id.'/check-in', ['latitude' => 19.01, 'longitude' => 72, 'accuracy_meters' => 75])->assertSuccessful();
    $row = VisitGeoAssessment::query()->sole();
    expect($row->result)->toBe('outside')->and($row->low_accuracy)->toBeTrue()
        ->and((int) $row->assigned_to)->toBe($this->asm->id);
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('geo_fence');
    expect($this->dcr->fresh()->approval_status)->toBe('draft');
    $this->patchJson('/api/v1/geo-exceptions/'.$row->id, ['decision' => 'approved', 'note' => 'Own approval'])->assertForbidden();
    Sanctum::actingAs($this->asm);
    $this->getJson('/api/v1/geo-exceptions')->assertSuccessful()->assertJsonPath('data.0.id', $row->id);
    $this->patchJson('/api/v1/geo-exceptions/'.$row->id, ['decision' => 'approved', 'note' => 'Clinic temporarily relocated'])->assertSuccessful();
    $this->patchJson('/api/v1/geo-exceptions/'.$row->id, ['decision' => 'rejected', 'note' => 'Repeated review'])->assertConflict();
    Sanctum::actingAs($this->rep);
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit')->assertSuccessful()->assertJsonPath('data.approval_status', 'approved')
        ->assertJsonPath('data.low_accuracy_warning', true);
});

it('revalidates the current master, accepts configured rural radius and prevents missing GPS bypass', function () {
    $this->postJson('/api/v1/visits/'.$this->visit->id.'/check-in', [])->assertUnprocessable();
    expect($this->visit->fresh()->start_time)->toBeNull();
    $company = $this->visit->primaryCompany();
    $company->update(['geo_radius_meters' => 250]);
    app(FieldVisitService::class)->checkIn($this->rep, $this->visit, ['latitude' => 19.0015, 'longitude' => 72, 'accuracy_meters' => 10]);
    $row = VisitGeoAssessment::query()->sole();
    expect($row->result)->toBe('inside')->and($row->decision)->toBe('not_required')->and($row->low_accuracy)->toBeFalse();
    // A master edit after download must affect server-side checkout assessment.
    $company->update(['geo_latitude' => 20]);
    app(FieldVisitService::class)->checkOut($this->rep, $this->visit->fresh(), ['latitude' => 19.0015, 'longitude' => 72, 'accuracy_meters' => 10]);
    expect(VisitGeoAssessment::query()->where('stage', 'check_out')->sole()->result)->toBe('outside');
    $this->postJson('/api/v1/dcrs/'.$this->dcr->id.'/submit')->assertUnprocessable();
});

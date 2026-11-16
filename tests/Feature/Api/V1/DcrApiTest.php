<?php

use App\Filament\Resources\SalesDcrs\Pages\CreateSalesDcr;
use App\Filament\Resources\SalesDcrs\Pages\ListSalesDcrs;
use App\Models\SalesDcr;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('returns one owned DCR for the requested day', function () {
    $user = fieldReportingUser('DcrApiRep');
    Sanctum::actingAs($user);
    $first = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.user_id', $user->id);
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.id', $first->json('data.id'));
    expect(SalesDcr::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not expose another representatives DCR', function () {
    $owner = fieldReportingUser('DcrOwner');
    Sanctum::actingAs($owner);
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    Sanctum::actingAs(fieldReportingUser('DcrOther'));
    $this->getJson('/api/v1/dcrs/'.$id)->assertNotFound();
});

it('hides another users DCR in the Admin list and the field API', function () {
    $owner = fieldReportingUser('DcrListOwner');
    $viewer = fieldReportingUser('DcrListViewer');
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:SalesDcr', 'web'));
    $dcr = SalesDcr::query()->create([
        'user_id' => $owner->id,
        'dcr_date' => today()->toDateString(),
        'approval_status' => 'draft',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    expect($viewer->can('AccessAllRecords'))->toBeFalse()
        ->and($viewer->hasAnyRole(['super_admin', 'administration_admin']))->toBeFalse();

    $this->actingAs($viewer, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListSalesDcrs::class)->assertCanNotSeeTableRecords([$dcr]);

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dcrs/'.$dcr->id)->assertNotFound();
});

it('rejects submission of an empty DCR', function () {
    Sanctum::actingAs(fieldReportingUser('EmptyDcr'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('dcr');
});

it('denies reporting to accounts without permissions and does not create rows for read-only accounts', function () {
    $viewer = reportingUser('Roleless DCR viewer');
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dcrs/today')->assertForbidden();

    grantPagePermissions($viewer, 'ViewAny:SalesDcr', 'View:SalesDcr');
    Sanctum::actingAs($viewer->fresh());
    $this->getJson('/api/v1/dcrs/today')->assertForbidden();
    expect(SalesDcr::query()->where('user_id', $viewer->id)->exists())->toBeFalse();

    $report = SalesDcr::forceCreate(['user_id' => $viewer->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.capabilities.submit', false);
    $this->postJson('/api/v1/dcrs/'.$report->id.'/submit')->assertForbidden();
});

it('rejects assigning an Admin report to a user outside the field team', function () {
    $actor = fieldReportingUser('Report Form Actor');
    $unrelated = reportingUser('Unrelated Report Owner');
    $this->actingAs($actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateSalesDcr::class)
        ->assertSuccessful()
        ->fillForm(['dcr_date' => today()->toDateString(), 'user_id' => $unrelated->id])
        ->call('create')
        ->assertHasFormErrors(['user_id']);

    expect(SalesDcr::query()->where('user_id', $unrelated->id)->exists())->toBeFalse();
});

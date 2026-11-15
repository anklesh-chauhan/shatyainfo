<?php

use App\Filament\Resources\SalesDcrs\Pages\ListSalesDcrs;
use App\Models\SalesDcr;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('returns one owned DCR for the requested day', function () {
    $user = reportingUser('DcrApiRep');
    Sanctum::actingAs($user);
    $first = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.user_id', $user->id);
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.id', $first->json('data.id'));
    expect(SalesDcr::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not expose another representatives DCR', function () {
    $owner = reportingUser('DcrOwner');
    Sanctum::actingAs($owner);
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    Sanctum::actingAs(reportingUser('DcrOther'));
    $this->getJson('/api/v1/dcrs/'.$id)->assertNotFound();
});

it('shows another users DCR in the Admin list and hides it from the API', function () {
    $owner = reportingUser('DcrListOwner');
    $viewer = reportingUser('DcrListViewer');
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
    Livewire::test(ListSalesDcrs::class)->assertCanSeeTableRecords([$dcr]);

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dcrs/'.$dcr->id)->assertNotFound();
});

it('rejects submission of an empty DCR', function () {
    Sanctum::actingAs(reportingUser('EmptyDcr'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('dcr');
});

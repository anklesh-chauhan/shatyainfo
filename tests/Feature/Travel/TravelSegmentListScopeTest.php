<?php

use App\Filament\Resources\TravelSegments\Pages\ListTravelSegments;
use App\Models\SalesDcr;
use App\Models\TravelSegment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('shows another users travel segment in the Admin list', function () {
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
        ->assertCanSeeTableRecords([$segment]);
});
